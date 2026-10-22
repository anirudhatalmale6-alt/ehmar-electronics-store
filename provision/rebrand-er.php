<?php
/**
 * Ehmar brand refresh: the gold ER logo in the header, the ER site icon, and a hand-picked
 * mix of products on the home page (via the "featured" star, so the owner can change it).
 *
 * Idempotent. Run with:  wp eval-file provision/rebrand-er.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

function rb_log( $msg ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::log( $msg );
	} else {
		echo esc_html( $msg ) . "\n";
	}
}

/** Add a theme image to the media library once (matched by file name). */
function rb_image( $path, $title ) {
	$name     = basename( $path );
	$existing = get_posts( array(
		'post_type'   => 'attachment',
		'post_status' => 'inherit',
		'meta_key'    => '_ehmar_source',
		'meta_value'  => $name,
		'numberposts' => 1,
		'fields'      => 'ids',
	) );
	if ( $existing ) {
		return (int) $existing[0];
	}
	$tmp = wp_tempnam( $name );
	copy( $path, $tmp );
	$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0, $title );
	if ( is_wp_error( $id ) ) {
		rb_log( 'Image failed: ' . $name . ' — ' . $id->get_error_message() );
		return 0;
	}
	update_post_meta( $id, '_ehmar_source', $name );
	return (int) $id;
}

$img  = get_theme_file_path( 'assets/img' );
$logo = rb_image( "$img/ehmar-logo-er.png", 'Ehmar logo' );
$icon = rb_image( "$img/ehmar-icon-er.png", 'Ehmar icon' );
if ( $logo ) {
	update_option( 'site_logo', $logo );
	set_theme_mod( 'custom_logo', $logo );
}
if ( $icon ) {
	update_option( 'site_icon', $icon );
}
rb_log( "Logo $logo, icon $icon." );

// Home page "Featured products": a mix across the store, not only cameras.
$featured = array(
	'SQ-K5OTGGGTXIB3WKDGW3TIJBUA', // Electric food warmer plate
	'SQ-D6MLNPKVDDITMLBTLNDWJ4U6', // Portable solar power station
	'SQ-IRUZ253CDG4BKUG5U6FPZ2PN', // Wireless video doorbell
	'SQ-5TODAPYLYESRN67MLYQPTCKQ', // Portable electric juicer
	'SQ-MPWNPVO2DB4OMSJEK4ASO6TX', // Android smartphone
	'SQ-42GEFLRLLEF72MP5PT5URTLQ', // Kids tablet
	'SQ-AB2JGSMJZDUP7WIEDT2QQD72', // Solar outdoor camera
	'SQ-V5SX56XVOOJ3ZWCOOIXDQK6E', // Smart watch
);
$done = 0;
foreach ( wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'private', 'draft' ) ) ) as $product ) {
	$want = in_array( $product->get_sku(), $featured, true );
	if ( $product->get_featured() !== $want ) {
		$product->set_featured( $want );
		$product->save();
	}
	$done += $want ? 1 : 0;
}
rb_log( "Featured products: $done of " . count( $featured ) . '.' );
