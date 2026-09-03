<?php
/**
 * Standalone MONA Pay API auth tests: php tests/test-oauth.php
 */

define( 'MONAPAY_TESTING', true );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$monapay_mock_auth_responses = array();
$monapay_mock_api_responses  = array();
$monapay_mock_auth_calls     = array();
$monapay_mock_api_calls      = array();
$monapay_mock_transients     = array();
$monapay_mock_ttls           = array();
$monapay_mock_deletes        = 0;

function __( $text, $domain = null ) {
	unset( $domain );
	return $text;
}

function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}

function wp_http_validate_url( $url ) {
	return false !== filter_var( $url, FILTER_VALIDATE_URL );
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function is_wp_error( $value ) {
	return false;
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['status'];
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

function get_transient( $key ) {
	global $monapay_mock_transients;
	return isset( $monapay_mock_transients[ $key ] ) ? $monapay_mock_transients[ $key ] : false;
}

function set_transient( $key, $value, $ttl ) {
	global $monapay_mock_transients, $monapay_mock_ttls;
	$monapay_mock_transients[ $key ] = $value;
	$monapay_mock_ttls[ $key ]       = $ttl;
	return true;
}

function delete_transient( $key ) {
	global $monapay_mock_transients, $monapay_mock_deletes;
	unset( $monapay_mock_transients[ $key ] );
	$monapay_mock_deletes++;
	return true;
}

function wp_remote_post( $url, $args ) {
	global $monapay_mock_auth_calls, $monapay_mock_auth_responses;
	$monapay_mock_auth_calls[] = array( 'url' => $url, 'args' => $args );
	return array_shift( $monapay_mock_auth_responses );
}

function wp_remote_request( $url, $args ) {
	global $monapay_mock_api_calls, $monapay_mock_api_responses;
	$monapay_mock_api_calls[] = array( 'url' => $url, 'args' => $args );
	return array_shift( $monapay_mock_api_responses );
}

require_once dirname( __DIR__ ) . '/includes/class-monapay-api.php';

$failures = 0;
$tests    = 0;

function monapay_oauth_assert( $condition, $message ) {
	global $failures, $tests;
	$tests++;
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}
	$failures++;
	fwrite( STDERR, "FAIL: {$message}\n" );
}

function monapay_mock_response( $status, $data = array(), $success = true ) {
	return array(
		'status' => $status,
		'body'   => json_encode(
			array(
				'success' => $success,
				'data'    => $data,
			)
		),
	);
}

function monapay_reset_mocks() {
	global $monapay_mock_auth_responses, $monapay_mock_api_responses, $monapay_mock_auth_calls, $monapay_mock_api_calls, $monapay_mock_transients, $monapay_mock_ttls, $monapay_mock_deletes;
	$monapay_mock_auth_responses = array();
	$monapay_mock_api_responses  = array();
	$monapay_mock_auth_calls     = array();
	$monapay_mock_api_calls      = array();
	$monapay_mock_transients     = array();
	$monapay_mock_ttls           = array();
	$monapay_mock_deletes        = 0;
}

monapay_reset_mocks();
$monapay_mock_auth_responses[] = monapay_mock_response( 200, array( 'access_token' => 'oauth-token', 'expires_in' => 120 ) );
$monapay_mock_api_responses[]  = monapay_mock_response( 200, array( 'id' => 'qr-1' ) );
$client = new MonaPay_API(
	array(
		'base_url'     => 'https://api.monapay.vn/api/v1/',
		'client_id'    => 'client_123',
		'client_secret' => 'secret_123',
	)
);
$client->generate_qr( array( 'amount' => 10000 ) );
$oauth_body = json_decode( $monapay_mock_auth_calls[0]['args']['body'], true );
monapay_oauth_assert( 'https://api.monapay.vn/api/v1/oauth/token' === $monapay_mock_auth_calls[0]['url'], 'client credentials dùng đúng OAuth token URL' );
monapay_oauth_assert( array( 'grant_type' => 'client_credentials', 'client_id' => 'client_123', 'client_secret' => 'secret_123' ) === $oauth_body, 'OAuth gửi đúng JSON client credentials' );
monapay_oauth_assert( 'Bearer oauth-token' === $monapay_mock_api_calls[0]['args']['headers']['Authorization'], 'API nhận Bearer token' );
monapay_oauth_assert( 'secret_123' === $monapay_mock_api_calls[0]['args']['headers']['X-Client-Secret'], 'lệnh ghi nhận X-Client-Secret' );
monapay_oauth_assert( 108 === reset( $monapay_mock_ttls ), 'token cache theo expires_in với khoảng an toàn' );

$monapay_mock_api_responses[] = monapay_mock_response( 200, array( 'id' => 'qr-2' ) );
$client->generate_qr( array( 'amount' => 20000 ) );
monapay_oauth_assert( 1 === count( $monapay_mock_auth_calls ), 'token còn hạn được dùng lại từ transient' );

$monapay_mock_api_responses[] = monapay_mock_response( 200, array( 'transaction_code' => 'SANDBOX-1' ) );
$client->create_sandbox_transaction( 'VA00001234', 10000, 'WooCommerce test sandbox' );
$sandbox_body = json_decode( $monapay_mock_api_calls[2]['args']['body'], true );
monapay_oauth_assert( 'https://api.monapay.vn/api/v1/sandbox/transactions' === $monapay_mock_api_calls[2]['url'], 'sandbox dùng đúng endpoint' );
monapay_oauth_assert( 'VA00001234' === $sandbox_body['virtual_account_number'] && 10000 === $sandbox_body['amount'], 'sandbox gửi đúng VA và 10.000 VND' );

$monapay_mock_api_responses[] = monapay_mock_response( 201, array( 'id' => 'checkout-1', 'checkout_url' => 'https://pay.monapay.vn/c/token-1' ) );
$client->create_checkout( array( 'amount' => 250000, 'order_code' => 'DH123' ), 'wc-123-1' );
$checkout_body = json_decode( $monapay_mock_api_calls[3]['args']['body'], true );
monapay_oauth_assert( 'https://api.monapay.vn/api/v1/checkouts' === $monapay_mock_api_calls[3]['url'], 'hosted checkout dùng đúng endpoint' );
monapay_oauth_assert( 'wc-123-1' === $monapay_mock_api_calls[3]['args']['headers']['Idempotency-Key'], 'hosted checkout gửi Idempotency-Key' );
monapay_oauth_assert( 250000 === $checkout_body['amount'] && 'DH123' === $checkout_body['order_code'], 'hosted checkout giữ đúng payload' );

$monapay_mock_api_responses[] = monapay_mock_response( 200, array( 'id' => 'checkout-1', 'status' => 'paid' ) );
$client->get_checkout( 'checkout-1' );
monapay_oauth_assert( 'https://api.monapay.vn/api/v1/checkouts/checkout-1' === $monapay_mock_api_calls[4]['url'], 'đối chiếu checkout dùng đúng endpoint GET' );
monapay_oauth_assert( 'GET' === $monapay_mock_api_calls[4]['args']['method'] && ! isset( $monapay_mock_api_calls[4]['args']['body'] ), 'GET checkout không gửi JSON body' );
monapay_oauth_assert( ! isset( $monapay_mock_api_calls[4]['args']['headers']['X-Client-Secret'] ), 'GET checkout chỉ dùng Bearer token' );

monapay_reset_mocks();
$monapay_mock_auth_responses[] = monapay_mock_response( 200, array( 'access_token' => 'expired-token', 'expires_in' => 3600 ) );
$monapay_mock_auth_responses[] = monapay_mock_response( 200, array( 'access_token' => 'fresh-token', 'expires_in' => 3600 ) );
$monapay_mock_api_responses[]  = monapay_mock_response( 401, array(), false );
$monapay_mock_api_responses[]  = monapay_mock_response( 200, array( 'id' => 'qr-retry' ) );
$retry_client = new MonaPay_API( array( 'client_id' => 'client_retry', 'client_secret' => 'retry_secret' ) );
$retry_client->generate_qr( array( 'amount' => 30000 ) );
monapay_oauth_assert( 2 === count( $monapay_mock_auth_calls ), '401 làm mới token đúng một lần' );
monapay_oauth_assert( 'Bearer expired-token' === $monapay_mock_api_calls[0]['args']['headers']['Authorization'] && 'Bearer fresh-token' === $monapay_mock_api_calls[1]['args']['headers']['Authorization'], 'request được gửi lại bằng token mới' );
monapay_oauth_assert( 1 === $monapay_mock_deletes, '401 xoá transient token cũ' );

monapay_reset_mocks();
$monapay_mock_auth_responses[] = monapay_mock_response( 200, array( 'access_token' => 'legacy-token', 'expires_in' => 86400 ) );
$monapay_mock_api_responses[]  = monapay_mock_response( 200, array( 'id' => 'legacy-qr' ) );
$legacy_client = new MonaPay_API(
	array(
		'client_id'     => '',
		'username'      => 'legacy@example.com',
		'password'      => 'legacy-password',
		'client_secret' => 'legacy-secret',
	)
);
$legacy_client->generate_qr( array( 'amount' => 40000 ) );
$legacy_body = json_decode( $monapay_mock_auth_calls[0]['args']['body'], true );
monapay_oauth_assert( 'https://api.monapay.vn/api/v1/client/login' === $monapay_mock_auth_calls[0]['url'], 'client_id rỗng dùng fallback đăng nhập 0.1.0' );
monapay_oauth_assert( array( 'username' => 'legacy@example.com', 'password' => 'legacy-password' ) === $legacy_body, 'fallback giữ đúng username/password cũ' );

monapay_reset_mocks();
$threw = false;
try {
	$invalid_client = new MonaPay_API( array( 'client_secret' => 'secret-only' ) );
	$invalid_client->generate_qr( array() );
} catch ( Exception $exception ) {
	$threw = true;
}
monapay_oauth_assert( $threw && 0 === count( $monapay_mock_auth_calls ), 'cấu hình thiếu bị chặn trước HTTP' );

echo "\n{$tests} tests, {$failures} failures\n";
exit( $failures > 0 ? 1 : 0 );
