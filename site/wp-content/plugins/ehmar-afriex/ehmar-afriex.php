<?php
/**
 * Plugin Name: Ehmar Afriex Payments
 * Description: Afriex hosted checkout for WooCommerce — card, bank transfer and mobile money, with local African currencies.
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 * Author: Ehmar
 */

defined( 'ABSPATH' ) || exit;

define( 'EHMAR_AFRIEX_FILE', __FILE__ );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	require_once __DIR__ . '/includes/class-ehmar-afriex-api.php';
	require_once __DIR__ . '/includes/class-ehmar-afriex-gateway.php';

	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'Ehmar_Afriex_Gateway';
		return $gateways;
	} );
} );

// Block checkout support.
add_action( 'woocommerce_blocks_loaded', function () {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}
	require_once __DIR__ . '/includes/class-ehmar-afriex-blocks.php';
	add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
		$registry->register( new Ehmar_Afriex_Blocks() );
	} );
} );

// Declare compatibility with order storage (HPOS) and the cart/checkout blocks.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', EHMAR_AFRIEX_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', EHMAR_AFRIEX_FILE, true );
	}
} );
