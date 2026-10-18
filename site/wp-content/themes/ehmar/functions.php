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
