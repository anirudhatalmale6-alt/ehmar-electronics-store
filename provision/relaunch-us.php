<?php
/**
 * Relaunch ehmar.com for the US business (Square catalogue, US dollars).
 *
 * Runs after setup.php; idempotent. Removes the demo catalogue, switches the store to USD,
 * rewrites the info pages and adds the Cars & Engines request page.
 * Run with:  wp eval-file provision/relaunch-us.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rl_log( $msg ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::log( $msg );
	} else {
		echo esc_html( $msg ) . "\n";
	}
}

function rl_page( $slug, $title, $content ) {
	$page = get_page_by_path( $slug );
	$data = array( 'post_title' => $title, 'post_name' => $slug, 'post_content' => $content, 'post_status' => 'publish', 'post_type' => 'page' );
	if ( $page ) {
		$data['ID'] = $page->ID;
		return wp_update_post( $data );
	}
	return wp_insert_post( $data );
}

/* ---------------------------------------------------------------- Store: US dollars, California address */

foreach ( array(
	'woocommerce_currency'           => 'USD',
	'woocommerce_currency_pos'       => 'left',
	'woocommerce_price_num_decimals' => 2,
	'woocommerce_default_country'    => 'US:CA',
	'woocommerce_store_address'      => '21200 Oxnard St Suite 6763',
	'woocommerce_store_city'         => 'Woodland Hills',
	'woocommerce_store_postcode'     => '91365',
	'woocommerce_weight_unit'        => 'lbs',
	'woocommerce_dimension_unit'     => 'in',
	'woocommerce_allowed_countries'  => 'all',
	'woocommerce_ship_to_countries'  => '',
	'timezone_string'                => 'America/Los_Angeles',
	'blogdescription'                => 'Security Cameras, Electronics, Kitchen & US Auction Cars',
) as $key => $value ) {
	update_option( $key, $value );
}
rl_log( 'Store switched to USD / California.' );

/* ---------------------------------------------------------------- Remove the demo catalogue */

$removed = 0;
foreach ( wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'draft', 'private' ) ) ) as $product ) {
	if ( 0 !== strpos( (string) $product->get_sku(), 'DEMO-' ) ) {
		continue;
	}
	$images = array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() );
	foreach ( $product->get_children() as $child_id ) {
		wp_delete_post( $child_id, true );
	}
	$product->delete( true );
	foreach ( array_filter( $images ) as $att ) {
		if ( get_post_meta( $att, '_ehmar_source', true ) && 0 !== strpos( (string) get_post_meta( $att, '_ehmar_source', true ), 'ehmar-' ) ) {
			wp_delete_attachment( $att, true );
		}
	}
	$removed++;
}
rl_log( "Demo products removed: $removed." );

// Demo-era categories and brands that no longer hold anything.
foreach ( array( 'phones', 'memory-cards', 'solar-generators', 'power-banks', 'chargers-cables', 'accessories' ) as $slug ) {
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	if ( $term && 0 === (int) $term->count ) {
		wp_delete_term( $term->term_id, 'product_cat' );
	}
}
foreach ( get_terms( array( 'taxonomy' => 'product_brand', 'hide_empty' => false ) ) as $brand ) {
	if ( 0 === (int) $brand->count ) {
		wp_delete_term( $brand->term_id, 'product_brand' );
	}
}

$descriptions = array(
	'security-cameras' => 'Wi-Fi, 4G and solar security cameras with night vision, two-way audio and motion alerts to your phone.',
	'video-doorbells'  => 'See and talk to whoever is at your door, from anywhere.',
	'phones-tablets'   => 'Android tablets, smartphones and smart watches.',
	'solar-power'      => 'Portable solar power stations and generators.',
	'electronics'      => 'Bluetooth speakers, shavers, learning devices and more.',
	'kitchen'          => 'Food warmers, warming trays and juicers.',
	'car-accessories'  => 'Car diffusers, cleaning blowers and accessories.',
);
foreach ( $descriptions as $slug => $desc ) {
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	if ( $term ) {
		wp_update_term( $term->term_id, 'product_cat', array( 'description' => $desc ) );
	}
}

/* ---------------------------------------------------------------- Pages */

rl_page( 'about', 'About Ehmar', <<<HTML
<!-- wp:paragraph {"fontSize":"large"} --><p class="has-large-font-size">Ehmar is a California-based business bringing reliable security cameras, everyday electronics and kitchen essentials to customers in the USA and across Africa.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2 class="wp-block-heading">What we offer</h2><!-- /wp:heading -->
<!-- wp:list --><ul class="wp-block-list"><li><strong>Security cameras &amp; video doorbells</strong> — Wi-Fi, 4G and solar cameras with night vision and two-way audio</li><li><strong>Electronics</strong> — tablets, phones, smart watches, speakers and solar power stations</li><li><strong>Kitchen</strong> — food warmers, warming trays and juicers</li><li><strong>Cars &amp; engines</strong> — vehicles from US auctions and quality used engines, shipped to customers in Africa</li></ul><!-- /wp:list -->
<!-- wp:paragraph --><p>Ehmar is part of the same family as <a href="https://ehmarfoods.com">Ehmar Foods</a>.</p><!-- /wp:paragraph -->
HTML );

rl_page( 'contact', 'Contact Us', <<<HTML
<!-- wp:paragraph --><p>Questions about a product, an order or a car request? We're happy to help.</p><!-- /wp:paragraph -->
<!-- wp:group {"className":"ehmar-page-card","layout":{"type":"constrained"}} --><div class="wp-block-group ehmar-page-card">
<!-- wp:list --><ul class="wp-block-list"><li><strong>Address:</strong> 21200 Oxnard St Suite 6763, Woodland Hills, CA 91365, USA</li><li><strong>Phone:</strong> <a href="tel:+13239998224">+1 (323) 999-8224</a></li><li><strong>Email:</strong> <a href="mailto:info@ehmar.com">info@ehmar.com</a></li><li><strong>Hours:</strong> Monday – Saturday, 9:00am – 5:00pm (Pacific). Closed Sunday.</li></ul><!-- /wp:list -->
</div><!-- /wp:group -->
HTML );

rl_page( 'cars-engines', 'Cars & Engines', <<<HTML
<!-- wp:paragraph {"fontSize":"large"} --><p class="has-large-font-size">Get the car or engine you want from US auctions, shipped to you in Africa.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>We source vehicles from US auto auctions and quality used engines, and arrange shipping to customers across Africa. Tell us what you are looking for and your budget — we'll find options and send you a quote.</p><!-- /wp:paragraph -->
<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">How it works</h3><!-- /wp:heading -->
<!-- wp:list {"ordered":true} --><ol class="wp-block-list"><li>Send your request below (car or engine, model, year, budget, country).</li><li>We search US auctions and suppliers and send you matching options with prices.</li><li>You choose, we handle the purchase and arrange shipping to your country.</li></ol><!-- /wp:list -->
<!-- wp:shortcode -->[ehmar_car_request]<!-- /wp:shortcode -->
HTML );

rl_page( 'shipping-delivery', 'Shipping & Delivery', <<<HTML
<!-- wp:paragraph --><p>We ship within the USA and internationally. Shipping cost and delivery time depend on the item and your location — we confirm both with you after you place your order, before anything is charged.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Cars and engines are shipped to Africa by sea; we agree the shipping details with you as part of your quote. See <a href="/cars-engines/">Cars &amp; Engines</a>.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Questions? <a href="/contact/">Contact us</a>.</p><!-- /wp:paragraph -->
HTML );

rl_page( 'returns-warranty', 'Returns & Warranty', <<<HTML
<!-- wp:paragraph --><p>We want you to be happy with every purchase. If something isn't right, contact us and we'll sort it out.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>[Return window, conditions and warranty details to be confirmed.]</p><!-- /wp:paragraph -->
HTML );

rl_page( 'faq', 'Frequently Asked Questions', <<<HTML
<!-- wp:html -->
<div class="ehmar-faq">
<details open><summary>Where are you based?</summary><p>We are based in Woodland Hills, California, USA. See our <a href="/contact/">contact details</a>.</p></details>
<details><summary>How much is shipping?</summary><p>Shipping depends on the item and your location. We confirm the cost with you after you place your order, before you are charged.</p></details>
<details><summary>Can you get me a car or an engine from a US auction?</summary><p>Yes. Send us your request on the <a href="/cars-engines/">Cars &amp; Engines</a> page and we'll come back to you with options and a quote.</p></details>
<details><summary>Do you sell gift cards?</summary><p>Yes — see <a href="https://squareup.com/gift/09W8K6C53WTYD/order" target="_blank" rel="noopener">Gift Cards</a>.</p></details>
<details><summary>Can I return an item?</summary><p>Please see <a href="/returns-warranty/">Returns &amp; Warranty</a>, or contact us and we'll help.</p></details>
</div>
<!-- /wp:html -->
HTML );
rl_log( 'Pages ready (about, contact, cars-engines, shipping, returns, faq).' );

/* ---------------------------------------------------------------- Payments & shipping (interim, until the client's gateway is connected) */

update_option( 'woocommerce_cod_settings', array_merge( (array) get_option( 'woocommerce_cod_settings', array() ), array( 'enabled' => 'no' ) ) );
update_option( 'woocommerce_bacs_settings', array_merge( (array) get_option( 'woocommerce_bacs_settings', array() ), array( 'enabled' => 'no' ) ) );
update_option( 'woocommerce_cheque_settings', array_merge( (array) get_option( 'woocommerce_cheque_settings', array() ), array(
	'enabled'      => 'yes',
	'title'        => 'Place order — we contact you to arrange payment',
	'description'  => 'No payment is taken now. We will contact you to confirm shipping cost and send you a secure payment link.',
	'instructions' => 'Thank you! We will contact you shortly to confirm shipping and send you a secure payment link.',
) ) );

// Ship anywhere; the cost is confirmed with the customer after ordering.
foreach ( WC_Shipping_Zones::get_zones() as $z ) {
	if ( 'Nigeria' === $z['zone_name'] ) {
		WC_Shipping_Zones::delete_zone( $z['id'] );
	}
}
$rest = new WC_Shipping_Zone( 0 );
$has  = false;
foreach ( $rest->get_shipping_methods() as $m ) {
	$has = $has || 'flat_rate' === $m->id;
}
if ( ! $has ) {
	$instance = $rest->add_shipping_method( 'flat_rate' );
	update_option( "woocommerce_flat_rate_{$instance}_settings", array(
		'title'      => 'Shipping (cost confirmed after you order)',
		'tax_status' => 'none',
		'cost'       => '0',
	) );
}
rl_log( 'Payments (interim) and shipping configured.' );

flush_rewrite_rules();
rl_log( 'Relaunch done.' );
