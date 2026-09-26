<?php
/**
 * Standalone tests: php tests/test-signature.php
 */

define( 'MONAPAY_TESTING', true );
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require_once dirname( __DIR__ ) . '/includes/monapay-functions.php';

$failures = 0;
$tests    = 0;

/**
 * Tiny assertion helper with no WordPress or PHPUnit dependency.
 *
 * @param bool   $condition Test result.
 * @param string $message   Assertion label.
 */
function monapay_test_assert( $condition, $message ) {
	global $failures, $tests;
	$tests++;
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}
	$failures++;
	fwrite( STDERR, "FAIL: {$message}\n" );
}

$timestamp = '1756355400';
$secret    = '0123456789abcdef0123456789abcdef';
$body      = '{"amount":2500000,"description":"DH123","transaction_code":"FT26240001234","account_number":"MONA00000123","type":"income"}';
$known     = 'sha256=c7b09ff9e0e8eaee7d31e9c35f08fb41222543b8d6e793f1c4eed5b23008d28a';

monapay_test_assert( monapay_verify_signature( $body, $timestamp, $known, $secret, 1756355400 ), 'chấp nhận vector HMAC-SHA256 đã biết' );
monapay_test_assert( ! monapay_verify_signature( $body . ' ', $timestamp, $known, $secret, 1756355400 ), 'ký trên raw body, từ chối body bị thay đổi' );
monapay_test_assert( ! monapay_verify_signature( $body, $timestamp, 'sha256=' . str_repeat( '0', 64 ), $secret, 1756355400 ), 'từ chối chữ ký sai' );
monapay_test_assert( ! monapay_verify_signature( $body, $timestamp, $known, $secret, 1756355701 ), 'từ chối timestamp cũ hơn 300 giây' );
monapay_test_assert( ! monapay_verify_signature( $body, $timestamp, $known, $secret, 1756355099 ), 'từ chối timestamp tương lai quá 300 giây' );
monapay_test_assert( ! monapay_verify_signature( $body, '1756355400.0', $known, $secret, 1756355400 ), 'từ chối timestamp không phải số nguyên' );
monapay_test_assert( ! monapay_verify_signature( $body, $timestamp, strtoupper( $known ), $secret, 1756355400 ), 'từ chối chữ ký không đúng định dạng chuẩn' );
monapay_test_assert( ! monapay_verify_signature( $body, $timestamp, $known, '', 1756355400 ), 'từ chối secret rỗng' );

monapay_test_assert( 123 === monapay_parse_order_id( 'DH123' ), 'parse DH123' );
monapay_test_assert( 10234 === monapay_parse_order_id( 'Thanh toan DH10234 tai ACB' ), 'parse DH{id} trong nội dung ngân hàng' );
monapay_test_assert( 42 === monapay_parse_order_id( 'thanh toan dh # 42' ), 'parse không phân biệt hoa thường và khoảng trắng' );
monapay_test_assert( null === monapay_parse_order_id( 'ABCDH123' ), 'không parse DH nằm trong mã khác' );
monapay_test_assert( null === monapay_parse_order_id( 'DHM123' ), 'không parse prefix khác' );
monapay_test_assert( null === monapay_parse_order_id( 'DH0' ), 'không chấp nhận order ID bằng 0' );

echo "\n{$tests} tests, {$failures} failures\n";
exit( $failures > 0 ? 1 : 0 );
