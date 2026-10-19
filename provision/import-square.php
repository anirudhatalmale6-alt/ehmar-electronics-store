<?php
/**
 * Import the client's Square catalogue (square/import.json) into WooCommerce.
 *
 * Idempotent: products are matched by SKU "SQ-<square id>", images by their source URL.
 * Run with:  wp eval-file provision/import-square.php   (EHMAR_IMPORT = path to import.json)
 * Optional:  EHMAR_IMPORT_ONLY=1,7,9  limits the run to those catalogue numbers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$file = getenv( 'EHMAR_IMPORT' ) ?: dirname( __DIR__ ) . '/square/import.json';
$data = json_decode( (string) file_get_contents( $file ), true );
if ( ! is_array( $data ) ) {
	exit( "import.json missing or invalid: $file\n" );
}
$only = array_filter( array_map( 'intval', explode( ',', (string) getenv( 'EHMAR_IMPORT_ONLY' ) ) ) );

function sq_log( $msg ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::log( $msg );
	} else {
		echo esc_html( $msg ) . "\n";
		@ob_flush();
		flush();
	}
}

/** Download a remote image once; later runs reuse the attachment. */
function sq_image( $url, $title ) {
	if ( ! $url ) {
		return 0;
	}
	$found = get_posts( array(
		'post_type'   => 'attachment',
		'post_status' => 'inherit',
		'meta_key'    => '_sq_source',
		'meta_value'  => $url,
		'numberposts' => 1,
		'fields'      => 'ids',
	) );
	if ( $found ) {
		return (int) $found[0];
	}
	$tmp = download_url( $url, 60 );
	if ( is_wp_error( $tmp ) ) {
		sq_log( '  image failed: ' . $tmp->get_error_message() );
		return 0;
	}
	// Square's metadata can under-report size (a "2560px" photo was 5100px). Decoding that for thumbnails
	// exceeds shared-hosting memory and the request dies with a 503, so take Square's 1280px rendition instead.
	$dims = @getimagesize( $tmp );
	if ( $dims && max( $dims[0], $dims[1] ) > 3000 && false !== strpos( (string) wp_parse_url( $url, PHP_URL_HOST ), 'editmysite.com' ) ) {
		$smaller = download_url( add_query_arg( 'width', 1280, $url ), 60 );
		if ( ! is_wp_error( $smaller ) ) {
			@unlink( $tmp );
			$tmp = $smaller;
		}
	}
	$name = sanitize_file_name( basename( wp_parse_url( $url, PHP_URL_PATH ) ) );
	$id   = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0, $title );
	if ( is_wp_error( $id ) ) {
		@unlink( $tmp );
		sq_log( '  image failed: ' . $id->get_error_message() );
		return 0;
	}
	update_post_meta( $id, '_sq_source', $url );
	return (int) $id;
}

/** Square stock flags -> WooCommerce stock fields. */
function sq_stock( $product, $v ) {
	if ( $v['track'] ) {
		$product->set_manage_stock( true );
		$product->set_stock_quantity( max( 0, (int) $v['stock'] ) );
	} else {
		$product->set_manage_stock( false );
		$product->set_stock_status( $v['sold_out'] ? 'outofstock' : 'instock' );
	}
}

function sq_prices( $product, $v ) {
	$regular = (float) $v['regular'];
	$sale    = $v['sale'] ? (float) $v['sale'] : 0;
	$product->set_regular_price( wc_format_decimal( $regular, 2 ) );
	$product->set_sale_price( $sale && $sale < $regular ? wc_format_decimal( $sale, 2 ) : '' );
}

$categories = array(
	'security-cameras' => 'Security Cameras',
	'video-doorbells'  => 'Video Doorbells',
	'phones-tablets'   => 'Phones & Tablets',
	'solar-power'      => 'Solar Power',
	'electronics'      => 'Electronics',
	'kitchen'          => 'Kitchen',
	'car-accessories'  => 'Car Accessories',
);
$cat_ids = array();
foreach ( $categories as $slug => $name ) {
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	$cat_ids[ $slug ] = $term ? (int) $term->term_id : (int) wp_insert_term( $name, 'product_cat', array( 'slug' => $slug ) )['term_id'];
	if ( $term ) {
		wp_update_term( $term->term_id, 'product_cat', array( 'name' => $name ) );
	}
}

$created = 0;
$updated = 0;
foreach ( $data as $i => $p ) {
	if ( $only && ! in_array( (int) $p['n'], $only, true ) ) {
		continue;
	}
	$sku = 'SQ-' . $p['square_id'];

	// Only offer options whose value actually differs between variations.
	$varying = array();
	foreach ( $p['options'] as $opt ) {
		$values = array_unique( array_filter( array_map( fn( $v ) => $v['choices'][ $opt ] ?? '', $p['variations'] ) ) );
		if ( count( $values ) > 1 ) {
			$varying[ $opt ] = array_values( $values );
		}
	}
	$is_variable = $varying && count( $p['variations'] ) > 1;

	$existing_id = wc_get_product_id_by_sku( $sku );
	$existing    = $existing_id ? wc_get_product( $existing_id ) : null;
	if ( $existing && $is_variable !== $existing->is_type( 'variable' ) ) {
		$existing->delete( true );
		$existing = null;
	}
	$product = $existing ?: ( $is_variable ? new WC_Product_Variable() : new WC_Product_Simple() );

	$images = array_values( array_filter( array_map( fn( $u ) => sq_image( $u, $p['name'] ), $p['images'] ) ) );

	$product->set_name( $p['name'] );
	$product->set_sku( $sku );
	$product->set_status( 'publish' );
	$product->set_description( wp_kses_post( $p['description'] ) );
	$product->set_short_description( '' );
	$product->set_category_ids( array( $cat_ids[ $p['category'] ] ) );
	if ( $images ) {
		$product->set_image_id( $images[0] );
		$product->set_gallery_image_ids( array_slice( $images, 1 ) );
	}

	if ( ! $is_variable ) {
		$v = $p['variations'][0];
		sq_prices( $product, $v );
		sq_stock( $product, $v );
		$product_id = $product->save();
	} else {
		$attrs = array();
		foreach ( $varying as $opt => $values ) {
			$a = new WC_Product_Attribute();
			$a->set_name( $opt );
			$a->set_options( $values );
			$a->set_visible( true );
			$a->set_variation( true );
			$attrs[] = $a;
		}
		$product->set_attributes( $attrs );
		$product_id = $product->save();

		$by_sku = array();
		foreach ( $product->get_children() as $child_id ) {
			$child                       = wc_get_product( $child_id );
			$by_sku[ $child->get_sku() ] = $child;
		}
		$defaults = array();
		foreach ( $p['variations'] as $v ) {
			$vsku      = $sku . '-' . $v['sku'];
			$variation = $by_sku[ $vsku ] ?? new WC_Product_Variation();
			unset( $by_sku[ $vsku ] );
			$attr_values = array();
			foreach ( $varying as $opt => $values ) {
				$attr_values[ sanitize_title( $opt ) ] = $v['choices'][ $opt ] ?? '';
			}
			$variation->set_parent_id( $product_id );
			$variation->set_sku( $vsku );
			$variation->set_attributes( $attr_values );
			sq_prices( $variation, $v );
			sq_stock( $variation, $v );
			if ( ! empty( $v['image'] ) ) {
				$vimg = sq_image( $v['image'], $p['name'] );
				if ( $vimg ) {
					$variation->set_image_id( $vimg );
				}
			}
			$variation->save();
			if ( ! $defaults && 'instock' === $variation->get_stock_status() ) {
				$defaults = $attr_values;
			}
		}
		foreach ( $by_sku as $stale ) {
			$stale->delete( true );
		}
		$product->set_default_attributes( $defaults );
		$product->save();
		WC_Product_Variable::sync( $product_id );
	}

	// "New arrivals" order follows the Square catalogue order.
	$stamp = time() - ( (int) $p['n'] ) * 60;
	wp_update_post( array( 'ID' => $product_id, 'post_status' => 'publish', 'post_date' => wp_date( 'Y-m-d H:i:s', $stamp ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $stamp ) ) );

	$existing ? $updated++ : $created++;
	sq_log( sprintf( '#%d %s %s (%s)', $p['n'], $existing ? 'updated' : 'created', mb_substr( $p['name'], 0, 60 ), $is_variable ? count( $p['variations'] ) . ' variations' : 'simple' ) );
}

sq_log( "Done: $created created, $updated updated." );
