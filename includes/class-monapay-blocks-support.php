<?php
/**
 * Payment method registration for the WooCommerce Cart and Checkout blocks.
 *
 * The blocks checkout does not render classic gateways on its own. This class
 * tells it that MONA Pay exists, what to call it and which features it supports;
 * charging still goes through MonaPay_Gateway::process_payment() on the server.
 *
 * @package MonaPay_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class MonaPay_Blocks_Support extends AbstractPaymentMethodType {
	/** @var string Must equal the gateway ID. */
	protected $name = 'monapay_vietqr';

	/** Load the saved gateway settings. */
	public function initialize() {
		$settings       = get_option( 'woocommerce_monapay_vietqr_settings', array() );
		$this->settings = is_array( $settings ) ? $settings : array();
	}

	/**
	 * Whether the method is offered. Currency and credential checks run again in
	 * MonaPay_Gateway::is_available() when the Store API lists gateways.
	 *
	 * @return bool
	 */
	public function is_active() {
		return 'yes' === $this->get_setting( 'enabled', 'no' );
	}

	/**
	 * Register the checkout script and return its handle.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'monapay-blocks-checkout',
			MONAPAY_WC_URL . 'assets/js/blocks-checkout.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			MONAPAY_WC_VERSION,
			true
		);
		return array( 'monapay-blocks-checkout' );
	}

	/**
	 * Data handed to the script through wc.wcSettings.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => wp_strip_all_tags( (string) $this->get_setting( 'title', __( 'Bank transfer (VietQR, confirmed automatically)', 'mona-pay-for-woocommerce' ) ) ),
			'description' => wp_strip_all_tags( (string) $this->get_setting( 'description', '' ) ),
			'supports'    => array( 'products' ),
		);
	}
}
