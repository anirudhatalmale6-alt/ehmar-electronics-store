<?php
/**
 * Ehmar Electronics store provisioning.
 *
 * Idempotent: safe to run again; it updates what exists and creates what is missing.
 * Run with:  wp eval-file provision/setup.php
 * Assets are read from EHMAR_ASSETS (defaults to ../demo-ready next to this file).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$assets = getenv( 'EHMAR_ASSETS' ) ?: dirname( __DIR__ ) . '/demo-ready';

function ehmar_log( $msg ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::log( $msg );
	} else {
		echo esc_html( $msg ) . "\n";
	}
}

/** Import a local image once; reuse it on later runs (matched by file name). */
function ehmar_image( $path, $title = '', $caption = '' ) {
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
		ehmar_log( 'Image failed: ' . $name . ' — ' . $id->get_error_message() );
		return 0;
	}
	update_post_meta( $id, '_ehmar_source', $name );
	if ( $caption ) {
		wp_update_post( array( 'ID' => $id, 'post_excerpt' => $caption ) );
	}
	return (int) $id;
}

function ehmar_term( $name, $taxonomy, $slug, $description = '' ) {
	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( $term ) {
		wp_update_term( $term->term_id, $taxonomy, array( 'name' => $name, 'description' => $description ) );
		return (int) $term->term_id;
	}
	$created = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug, 'description' => $description ) );
	return is_wp_error( $created ) ? 0 : (int) $created['term_id'];
}

function ehmar_page( $slug, $title, $content ) {
	$page = get_page_by_path( $slug );
	$data = array(
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_content' => $content,
		'post_status'  => 'publish',
		'post_type'    => 'page',
	);
	if ( $page ) {
		$data['ID'] = $page->ID;
		return wp_update_post( $data );
	}
	return wp_insert_post( $data );
}

/* ---------------------------------------------------------------- Site & WooCommerce settings */

// Market defaults: Nigeria / Naira. Switch EHMAR_CURRENCY + EHMAR_COUNTRY for a US-dollar store.
$currency = getenv( 'EHMAR_CURRENCY' ) ?: 'NGN';
$country  = getenv( 'EHMAR_COUNTRY' ) ?: 'NG:LA';
foreach ( array(
	'timezone_string'                    => 'Africa/Lagos',
	'permalink_structure'                => '/%postname%/',
	'woocommerce_default_country'        => $country,
	'woocommerce_currency'               => $currency,
	'woocommerce_currency_pos'           => 'left',
	'woocommerce_price_thousand_sep'     => ',',
	'woocommerce_price_decimal_sep'      => '.',
	'woocommerce_price_num_decimals'     => 'NGN' === $currency ? 0 : 2,
	'woocommerce_weight_unit'            => 'kg',
	'woocommerce_dimension_unit'         => 'cm',
	'woocommerce_calc_taxes'             => 'no',
	'woocommerce_enable_guest_checkout'  => 'yes',
	'woocommerce_coming_soon'            => 'no',
	'woocommerce_allow_tracking'         => 'no',
	'woocommerce_task_list_hidden'       => 'yes',
	'woocommerce_onboarding_profile'     => array( 'skipped' => true ),
) as $key => $value ) {
	update_option( $key, $value );
}

// Store email: dashboard login, order notifications and the sender of customer emails.
$store_email = getenv( 'EHMAR_EMAIL' ) ?: 'info@ehmar.com';
update_option( 'admin_email', $store_email );
update_option( 'woocommerce_email_from_address', $store_email );
update_option( 'woocommerce_email_from_name', 'Ehmar' );
foreach ( array( 'woocommerce_new_order_settings', 'woocommerce_cancelled_order_settings', 'woocommerce_failed_order_settings' ) as $email_option ) {
	update_option( $email_option, array_merge( (array) get_option( $email_option, array() ), array( 'enabled' => 'yes', 'recipient' => $store_email ) ) );
}

/* ---------------------------------------------------------------- Theme & branding */

switch_theme( 'ehmar' );
update_option( 'blogname', 'Ehmar' );
update_option( 'blogdescription', 'Phones, Security Cameras, Memory Cards & Solar Power' );

$logo = ehmar_image( "$assets/ehmar-logo.png", 'Ehmar Electronics logo' );
$icon = ehmar_image( "$assets/ehmar-icon.png", 'Ehmar icon' );
if ( $logo ) {
	set_theme_mod( 'custom_logo', $logo );
	update_option( 'site_logo', $logo );
}
if ( $icon ) {
	update_option( 'site_icon', $icon );
}
ehmar_log( 'Theme and logo set.' );

/* ---------------------------------------------------------------- Categories & brands */

$categories = array(
	'phones'           => array( 'Phones', 'Smartphones and feature phones from trusted brands.' ),
	'security-cameras' => array( 'Security Cameras', 'Wi-Fi, CCTV and doorbell cameras to keep your home and business safe.' ),
	'memory-cards'     => array( 'Memory Cards', 'microSD and SD cards and flash drives for phones, cameras and laptops.' ),
	'solar-generators' => array( 'Solar Generators', 'Portable power stations and solar panels — power that keeps going when the grid does not.' ),
	'power-banks'      => array( 'Power Banks', 'Portable chargers for phones, tablets and more.' ),
	'chargers-cables'  => array( 'Chargers & Cables', 'Fast chargers, USB-C and Lightning cables.' ),
	'accessories'      => array( 'Accessories', 'Earbuds, phone cases, screen protectors and more.' ),
);
$cat_ids = array();
foreach ( $categories as $slug => $c ) {
	$cat_ids[ $slug ] = ehmar_term( $c[0], 'product_cat', $slug, $c[1] );
}
// Rename WooCommerce's default "Uncategorized" so it never shows that word to customers.
$default_cat = (int) get_option( 'default_product_cat' );
if ( $default_cat ) {
	wp_update_term( $default_cat, 'product_cat', array( 'name' => 'Other', 'slug' => 'other' ) );
}

$brand_ids = array();
foreach ( array( 'Samsung', 'Google', 'SanDisk', 'Anker', 'Baseus', 'Ehmar' ) as $b ) {
	$brand_ids[ $b ] = ehmar_term( $b, 'product_brand', sanitize_title( $b ) );
}
ehmar_log( 'Categories and brands ready.' );

/* ---------------------------------------------------------------- Demo products */

$demo_note = '<p><em>Demo product — will be replaced with Ehmar\'s real catalogue.</em></p>';

$products = array(
	array(
		'sku' => 'DEMO-PH-A13', 'name' => 'Samsung Galaxy A13', 'cat' => 'phones', 'brand' => 'Samsung',
		'img' => array( 'samsung-a13.jpg' ), 'weight' => 0.4,
		'short' => '6.6" display, 50MP quad camera, 5000mAh battery, dual SIM.',
		'desc' => "<ul><li>6.6\" FHD+ display</li><li>50MP main camera with ultra-wide and macro</li><li>5000mAh battery with fast charging</li><li>Dual SIM, expandable storage with microSD</li></ul>",
		'variations' => array( 'Storage' => array( '64GB' => array( 145000, 160000 ), '128GB' => array( 168000, 185000 ) ) ),
	),
	array(
		'sku' => 'DEMO-PH-PX6P', 'name' => 'Google Pixel 6 Pro (UK Used)', 'cat' => 'phones', 'brand' => 'Google',
		'img' => array( 'pixel-6-pro.jpg', 'pixel-6-pro-back.jpg' ), 'weight' => 0.45,
		'price' => 395000, 'regular' => 430000, 'stock' => 3,
		'short' => 'Clean UK-used Pixel 6 Pro with box and cable. 12GB RAM, 128GB.',
		'desc' => '<ul><li>6.7" LTPO OLED, 120Hz</li><li>50MP main, 48MP telephoto, 12MP ultra-wide</li><li>12GB RAM, 128GB storage</li><li>Comes with original box and USB-C cable</li></ul>',
	),
	array(
		'sku' => 'DEMO-CAM-DOME4', 'name' => '4MP Wi-Fi Dome Security Camera', 'cat' => 'security-cameras', 'brand' => 'Ehmar',
		'img' => array( 'dome-camera.jpg' ), 'weight' => 0.6, 'price' => 48500, 'stock' => 12,
		'short' => 'Ceiling-mount dome camera with night vision and phone alerts.',
		'desc' => '<ul><li>4MP sharp video, day and night</li><li>Infrared night vision</li><li>Motion alerts to your phone</li><li>Records to microSD card or cloud</li></ul>',
	),
	array(
		'sku' => 'DEMO-SD-ULTRA', 'name' => 'SanDisk Ultra microSD Card with Adapter', 'cat' => 'memory-cards', 'brand' => 'SanDisk',
		'img' => array( 'sandisk-ultra.jpg' ), 'weight' => 0.02,
		'short' => 'Fast, reliable storage for phones, tablets and security cameras.',
		'desc' => '<ul><li>Class 10, A1 rated for apps</li><li>Ideal for phones and security cameras</li><li>SD adapter included</li></ul>',
		'variations' => array( 'Capacity' => array( '32GB' => array( 6500 ), '64GB' => array( 9500 ), '128GB' => array( 16500 ) ) ),
	),
	array(
		'sku' => 'DEMO-SOL-PS', 'name' => 'Anker SOLIX Portable Power Station', 'cat' => 'solar-generators', 'brand' => 'Anker',
		'img' => array( 'power-station.jpg' ), 'weight' => 4.1, 'price' => 365000, 'regular' => 399000, 'stock' => 5,
		'short' => 'Quiet, fuel-free backup power for phones, laptop, TV, fan and lights.',
		'desc' => '<ul><li>Charges from solar panel, wall socket or car</li><li>AC sockets, USB-C and USB-A outputs</li><li>Built-in light, carry strap</li><li>No fuel, no noise, no fumes</li></ul>',
		'credit' => 'Photo: Anker SOLIX C300X, Wikimedia Commons, CC BY-SA 4.0',
	),
	array(
		'sku' => 'DEMO-SOL-PNL', 'name' => '100W Foldable Solar Panel', 'cat' => 'solar-generators', 'brand' => 'Ehmar',
		'img' => array( 'solar-panel.jpg' ), 'weight' => 4.5, 'price' => 145000, 'stock' => 8,
		'short' => 'Fold-out solar panel to recharge your power station from the sun.',
		'desc' => '<ul><li>100W output in full sun</li><li>Folds flat with carry handle</li><li>Works with most portable power stations</li></ul>',
	),
	array(
		'sku' => 'DEMO-CH-20W', 'name' => 'Baseus 20W USB-C Fast Charger', 'cat' => 'chargers-cables', 'brand' => 'Baseus',
		'img' => array( 'charger-20w.jpg' ), 'weight' => 0.1, 'price' => 9500, 'stock' => 40,
		'short' => 'Compact fast charger for iPhone and Android.',
		'desc' => '<ul><li>20W Power Delivery fast charging</li><li>USB-C and USB-A ports</li><li>Compact, travel-friendly</li></ul>',
	),
	array(
		'sku' => 'DEMO-CB-USBC', 'name' => 'USB-C Charging Cable (1m)', 'cat' => 'chargers-cables', 'brand' => 'Ehmar',
		'img' => array( 'usb-c-cable.jpg' ), 'weight' => 0.05, 'price' => 3000, 'stock' => 60,
		'short' => 'Durable USB-A to USB-C cable for charging and data.',
		'desc' => '<ul><li>1 metre length</li><li>Fast charge and data transfer</li></ul>',
	),
	array(
		'sku' => 'DEMO-AC-BUDS', 'name' => 'True Wireless Earbuds', 'cat' => 'accessories', 'brand' => 'Ehmar',
		'img' => array( 'earbuds.jpg' ), 'weight' => 0.1, 'price' => 18500, 'regular' => 22000, 'stock' => 20,
		'short' => 'Bluetooth earbuds with charging case and touch controls.',
		'desc' => '<ul><li>Bluetooth 5.x</li><li>Charging case for extra hours</li><li>Built-in mic for calls</li></ul>',
	),
	array(
		'sku' => 'DEMO-AC-CASE', 'name' => 'Clear Shockproof Phone Case', 'cat' => 'accessories', 'brand' => 'Ehmar',
		'img' => array( 'clear-case.jpg' ), 'weight' => 0.05, 'price' => 3500, 'stock' => 50,
		'short' => 'Slim clear case that shows off your phone and protects it.',
		'desc' => '<ul><li>Flexible TPU with reinforced corners</li><li>Raised edges protect screen and camera</li></ul>',
	),
);

foreach ( $products as $i => $p ) {
	$id      = wc_get_product_id_by_sku( $p['sku'] );
	$is_var  = ! empty( $p['variations'] );
	$product = $id ? wc_get_product( $id ) : ( $is_var ? new WC_Product_Variable() : new WC_Product_Simple() );

	$caption = $p['credit'] ?? '';
	$images  = array_values( array_filter( array_map( fn( $f ) => ehmar_image( "$assets/$f", $p['name'], $caption ), $p['img'] ) ) );

	$product->set_name( $p['name'] );
	$product->set_sku( $p['sku'] );
	$product->set_status( 'publish' );
	$product->set_short_description( $p['short'] );
	$product->set_description( $p['desc'] . $demo_note . ( $caption ? '<p><small>' . esc_html( $caption ) . '</small></p>' : '' ) );
	$product->set_category_ids( array( $cat_ids[ $p['cat'] ] ) );
	$product->set_weight( (string) $p['weight'] );
	if ( $images ) {
		$product->set_image_id( $images[0] );
		$product->set_gallery_image_ids( array_slice( $images, 1 ) );
	}

	if ( $is_var ) {
		$label   = array_key_first( $p['variations'] );
		$options = $p['variations'][ $label ];
		$attr    = new WC_Product_Attribute();
		$attr->set_name( $label );
		$attr->set_options( array_keys( $options ) );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$product->set_attributes( array( $attr ) );
		$product->set_default_attributes( array( sanitize_title( $label ) => array_key_first( $options ) ) );
		$product_id = $product->save();

		$existing = array();
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			$existing[ $child->get_attribute( $label ) ] = $child;
		}
		foreach ( $options as $value => $prices ) {
			$variation = $existing[ $value ] ?? new WC_Product_Variation();
			$variation->set_parent_id( $product_id );
			$variation->set_attributes( array( sanitize_title( $label ) => $value ) );
			$variation->set_sku( $p['sku'] . '-' . sanitize_title( $value ) );
			if ( isset( $prices[1] ) ) {
				$variation->set_regular_price( (string) $prices[1] );
				$variation->set_sale_price( (string) $prices[0] );
			} else {
				$variation->set_regular_price( (string) $prices[0] );
				$variation->set_sale_price( '' );
			}
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( 10 );
			$variation->set_weight( (string) $p['weight'] );
			$variation->save();
		}
		WC_Product_Variable::sync( $product_id );
	} else {
		if ( isset( $p['regular'] ) ) {
			$product->set_regular_price( (string) $p['regular'] );
			$product->set_sale_price( (string) $p['price'] );
		} else {
			$product->set_regular_price( (string) $p['price'] );
			$product->set_sale_price( '' );
		}
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $p['stock'] );
		$product_id = $product->save();
	}

	wp_set_object_terms( $product_id, array( $brand_ids[ $p['brand'] ] ), 'product_brand' );
	// Listing order on "New arrivals" follows the order of this array.
	// time() is real UTC; wp_date() applies the site timezone exactly once. Always in the past, so it stays published.
	$stamp = time() - ( $i + 1 ) * 60;
	wp_update_post( array( 'ID' => $product_id, 'post_status' => 'publish', 'post_date' => wp_date( 'Y-m-d H:i:s', $stamp ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $stamp ) ) );
	ehmar_log( 'Product: ' . $p['name'] );
}

/* ---------------------------------------------------------------- Pages */

$contact_box = '<!-- wp:group {"className":"ehmar-page-card","layout":{"type":"constrained"}} --><div class="wp-block-group ehmar-page-card">';

ehmar_page( 'about', 'About Ehmar', <<<HTML
<!-- wp:paragraph {"fontSize":"large"} --><p class="has-large-font-size">Ehmar brings genuine, reliable electronics to homes and businesses — the phones you use every day, the cameras that keep you safe, the storage that holds your memories, and the solar power that keeps everything running.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2 class="wp-block-heading">What we sell</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li><strong>Phones</strong> — new and quality UK-used smartphones</li><li><strong>Security cameras</strong> — Wi-Fi, CCTV and doorbell cameras</li><li><strong>Memory cards</strong> — microSD, SD and flash drives</li><li><strong>Solar generators</strong> — portable power stations and solar panels</li><li><strong>Accessories</strong> — power banks, chargers, cables, earbuds and cases</li></ul><!-- /wp:list -->
<!-- wp:heading --><h2 class="wp-block-heading">Why shop with us</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Ehmar is part of the same family as <a href="https://ehmarfoods.com">Ehmar Foods</a>. We bring the same care to electronics: honest product descriptions, fair prices, secure payment and real people to talk to when you need help.</p><!-- /wp:paragraph -->
HTML );

ehmar_page( 'contact', 'Contact Us', <<<HTML
<!-- wp:paragraph --><p>Questions about a product, an order or a delivery? Reach us any of these ways and we'll get back to you quickly.</p><!-- /wp:paragraph -->
{$contact_box}
<!-- wp:list --><ul class="wp-block-list"><li><strong>WhatsApp / Phone:</strong> [to be added]</li><li><strong>Email:</strong> <a href="mailto:info@ehmar.com">info@ehmar.com</a></li><li><strong>Address:</strong> [to be added]</li><li><strong>Opening hours:</strong> [to be added]</li></ul><!-- /wp:list -->
</div><!-- /wp:group -->
HTML );

ehmar_page( 'shipping-delivery', 'Shipping & Delivery', <<<HTML
<!-- wp:paragraph --><p>We deliver across Nigeria. Your delivery fee is shown at checkout before you pay.</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Delivery times</h3><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li><strong>Lagos:</strong> [to be confirmed]</li><li><strong>Other states:</strong> [to be confirmed]</li></ul><!-- /wp:list -->
<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Tracking your order</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Once your order is on its way we'll send you an update. You can always check your order status in <a href="/my-account/">My Account</a>.</p><!-- /wp:paragraph -->
HTML );

ehmar_page( 'returns-warranty', 'Returns & Warranty', <<<HTML
<!-- wp:paragraph --><p>We want you to be happy with every purchase. If something isn't right, contact us and we'll sort it out.</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Returns</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>[Return window and conditions to be confirmed — for example: unused items in original packaging within 7 days of delivery.]</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Warranty</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>The warranty period for each product is shown on its product page. [Warranty process to be confirmed.]</p><!-- /wp:paragraph -->
HTML );

ehmar_page( 'faq', 'Frequently Asked Questions', <<<HTML
<!-- wp:html -->
<div class="ehmar-faq">
<details open><summary>Are your products genuine?</summary><p>Yes. We sell original products from trusted brands. Used phones are clearly labelled as "UK Used" with their condition described.</p></details>
<details><summary>How can I pay?</summary><p>You can pay securely online with your debit or credit card, by bank transfer, or with cash on delivery where available.</p></details>
<details><summary>How much is delivery?</summary><p>The delivery fee for your location is shown at checkout before you pay. See <a href="/shipping-delivery/">Shipping &amp; Delivery</a>.</p></details>
<details><summary>Can I return an item?</summary><p>Please see our <a href="/returns-warranty/">Returns &amp; Warranty</a> page, or contact us and we'll help.</p></details>
<details><summary>How do I track my order?</summary><p>Log in to <a href="/my-account/">My Account</a> to see your order status, or contact us with your order number.</p></details>
</div>
<!-- /wp:html -->
HTML );
ehmar_log( 'Pages ready.' );

/* ---------------------------------------------------------------- Front page, payments, shipping */

update_option( 'show_on_front', 'posts' ); // front-page.html template takes over the home page.
update_option( 'woocommerce_default_catalog_orderby', 'date' ); // Newest first, matching "New arrivals".
update_option( 'woocommerce_checkout_phone_field', 'required' ); // Couriers need a phone number in Nigeria.

// Bank transfer: account details are added once the client supplies them.
$bacs = get_option( 'woocommerce_bacs_settings', array() );
update_option( 'woocommerce_bacs_settings', array_merge( $bacs, array(
	'enabled'      => 'yes',
	'title'        => 'Bank transfer',
	'description'  => 'Transfer the total to our bank account. Use your order number as the reference. Your order ships once payment is confirmed.',
	'instructions' => 'Please use your order number as the transfer reference. Your order ships once payment is confirmed.',
) ) );
$cod = get_option( 'woocommerce_cod_settings', array() );
update_option( 'woocommerce_cod_settings', array_merge( $cod, array(
	'enabled'      => 'yes',
	'title'        => 'Pay on delivery',
	'description'  => 'Pay with cash or transfer when your order arrives.',
	'instructions' => 'Please have the exact amount ready when your order arrives.',
) ) );

// One shipping zone for Nigeria. Rates are PLACEHOLDERS until the client confirms them.
$zone_id = 0;
foreach ( WC_Shipping_Zones::get_zones() as $z ) {
	if ( 'Nigeria' === $z['zone_name'] ) {
		$zone_id = $z['id'];
	}
}
$zone = new WC_Shipping_Zone( $zone_id ?: null );
$zone->set_zone_name( 'Nigeria' );
$zone->set_locations( array( array( 'code' => 'NG', 'type' => 'country' ) ) );
$zone->save();
if ( ! $zone->get_shipping_methods() ) {
	$instance = $zone->add_shipping_method( 'flat_rate' );
	update_option( "woocommerce_flat_rate_{$instance}_settings", array(
		'title'      => 'Standard delivery',
		'tax_status' => 'none',
		'cost'       => '5000',
	) );
	$pickup = $zone->add_shipping_method( 'local_pickup' );
	update_option( "woocommerce_local_pickup_{$pickup}_settings", array(
		'title' => 'Pick up from our store',
		'cost'  => '',
	) );
}
ehmar_log( 'Payments and shipping configured.' );

flush_rewrite_rules();
ehmar_log( 'Done.' );
