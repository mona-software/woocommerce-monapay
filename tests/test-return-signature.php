<?php
/**
 * Standalone hosted-checkout return signature tests.
 */

define( 'MONAPAY_TESTING', true );
require_once dirname( __DIR__ ) . '/includes/monapay-functions.php';

$failures = 0;
$tests    = 0;

function monapay_return_assert( $condition, $message ) {
	global $failures, $tests;
	$tests++;
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}
	$failures++;
	fwrite( STDERR, "FAIL: {$message}\n" );
}

$checkout_id = '0190e0f1-1234-7000-8000-123456789abc';
$order_code  = 'DH10234';
$timestamp   = '1788400800';
$secret      = '0123456789abcdef0123456789abcdef0123456789abcdef';
$known       = '026e9004f441dee6bd486c303f664e6cb09eb3c0fdb82e1e381be45ccffaf633';

monapay_return_assert( monapay_verify_return_signature( $checkout_id, $order_code, 'paid', $timestamp, $known, $secret, 1788400800 ), 'chấp nhận vector HMAC-SHA256 return đã biết' );
monapay_return_assert( monapay_verify_return_signature( $checkout_id, $order_code, 'paid', $timestamp, $known, $secret, 1788401400 ), 'chấp nhận timestamp tại biên 10 phút' );
monapay_return_assert( ! monapay_verify_return_signature( $checkout_id, $order_code, 'paid', $timestamp, $known, $secret, 1788401401 ), 'từ chối timestamp cũ quá 10 phút' );
monapay_return_assert( ! monapay_verify_return_signature( $checkout_id, $order_code, 'paid', $timestamp, $known, $secret, 1788400199 ), 'từ chối timestamp tương lai quá 10 phút' );
monapay_return_assert( ! monapay_verify_return_signature( $checkout_id . '0', $order_code, 'paid', $timestamp, $known, $secret, 1788400800 ), 'từ chối checkout id bị thay đổi' );
monapay_return_assert( ! monapay_verify_return_signature( $checkout_id, 'DH10235', 'paid', $timestamp, $known, $secret, 1788400800 ), 'từ chối order code bị thay đổi' );
monapay_return_assert( ! monapay_verify_return_signature( $checkout_id, $order_code, 'cancelled', $timestamp, $known, $secret, 1788400800 ), 'không chấp nhận trạng thái cancelled bằng chữ ký paid' );
monapay_return_assert( ! monapay_verify_return_signature( $checkout_id, $order_code, 'paid', '1788400800.0', $known, $secret, 1788400800 ), 'từ chối timestamp không phải số nguyên' );
monapay_return_assert( ! monapay_verify_return_signature( $checkout_id, $order_code, 'paid', $timestamp, 'sha256=' . $known, $secret, 1788400800 ), 'từ chối chữ ký có prefix webhook' );
monapay_return_assert( ! monapay_verify_return_signature( $checkout_id, $order_code, 'paid', $timestamp, strtoupper( $known ), $secret, 1788400800 ), 'từ chối chữ ký không đúng lowercase hex' );
monapay_return_assert( ! monapay_verify_return_signature( $checkout_id, $order_code, 'paid', $timestamp, $known, '', 1788400800 ), 'từ chối secret rỗng' );

echo "\n{$tests} tests, {$failures} failures\n";
exit( $failures > 0 ? 1 : 0 );
