<?php
/**
 * MONA Pay API client backed by the WordPress HTTP API.
 *
 * @package WooCommerce_MonaPay
 */

defined( 'ABSPATH' ) || exit;

class MonaPay_API {
	/** @var string */
	private $base_url;

	/** @var string */
	private $username;

	/** @var string */
	private $password;

	/** @var string */
	private $client_secret;

	/**
	 * Constructor.
	 *
	 * @param array $settings Gateway API settings.
	 */
	public function __construct( $settings ) {
		$base_url           = isset( $settings['base_url'] ) ? (string) $settings['base_url'] : 'https://api.monapay.vn';
		$this->base_url     = untrailingslashit( preg_replace( '#/api/v1/?$#i', '', $base_url ) );
		$this->username     = isset( $settings['username'] ) ? (string) $settings['username'] : '';
		$this->password     = isset( $settings['password'] ) ? (string) $settings['password'] : '';
		$this->client_secret = isset( $settings['client_secret'] ) ? (string) $settings['client_secret'] : '';
	}

	/**
	 * Generate a dynamic VietQR payment.
	 *
	 * @param array $payload QR API payload.
	 * @return array
	 * @throws Exception When MONA Pay rejects the request.
	 */
	public function generate_qr( $payload ) {
		return $this->request( 'POST', '/api/v1/acb/qr-payment/generate', $payload );
	}

	/**
	 * Ask MONA Pay to send a signed dummy webhook to this store.
	 *
	 * @param string $webhook_url Public store webhook URL.
	 * @param string $hmac_secret Webhook signing secret.
	 * @return array
	 * @throws Exception When MONA Pay rejects the request.
	 */
	public function test_webhook( $webhook_url, $hmac_secret ) {
		return $this->request(
			'POST',
			'/api/v1/client-webhooks/test',
			array(
				'webhook_url'   => $webhook_url,
				'auth_type'     => 'HMAC_SHA256',
				'secret_key'    => $hmac_secret,
				'payload_format' => 'application/json',
				'is_dummy'      => true,
			)
		);
	}

	/**
	 * Perform an authenticated API request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   API path.
	 * @param array  $body   JSON request body.
	 * @param bool   $retry  Whether a single 401 token refresh is allowed.
	 * @return array
	 * @throws Exception For transport, authentication, or API errors.
	 */
	private function request( $method, $path, $body, $retry = true ) {
		$this->assert_configured();
		$token = $this->get_access_token();

		$response = wp_remote_request(
			$this->base_url . $path,
			array(
				'method'      => $method,
				'timeout'     => 20,
				'redirection' => 2,
				'sslverify'   => true,
				'headers'     => array(
					'Accept'          => 'application/json',
					'Content-Type'    => 'application/json',
					'Authorization'   => 'Bearer ' . $token,
					'X-Client-Secret' => $this->client_secret,
				),
				'body'        => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( sprintf( 'Không thể kết nối MONA Pay: %s', $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $status && $retry ) {
			delete_transient( $this->token_cache_key() );
			return $this->request( $method, $path, $body, false );
		}

		if ( $status < 200 || $status >= 300 || ! is_array( $json ) || empty( $json['success'] ) ) {
			throw new Exception( $this->response_error_message( $json, $status ) );
		}

		return isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : array();
	}

	/**
	 * Return a cached token or log in for a fresh one.
	 *
	 * @return string
	 * @throws Exception When login fails.
	 */
	private function get_access_token() {
		$cache_key = $this->token_cache_key();
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			$this->base_url . '/api/v1/client/login',
			array(
				'timeout'     => 20,
				'redirection' => 2,
				'sslverify'   => true,
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'        => wp_json_encode(
					array(
						'username' => $this->username,
						'password' => $this->password,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( sprintf( 'Không thể đăng nhập MONA Pay: %s', $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( wp_remote_retrieve_body( $response ), true );
		$token  = is_array( $json ) && isset( $json['data']['access_token'] ) ? (string) $json['data']['access_token'] : '';

		if ( $status < 200 || $status >= 300 || empty( $json['success'] ) || '' === $token ) {
			throw new Exception( $this->response_error_message( $json, $status ) );
		}

		$expires_in = isset( $json['data']['expires_in'] ) ? (int) $json['data']['expires_in'] : DAY_IN_SECONDS;
		$ttl        = max( 60, min( DAY_IN_SECONDS - 300, $expires_in - 300 ) );
		set_transient( $cache_key, $token, $ttl );

		return $token;
	}

	/**
	 * Ensure all credentials required by write endpoints are available.
	 *
	 * @throws Exception When gateway credentials are incomplete.
	 */
	private function assert_configured() {
		if ( ! wp_http_validate_url( $this->base_url ) || '' === $this->username || '' === $this->password || '' === $this->client_secret ) {
			throw new Exception( 'Cấu hình API MONA Pay chưa đầy đủ.' );
		}
	}

	/**
	 * Build a site-local transient key without placing credentials in the key.
	 *
	 * @return string
	 */
	private function token_cache_key() {
		return 'monapay_token_' . substr( hash( 'sha256', $this->base_url . '|' . $this->username ), 0, 32 );
	}

	/**
	 * Extract a safe, useful message from a MONA Pay error envelope.
	 *
	 * @param mixed $json   Parsed response.
	 * @param int   $status HTTP status.
	 * @return string
	 */
	private function response_error_message( $json, $status ) {
		if ( is_array( $json ) && isset( $json['message'] ) && is_string( $json['message'] ) && '' !== $json['message'] ) {
			return sprintf( 'MONA Pay (%d): %s', $status, sanitize_text_field( $json['message'] ) );
		}

		if ( is_array( $json ) && isset( $json['detail'] ) && is_string( $json['detail'] ) ) {
			return sprintf( 'MONA Pay (%d): %s', $status, sanitize_text_field( $json['detail'] ) );
		}

		return sprintf( 'MONA Pay trả về lỗi HTTP %d.', $status );
	}
}

