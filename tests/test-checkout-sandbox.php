<?php
/**
 * Standalone hosted-checkout sandbox payload tests.
 *
 * Run with: php tests/test-checkout-sandbox.php
 */

define( 'MONAPAY_TESTING', true );
require_once dirname( __DIR__ ) . '/includes/monapay-functions.php';

$failures = 0;
$tests    = 0;

/**
 * Tiny assertion helper with no WordPress or PHPUnit dependency.
 *
 * @param bool   $condition Test result.
 * @param string $message   Assertion label.
 */
function monapay_checkout_sandbox_assert( $condition, $message ) {
	global $failures, $tests;
	$tests++;
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}

	$failures++;
	fwrite( STDERR, "FAIL: {$message}\n" );
}

$base_payload = array(
	'amount'      => 250000,
	'order_code'  => 'DH123',
	'description' => 'Thanh toán đơn hàng DH123',
);

$sandbox_payload = monapay_prepare_checkout_payload( $base_payload, 'yes' );
monapay_checkout_sandbox_assert(
	isset( $sandbox_payload['sandbox'] ) && true === $sandbox_payload['sandbox'],
	'create_checkout có sandbox:true khi bật chế độ thử'
);
monapay_checkout_sandbox_assert(
	250000 === $sandbox_payload['amount'] && 'DH123' === $sandbox_payload['order_code'],
	'bật sandbox không làm thay đổi dữ liệu checkout còn lại'
);

$live_payload = monapay_prepare_checkout_payload( array_merge( $base_payload, array( 'sandbox' => true ) ), 'no' );
monapay_checkout_sandbox_assert(
	! array_key_exists( 'sandbox', $live_payload ),
	'create_checkout không có trường sandbox khi tắt chế độ thử'
);
monapay_checkout_sandbox_assert(
	$base_payload === $live_payload,
	'payload live giữ nguyên sau khi loại bỏ cờ sandbox'
);

echo "\n{$tests} tests, {$failures} failures\n";
exit( $failures > 0 ? 1 : 0 );
