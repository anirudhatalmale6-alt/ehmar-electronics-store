<?php
/**
 * Ehmar Electronics child theme.
 */

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'ehmar', get_stylesheet_directory_uri() . '/assets/ehmar.css', array(), filemtime( get_stylesheet_directory() . '/assets/ehmar.css' ) );
} );

add_action( 'after_setup_theme', function () {
	add_theme_support( 'custom-logo' );
	add_editor_style( 'assets/ehmar.css' );
} );

// Four products per row on desktop listings.
add_filter( 'loop_shop_per_page', fn() => 24 );

// The block theme's "Uncategorized" product category is noise in a small catalogue.
add_filter( 'woocommerce_product_categories_widget_args', function ( $args ) {
	$args['exclude'] = get_option( 'default_product_cat' );
	return $args;
} );

// WooCommerce 11.2 loads the mini-cart script module on cart/checkout pages but skips hydrating its
// state there, so the cart store fetches "undefinedwc/store/v1/cart" (404). Provide the REST root.
add_action( 'wp_enqueue_scripts', function () {
	if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) && function_exists( 'wp_interactivity_state' ) ) {
		wp_interactivity_state( 'woocommerce', array( 'restUrl' => get_rest_url() ) );
	}
} );
