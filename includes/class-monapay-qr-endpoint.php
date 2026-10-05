<?php
/**
 * Authenticated image endpoint used by checkout pages and HTML emails.
 *
 * @package MonaPay_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MonaPay_QR_Endpoint {
	/**
	 * Register the image handlers.
	 *
	 * The WooCommerce API route is the canonical URL because it lives outside
	 * /wp-admin/, which hosts and firewalls often restrict. The admin-post pair
	 * stays so links already sent in customer emails keep working.
	 */
	public function __construct() {
		add_action( 'woocommerce_api_monapay_qr', array( $this, 'serve' ) );
		add_action( 'admin_post_monapay_qr_image', array( $this, 'serve' ) );
		add_action( 'admin_post_nopriv_monapay_qr_image', array( $this, 'serve' ) );
	}

	/**
	 * Build the protected QR image URL for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	public static function get_url( $order ) {
		return add_query_arg(
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
			),
			WC()->api_request_url( 'monapay_qr' )
		);
	}

	/**
	 * Verify the order key and stream a generated PNG.
	 */
	public function serve() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public image URL is authenticated with the WooCommerce order key below.
		$order_id = isset( $_GET['order_id'] ) ? absint( sanitize_text_field( wp_unslash( $_GET['order_id'] ) ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public image URL is authenticated with the WooCommerce order key below.
		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$order = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			status_header( 404 );
			exit;
		}

		$qr_data = (string) $order->get_meta( '_monapay_qr_data', true );
		if ( 'monapay_vietqr' !== $order->get_payment_method() || '' === $qr_data ) {
			status_header( 404 );
			exit;
		}

		// The encoder is only needed here, so it is not parsed on ordinary requests.
		require_once MONAPAY_WC_PATH . 'includes/class-monapay-qr-code.php';

		try {
			$png = MonaPay_QR_Code::png( $qr_data, 5, 4 );
		} catch ( Exception $exception ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					$exception->getMessage(),
					array(
						'source'   => 'mona-pay-for-woocommerce',
						'order_id' => $order_id,
					)
				);
			}
			status_header( 500 );
			exit;
		}

		// The payload of an order never changes, so a private day-long cache spares
		// re-encoding every time a mail client or the order page asks for the image.
		header( 'Cache-Control: private, max-age=86400' );
		header( 'Content-Type: image/png' );
		header( 'Content-Length: ' . strlen( $png ) );
		header( 'Content-Disposition: inline; filename="monapay-qr-' . $order_id . '.png"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PNG bytes.
		exit;
	}
}
