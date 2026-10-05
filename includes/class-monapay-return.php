<?php
/**
 * Hosted checkout return and cancellation handlers.
 *
 * @package MonaPay_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MonaPay_Return {
	/** Register the WooCommerce API return endpoint and checkout cancel notice. */
	public function __construct() {
		add_action( 'woocommerce_api_monapay_return', array( $this, 'handle' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_cancel' ), 5 );
	}

	/** Handle a signed paid return or an unsigned cancelled return. */
	public function handle() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public MONA Pay redirect; paid returns are HMAC-verified and cancellations require the WooCommerce order key.
		$checkout_id = isset( $_GET['monapay_checkout'] ) ? sanitize_text_field( wp_unslash( $_GET['monapay_checkout'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public MONA Pay redirect; paid returns are HMAC-verified below.
		$order_code = isset( $_GET['order_code'] ) ? sanitize_text_field( wp_unslash( $_GET['order_code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public MONA Pay redirect; status is covered by HMAC or order-key validation.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public MONA Pay redirect; timestamp is covered by HMAC verification below.
		$timestamp = isset( $_GET['ts'] ) ? sanitize_text_field( wp_unslash( $_GET['ts'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public MONA Pay redirect; this is the HMAC value verified below.
		$signature = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public cancellation redirect authenticated with the WooCommerce order key.
		$order_id = isset( $_GET['order_id'] ) ? absint( sanitize_text_field( wp_unslash( $_GET['order_id'] ) ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public cancellation redirect authenticated with the WooCommerce order key.
		$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

		if ( 'cancelled' === $status ) {
			if ( $this->mark_cancelled( $checkout_id, $order_id, $order_key ) ) {
				$this->redirect_to_checkout( __( 'Payment has not been completed.', 'mona-pay-for-woocommerce' ), 'notice' );
			}
			$this->redirect_to_checkout( __( 'The payment cancellation link is not valid.', 'mona-pay-for-woocommerce' ), 'error' );
		}

		$order    = $this->find_paid_return_order( $checkout_id, $order_code );
		$settings = get_option( 'woocommerce_monapay_vietqr_settings', array() );
		$secret   = is_array( $settings ) && isset( $settings['return_signature_secret'] ) ? (string) $settings['return_signature_secret'] : '';
		if ( ! $order || ! monapay_verify_return_signature( $checkout_id, $order_code, $status, $timestamp, $signature, $secret ) ) {
			$this->log( 'warning', 'Hosted checkout return has an invalid signature or invalid data.', array( 'checkout_id' => $checkout_id ) );
			$this->redirect_to_checkout( __( 'The payment confirmation link is not valid. Please try again.', 'mona-pay-for-woocommerce' ), 'error' );
		}

		try {
			$api      = new MonaPay_API( $this->api_settings( $settings ) );
			$checkout = $api->get_checkout( $checkout_id );
			$is_paid  = isset( $checkout['status'], $checkout['order_code'], $checkout['paid_amount'], $checkout['transaction_code'] )
				&& 'paid' === (string) $checkout['status']
				&& $order_code === (string) $checkout['order_code']
				&& is_numeric( $checkout['paid_amount'] )
				&& '' !== (string) $checkout['transaction_code'];

			if ( $is_paid ) {
				$result = monapay_complete_order_payment(
					$order,
					(string) $checkout['transaction_code'],
					$checkout['paid_amount'],
					isset( $settings['autocomplete_orders'] ) && 'yes' === $settings['autocomplete_orders']
				);
				if ( in_array( $result, array( 'completed', 'duplicate' ), true ) ) {
					$this->log(
						'info',
						'Hosted checkout return reconciled and payment confirmed.',
						array(
							'order_id'    => $order->get_id(),
							'checkout_id' => $checkout_id,
						)
					);
					$this->redirect_to_order( $order );
				}
			}
		} catch ( Exception $exception ) {
			$this->log(
				'error',
				$exception->getMessage(),
				array(
					'order_id'    => $order->get_id(),
					'checkout_id' => $checkout_id,
				)
			);
		}

		$this->redirect_to_order( $order, __( 'The payment is waiting for confirmation.', 'mona-pay-for-woocommerce' ) );
	}

	/** Show the cancellation notice when MONA Pay redirects directly to checkout. */
	public function maybe_handle_cancel() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public MONA Pay cancellation redirect authenticated with the WooCommerce order key.
		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public MONA Pay cancellation redirect authenticated with the WooCommerce order key.
		$checkout_id = isset( $_GET['monapay_checkout'] ) ? sanitize_text_field( wp_unslash( $_GET['monapay_checkout'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public cancellation redirect authenticated with the WooCommerce order key.
		$order_id = isset( $_GET['order_id'] ) ? absint( sanitize_text_field( wp_unslash( $_GET['order_id'] ) ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public cancellation redirect authenticated with the WooCommerce order key.
		$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( 'cancelled' !== $status || '' === $checkout_id || ! $this->mark_cancelled( $checkout_id, $order_id, $order_key ) ) {
			return;
		}

		if ( ! wc_has_notice( __( 'Payment has not been completed.', 'mona-pay-for-woocommerce' ), 'notice' ) ) {
			wc_add_notice( __( 'Payment has not been completed.', 'mona-pay-for-woocommerce' ), 'notice' );
		}
	}

	/** Find and mark a redirect order as cancelled without cancelling the order. */
	private function mark_cancelled( $checkout_id, $order_id, $order_key ) {
		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || '' === $order_key || ! hash_equals( (string) $order->get_order_key(), $order_key ) || ! hash_equals( (string) $order->get_meta( '_monapay_checkout_id', true ), $checkout_id ) ) {
			return false;
		}
		if ( $order->is_paid() || 'redirect' !== (string) $order->get_meta( '_monapay_payment_mode', true ) ) {
			return false;
		}

		$order->update_meta_data( '_monapay_checkout_status', 'cancelled' );
		$order->save();
		return true;
	}

	/** Resolve a paid return to its exact WooCommerce order. */
	private function find_paid_return_order( $checkout_id, $order_code ) {
		if ( '' === $checkout_id || ! preg_match( '/^DH([1-9][0-9]*)$/', $order_code, $matches ) ) {
			return false;
		}

		$order = wc_get_order( (int) $matches[1] );
		if ( ! $order || 'monapay_vietqr' !== $order->get_payment_method() ) {
			return false;
		}

		$stored_checkout_id = (string) $order->get_meta( '_monapay_checkout_id', true );
		return '' !== $stored_checkout_id && hash_equals( $stored_checkout_id, $checkout_id ) ? $order : false;
	}

	/** Build API settings from the gateway option without exposing secrets. */
	private function api_settings( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		return array(
			'base_url'      => isset( $settings['base_url'] ) ? (string) $settings['base_url'] : 'https://api.monapay.vn',
			'client_id'     => isset( $settings['client_id'] ) ? (string) $settings['client_id'] : '',
			'username'      => isset( $settings['username'] ) ? (string) $settings['username'] : '',
			'password'      => isset( $settings['password'] ) ? (string) $settings['password'] : '',
			'client_secret' => isset( $settings['client_secret'] ) ? (string) $settings['client_secret'] : '',
		);
	}

	/** Redirect back to the shop checkout with a WooCommerce notice. */
	private function redirect_to_checkout( $message, $type ) {
		wc_add_notice( $message, $type );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/** Redirect to the order received page, optionally with a notice. */
	private function redirect_to_order( $order, $message = '' ) {
		if ( '' !== $message ) {
			wc_add_notice( $message, 'notice' );
		}
		wp_safe_redirect( $order->get_checkout_order_received_url() );
		exit;
	}

	/** Write a structured entry to the WooCommerce logger. */
	private function log( $level, $message, $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$context['source'] = 'mona-pay-for-woocommerce';
		wc_get_logger()->log( $level, $message, $context );
	}
}
