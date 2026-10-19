<?php
/**
 * Registers the Afriex gateway with the WooCommerce cart/checkout blocks.
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Ehmar_Afriex_Blocks extends AbstractPaymentMethodType {

	protected $name = 'ehmar_afriex';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_ehmar_afriex_settings', array() );
	}

	public function is_active() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways['ehmar_afriex'] ) && $gateways['ehmar_afriex']->is_available();
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'ehmar-afriex-blocks',
			plugins_url( 'assets/blocks.js', EHMAR_AFRIEX_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			filemtime( plugin_dir_path( EHMAR_AFRIEX_FILE ) . 'assets/blocks.js' ),
			true
		);
		return array( 'ehmar-afriex-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'supports'    => array( 'products' ),
		);
	}
}
