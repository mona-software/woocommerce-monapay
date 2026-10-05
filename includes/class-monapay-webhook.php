<?php
/**
 * Signed MONA Pay webhook receiver.
 *
 * @package MonaPay_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MonaPay_Webhook {
	/** Register the REST route. */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/** Register POST /wp-json/monapay/v1/webhook. */
	public function register_route() {
		register_rest_route(
			'monapay/v1',
			'/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				// Public by design: the caller is MONA Pay's server, which has no WordPress session.
				// Authentication is the HMAC-SHA256 signature checked in handle() before anything is read.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Verify and apply an incoming transaction or hosted-checkout event.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle( $request ) {
		$raw_body = $request->get_body();
		if ( strlen( $raw_body ) > 65536 ) {
			return $this->response( 413, false, 'Payload too large.' );
		}
		$timestamp = (string) $request->get_header( 'x-mona-timestamp' );
		$signature = (string) $request->get_header( 'x-mona-signature' );
		$settings  = get_option( 'woocommerce_monapay_vietqr_settings', array() );
		$secret    = isset( $settings['webhook_secret'] ) ? (string) $settings['webhook_secret'] : '';

		if ( '' === $secret ) {
			$this->log( 'error', 'Webhook rejected because no HMAC secret is configured.' );
			return $this->response( 503, false, 'Webhook is not configured.' );
		}

		if ( ! monapay_verify_signature( $raw_body, $timestamp, $signature, $secret ) ) {
			$this->log( 'warning', 'Webhook has an invalid timestamp or signature.' );
			return $this->response( 401, false, 'Invalid signature.' );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			$this->log( 'warning', 'Webhook body is not valid JSON.' );
			return $this->response( 400, false, 'Invalid payload.' );
		}

		$transaction_code = isset( $payload['transaction_code'] ) ? sanitize_text_field( (string) $payload['transaction_code'] ) : '';
		if ( 'DUMMY123' === $transaction_code ) {
			$this->log( 'info', 'MONA Pay test webhook verified.' );
			return $this->response( 200, true, 'Test webhook is valid.' );
		}

		$event = isset( $payload['event'] ) ? (string) $payload['event'] : ( isset( $payload['event_type'] ) ? (string) $payload['event_type'] : '' );
		if ( 'CHECKOUT_PAID' === $event ) {
			return $this->handle_checkout_paid( $payload, $settings );
		}
		if ( '' !== $event && 'TRANSACTION_IN' !== $event ) {
			$this->log( 'warning', 'Webhook has an unsupported event type.', array( 'event' => sanitize_text_field( $event ) ) );
			return $this->response( 400, false, 'Unsupported event.' );
		}

		return $this->handle_transaction_in( $payload, $settings );
	}

	/** Apply a CHECKOUT_PAID event by its exact DH order code. */
	private function handle_checkout_paid( $payload, $settings ) {
		if ( ! isset( $payload['order_code'], $payload['transaction_code'], $payload['paid_amount'] ) || ! is_numeric( $payload['paid_amount'] ) ) {
			$this->log( 'warning', 'CHECKOUT_PAID webhook is missing required fields.' );
			return $this->response( 400, false, 'Invalid CHECKOUT_PAID payload.' );
		}

		if ( isset( $payload['status'] ) && 'paid' !== (string) $payload['status'] ) {
			$this->log( 'warning', 'CHECKOUT_PAID webhook has an invalid status.' );
			return $this->response( 400, false, 'Invalid checkout status.' );
		}

		$transaction_code = sanitize_text_field( (string) $payload['transaction_code'] );
		$order_code       = sanitize_text_field( (string) $payload['order_code'] );
		$checkout_id      = isset( $payload['checkout_id'] ) ? sanitize_text_field( (string) $payload['checkout_id'] ) : '';
		if ( '' === $transaction_code ) {
			return $this->response( 400, false, 'Invalid transaction code.' );
		}

		$order = $this->find_checkout_order( $order_code, $checkout_id );
		if ( ! $order ) {
			$this->log(
				'warning',
				'No order matches the CHECKOUT_PAID event.',
				array(
					'order_code'  => $order_code,
					'checkout_id' => $checkout_id,
				)
			);
			return $this->response( 200, true, 'CHECKOUT_PAID received; no matching order was found.' );
		}

		$result = monapay_complete_order_payment(
			$order,
			$transaction_code,
			$payload['paid_amount'],
			isset( $settings['autocomplete_orders'] ) && 'yes' === $settings['autocomplete_orders']
		);
		return $this->payment_result_response( $result, $order, $transaction_code, 'CHECKOUT_PAID' );
	}

	/** Apply the original flat TRANSACTION_IN payload. */
	private function handle_transaction_in( $payload, $settings ) {
		if ( ! isset( $payload['amount'], $payload['description'], $payload['transaction_code'], $payload['account_number'] ) ) {
			$this->log( 'warning', 'TRANSACTION_IN webhook is missing required fields.' );
			return $this->response( 400, false, 'Invalid payload.' );
		}

		$transaction_code = sanitize_text_field( (string) $payload['transaction_code'] );
		if ( '' === $transaction_code || ( isset( $payload['type'] ) && 'income' !== $payload['type'] ) || ! is_numeric( $payload['amount'] ) ) {
			$this->log( 'warning', 'Webhook is not a valid incoming transaction.' );
			return $this->response( 400, false, 'Invalid transaction.' );
		}

		$order = $this->find_transaction_order( $payload );
		if ( ! $order ) {
			$this->log( 'warning', 'No order matches the webhook.', array( 'transaction_code' => $transaction_code ) );
			return $this->response( 200, true, 'Webhook received; no matching order was found.' );
		}

		$result = monapay_complete_order_payment(
			$order,
			$transaction_code,
			$payload['amount'],
			isset( $settings['autocomplete_orders'] ) && 'yes' === $settings['autocomplete_orders']
		);
		return $this->payment_result_response( $result, $order, $transaction_code, 'TRANSACTION_IN' );
	}

	/** Convert the shared payment result to logging and an HTTP response. */
	private function payment_result_response( $result, $order, $transaction_code, $event ) {
		$context = array(
			'order_id'         => $order->get_id(),
			'transaction_code' => $transaction_code,
			'event'            => $event,
		);
		if ( 'duplicate' === $result ) {
			$this->log( 'info', 'Duplicate webhook ignored.', $context );
			return $this->response( 200, true, 'Transaction was already processed.' );
		}
		if ( 'underpaid' === $result ) {
			$this->log( 'warning', 'Webhook amount is lower than the order total.', $context );
			return $this->response( 200, true, 'Webhook received; the amount is not enough.' );
		}
		if ( 'completed' === $result ) {
			$this->log( 'info', 'Order payment confirmed.', $context );
			return $this->response( 200, true, 'Payment confirmed.' );
		}

		$this->log( 'warning', 'Webhook has invalid payment data.', $context );
		return $this->response( 400, false, 'Invalid transaction.' );
	}

	/** Find a redirect order by its exact DH{id} and optional checkout UUID. */
	private function find_checkout_order( $order_code, $checkout_id ) {
		if ( ! preg_match( '/^DH([1-9][0-9]*)$/', $order_code, $matches ) ) {
			return false;
		}

		$order = wc_get_order( (int) $matches[1] );
		if ( ! $order || 'monapay_vietqr' !== $order->get_payment_method() ) {
			return false;
		}

		$stored_checkout_id = (string) $order->get_meta( '_monapay_checkout_id', true );
		if ( '' === $stored_checkout_id ) {
			return false;
		}
		if ( '' !== $checkout_id && ! hash_equals( $stored_checkout_id, $checkout_id ) ) {
			return false;
		}

		return $order;
	}

	/** Find a MONA Pay order by DH{id} first, then by its generated VA number. */
	private function find_transaction_order( $payload ) {
		$order_id = monapay_parse_order_id( (string) $payload['description'] );
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order && 'monapay_vietqr' === $order->get_payment_method() ) {
				return $order;
			}
		}

		$account_number = sanitize_text_field( (string) $payload['account_number'] );
		if ( '' === $account_number ) {
			return false;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'objects',
				'type'       => 'shop_order',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed by a single exact value and only used as a fallback.
					array(
						'key'     => '_monapay_virtual_account_number',
						'value'   => $account_number,
						'compare' => '=',
					),
				),
			)
		);

		if ( empty( $orders ) || 'monapay_vietqr' !== $orders[0]->get_payment_method() ) {
			return false;
		}

		return $orders[0];
	}

	/** Build a consistent response envelope. */
	private function response( $status, $success, $message ) {
		return new WP_REST_Response(
			array(
				'success' => (bool) $success,
				'message' => $message,
				'data'    => null,
			),
			$status
		);
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
