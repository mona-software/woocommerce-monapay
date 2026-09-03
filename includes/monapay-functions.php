<?php
/**
 * Small pure functions shared by the webhook handler and standalone tests.
 *
 * @package MonaPay_WooCommerce
 */

defined( 'ABSPATH' ) || defined( 'MONAPAY_TESTING' ) || exit;

if ( ! function_exists( 'monapay_verify_signature' ) ) {
	/**
	 * Verify a MONA Pay webhook signature using its unmodified request body.
	 *
	 * @param string   $raw_body   Raw HTTP request body.
	 * @param string   $timestamp  Unix timestamp received in X-Mona-Timestamp.
	 * @param string   $signature  Value received in X-Mona-Signature.
	 * @param string   $secret     Shared HMAC secret.
	 * @param int|null $now        Current Unix time. Injectable for tests.
	 * @param int      $tolerance  Maximum clock drift in seconds.
	 * @return bool
	 */
	function monapay_verify_signature( $raw_body, $timestamp, $signature, $secret, $now = null, $tolerance = 300 ) {
		if ( '' === $secret || ! is_string( $timestamp ) || ! preg_match( '/^[0-9]{1,12}$/', $timestamp ) ) {
			return false;
		}

		if ( ! is_string( $signature ) || ! preg_match( '/^sha256=[a-f0-9]{64}$/', $signature ) ) {
			return false;
		}

		$current_time = null === $now ? time() : (int) $now;
		if ( abs( $current_time - (int) $timestamp ) > (int) $tolerance ) {
			return false;
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
		return hash_equals( $expected, $signature );
	}
}

if ( ! function_exists( 'monapay_parse_order_id' ) ) {
	/**
	 * Extract a numeric WooCommerce order ID from a DH123 payment description.
	 *
	 * @param string $description Bank transfer description.
	 * @return int|null
	 */
	function monapay_parse_order_id( $description ) {
		if ( ! is_string( $description ) || ! preg_match( '/(?:^|[^A-Z0-9])DH\s*#?\s*([0-9]+)(?:$|[^0-9])/i', $description, $matches ) ) {
			return null;
		}

		$order_id = (int) $matches[1];
		return $order_id > 0 ? $order_id : null;
	}
}

if ( ! function_exists( 'monapay_verify_return_signature' ) ) {
	/**
	 * Verify the signed redirect sent by a MONA Pay hosted checkout.
	 *
	 * @param string   $checkout_id Checkout UUID.
	 * @param string   $order_code  Merchant order code.
	 * @param string   $status      Checkout status. Only paid redirects are signed.
	 * @param string   $timestamp   Unix timestamp from the redirect.
	 * @param string   $signature   Lowercase hexadecimal HMAC-SHA256 signature.
	 * @param string   $secret      Payment profile return secret.
	 * @param int|null $now         Current Unix time. Injectable for tests.
	 * @param int      $tolerance   Maximum clock drift in seconds.
	 * @return bool
	 */
	function monapay_verify_return_signature( $checkout_id, $order_code, $status, $timestamp, $signature, $secret, $now = null, $tolerance = 600 ) {
		if ( '' === $secret || '' === $checkout_id || '' === $order_code || 'paid' !== $status ) {
			return false;
		}

		if ( ! is_string( $timestamp ) || ! preg_match( '/^[0-9]{1,12}$/', $timestamp ) ) {
			return false;
		}

		if ( ! is_string( $signature ) || ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
			return false;
		}

		$current_time = null === $now ? time() : (int) $now;
		if ( abs( $current_time - (int) $timestamp ) > (int) $tolerance ) {
			return false;
		}

		$message  = $checkout_id . '|' . $order_code . '|paid|' . $timestamp;
		$expected = hash_hmac( 'sha256', $message, $secret );
		return hash_equals( $expected, $signature );
	}
}

if ( ! function_exists( 'monapay_complete_order_payment' ) ) {
	/**
	 * Apply a verified MONA Pay payment to an order exactly once per transaction.
	 *
	 * @param WC_Order $order            WooCommerce order.
	 * @param string   $transaction_code MONA Pay/bank transaction code.
	 * @param int      $paid_amount      Verified paid amount in VND.
	 * @param bool     $autocomplete     Whether to force the completed status.
	 * @return string completed, duplicate, underpaid, or invalid.
	 */
	function monapay_complete_order_payment( $order, $transaction_code, $paid_amount, $autocomplete = false ) {
		$transaction_code = sanitize_text_field( (string) $transaction_code );
		if ( ! is_object( $order ) || '' === $transaction_code || ! is_numeric( $paid_amount ) ) {
			return 'invalid';
		}

		$paid_amount = (int) round( (float) $paid_amount );
		$order_total = (int) round( (float) $order->get_total() );
		if ( $paid_amount < $order_total ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: received amount, 2: required amount, 3: bank transaction code. */
					__( 'MONA Pay nhận thiếu: %1$s / %2$s VND (mã %3$s). Đơn chưa được xác nhận.', 'woocommerce-monapay' ),
					wc_format_localized_price( $paid_amount ),
					wc_format_localized_price( $order_total ),
					$transaction_code
				)
			);
			return 'underpaid';
		}

		$transaction_codes = $order->get_meta( '_monapay_txn_codes', true );
		$transaction_codes = is_array( $transaction_codes ) ? array_map( 'strval', $transaction_codes ) : array();
		$is_duplicate      = in_array( $transaction_code, $transaction_codes, true ) || $transaction_code === (string) $order->get_transaction_id();
		if ( $is_duplicate && $order->is_paid() ) {
			return 'duplicate';
		}

		if ( ! in_array( $transaction_code, $transaction_codes, true ) ) {
			$transaction_codes[] = $transaction_code;
			$order->update_meta_data( '_monapay_txn_codes', array_values( array_unique( $transaction_codes ) ) );
		}
		$order->update_meta_data( '_monapay_checkout_status', 'paid' );
		$order->save();

		$order->payment_complete( $transaction_code );
		$order->add_order_note(
			sprintf(
				/* translators: %s: bank transaction code. */
				__( 'MONA Pay đã tự động xác nhận thanh toán. Mã giao dịch: %s.', 'woocommerce-monapay' ),
				$transaction_code
			)
		);

		if ( $autocomplete && ! $order->has_status( 'completed' ) ) {
			$order->update_status( 'completed', __( 'MONA Pay tự động hoàn tất đơn theo cấu hình.', 'woocommerce-monapay' ) );
		}

		return 'completed';
	}
}
