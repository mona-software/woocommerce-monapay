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
