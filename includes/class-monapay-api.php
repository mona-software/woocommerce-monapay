<?php
/**
 * MONA Pay API client backed by the WordPress HTTP API.
 *
 * @package MonaPay_WooCommerce
 */

defined( 'ABSPATH' ) || defined( 'MONAPAY_TESTING' ) || exit;

class MonaPay_API {
	/** @var string */
	private $base_url;

	/** @var string */
	private $client_id;

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
		$base_url            = isset( $settings['base_url'] ) ? (string) $settings['base_url'] : 'https://api.monapay.vn';
		$this->base_url      = untrailingslashit( preg_replace( '#/api/v1/?$#i', '', $base_url ) );
		$this->client_id     = isset( $settings['client_id'] ) ? (string) $settings['client_id'] : '';
		$this->username      = isset( $settings['username'] ) ? (string) $settings['username'] : '';
		$this->password      = isset( $settings['password'] ) ? (string) $settings['password'] : '';
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
	 * Create a fake incoming transaction for a configured virtual account.
	 *
	 * @param string $virtual_account_number Full virtual account number.
	 * @param int    $amount                 Test amount in VND.
	 * @param string $description            Transfer description.
	 * @return array
	 * @throws Exception When MONA Pay rejects the request.
	 */
	public function create_sandbox_transaction( $virtual_account_number, $amount = 10000, $description = 'WooCommerce sandbox test' ) {
		return $this->request(
			'POST',
			'/api/v1/sandbox/transactions',
			array(
				'virtual_account_number' => (string) $virtual_account_number,
				'amount'                 => (int) $amount,
				'description'            => (string) $description,
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
			throw new Exception(
				sprintf(
					/* translators: %s: connection error detail. */
					__( 'Không thể kết nối MONA Pay: %s', 'woocommerce-monapay' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $status && $retry ) {
			delete_transient( $this->token_cache_key() );
			return $this->request( $method, $path, $body, false );
		}

		if ( $status < 200 || $status >= 300 || ! is_array( $json ) || ( isset( $json['success'] ) && false === $json['success'] ) ) {
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

		$using_client_credentials = '' !== $this->client_id;
		$path                     = $using_client_credentials ? '/api/v1/oauth/token' : '/api/v1/client/login';
		$body                     = $using_client_credentials
			? array(
				'grant_type'    => 'client_credentials',
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
			)
			: array(
				'username' => $this->username,
				'password' => $this->password,
			);

		$response = wp_remote_post(
			$this->base_url . $path,
			array(
				'timeout'     => 20,
				'redirection' => 2,
				'sslverify'   => true,
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'        => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception(
				sprintf(
					/* translators: %s: authentication connection error detail. */
					__( 'Không thể xác thực MONA Pay: %s', 'woocommerce-monapay' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( wp_remote_retrieve_body( $response ), true );
		$token  = is_array( $json ) && isset( $json['data']['access_token'] ) ? (string) $json['data']['access_token'] : '';

		if ( $status < 200 || $status >= 300 || ( isset( $json['success'] ) && false === $json['success'] ) || '' === $token ) {
			throw new Exception( $this->response_error_message( $json, $status ) );
		}

		$default_expires = $using_client_credentials ? HOUR_IN_SECONDS : DAY_IN_SECONDS;
		$expires_in      = isset( $json['data']['expires_in'] ) ? max( 1, (int) $json['data']['expires_in'] ) : $default_expires;
		$ttl             = max( 1, min( DAY_IN_SECONDS, $expires_in - min( 60, (int) floor( $expires_in / 10 ) ) ) );
		set_transient( $cache_key, $token, $ttl );

		return $token;
	}

	/**
	 * Ensure all credentials required by write endpoints are available.
	 *
	 * @throws Exception When gateway credentials are incomplete.
	 */
	private function assert_configured() {
		$has_client_credentials = '' !== $this->client_id && '' !== $this->client_secret;
		$has_legacy_credentials = '' === $this->client_id && '' !== $this->username && '' !== $this->password && '' !== $this->client_secret;

		if ( ! wp_http_validate_url( $this->base_url ) || ( ! $has_client_credentials && ! $has_legacy_credentials ) ) {
			throw new Exception( __( 'Cấu hình API MONA Pay chưa đầy đủ.', 'woocommerce-monapay' ) );
		}
	}

	/**
	 * Build a site-local transient key without placing credentials in the key.
	 *
	 * @return string
	 */
	private function token_cache_key() {
		$identity = '' !== $this->client_id ? 'oauth|' . $this->client_id : 'legacy|' . $this->username;
		return 'monapay_token_' . substr( hash( 'sha256', $this->base_url . '|' . $identity ), 0, 32 );
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
			return sprintf(
				/* translators: 1: HTTP status code, 2: API error detail. */
				__( 'MONA Pay (%1$d): %2$s', 'woocommerce-monapay' ),
				$status,
				sanitize_text_field( $json['message'] )
			);
		}

		if ( is_array( $json ) && isset( $json['detail'] ) && is_string( $json['detail'] ) ) {
			return sprintf(
				/* translators: 1: HTTP status code, 2: API error detail. */
				__( 'MONA Pay (%1$d): %2$s', 'woocommerce-monapay' ),
				$status,
				sanitize_text_field( $json['detail'] )
			);
		}

		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'MONA Pay trả về lỗi HTTP %d.', 'woocommerce-monapay' ),
			$status
		);
	}
}
