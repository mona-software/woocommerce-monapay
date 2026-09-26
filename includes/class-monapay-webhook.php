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
		$raw_body  = $request->get_body();
		$timestamp = (string) $request->get_header( 'x-mona-timestamp' );
		$signature = (string) $request->get_header( 'x-mona-signature' );
		$settings  = get_option( 'woocommerce_monapay_vietqr_settings', array() );
		$secret    = isset( $settings['webhook_secret'] ) ? (string) $settings['webhook_secret'] : '';

		if ( '' === $secret ) {
			$this->log( 'error', 'Webhook bị từ chối vì chưa cấu hình HMAC secret.' );
			return $this->response( 503, false, 'Webhook chưa được cấu hình.' );
		}

		if ( ! monapay_verify_signature( $raw_body, $timestamp, $signature, $secret ) ) {
			$this->log( 'warning', 'Webhook có timestamp hoặc chữ ký không hợp lệ.' );
			return $this->response( 401, false, 'Chữ ký không hợp lệ.' );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			$this->log( 'warning', 'Webhook có JSON không hợp lệ.' );
			return $this->response( 400, false, 'Payload không hợp lệ.' );
		}

		$transaction_code = isset( $payload['transaction_code'] ) ? sanitize_text_field( (string) $payload['transaction_code'] ) : '';
		if ( 'DUMMY123' === $transaction_code ) {
			$this->log( 'info', 'Đã xác minh webhook thử MONA Pay.' );
			return $this->response( 200, true, 'Webhook thử hợp lệ.' );
		}

		$event = isset( $payload['event'] ) ? (string) $payload['event'] : ( isset( $payload['event_type'] ) ? (string) $payload['event_type'] : '' );
		if ( 'CHECKOUT_PAID' === $event ) {
			return $this->handle_checkout_paid( $payload, $settings );
		}
		if ( '' !== $event && 'TRANSACTION_IN' !== $event ) {
			$this->log( 'warning', 'Webhook có loại sự kiện không được hỗ trợ.', array( 'event' => sanitize_text_field( $event ) ) );
			return $this->response( 400, false, 'Sự kiện không được hỗ trợ.' );
		}

		return $this->handle_transaction_in( $payload, $settings );
	}

	/** Apply a CHECKOUT_PAID event by its exact DH order code. */
	private function handle_checkout_paid( $payload, $settings ) {
		if ( ! isset( $payload['order_code'], $payload['transaction_code'], $payload['paid_amount'] ) || ! is_numeric( $payload['paid_amount'] ) ) {
			$this->log( 'warning', 'Webhook CHECKOUT_PAID thiếu trường bắt buộc.' );
			return $this->response( 400, false, 'Payload CHECKOUT_PAID không hợp lệ.' );
		}

		if ( isset( $payload['status'] ) && 'paid' !== (string) $payload['status'] ) {
			$this->log( 'warning', 'Webhook CHECKOUT_PAID có trạng thái không hợp lệ.' );
			return $this->response( 400, false, 'Trạng thái checkout không hợp lệ.' );
		}

		$transaction_code = sanitize_text_field( (string) $payload['transaction_code'] );
		$order_code       = sanitize_text_field( (string) $payload['order_code'] );
		$checkout_id      = isset( $payload['checkout_id'] ) ? sanitize_text_field( (string) $payload['checkout_id'] ) : '';
		if ( '' === $transaction_code ) {
			return $this->response( 400, false, 'Mã giao dịch không hợp lệ.' );
		}

		$order = $this->find_checkout_order( $order_code, $checkout_id );
		if ( ! $order ) {
			$this->log( 'warning', 'Không tìm thấy đơn khớp CHECKOUT_PAID.', array( 'order_code' => $order_code, 'checkout_id' => $checkout_id ) );
			return $this->response( 200, true, 'Đã nhận CHECKOUT_PAID; không tìm thấy đơn khớp.' );
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
			$this->log( 'warning', 'Webhook TRANSACTION_IN thiếu trường bắt buộc.' );
			return $this->response( 400, false, 'Payload không hợp lệ.' );
		}

		$transaction_code = sanitize_text_field( (string) $payload['transaction_code'] );
		if ( '' === $transaction_code || ( isset( $payload['type'] ) && 'income' !== $payload['type'] ) || ! is_numeric( $payload['amount'] ) ) {
			$this->log( 'warning', 'Webhook không phải giao dịch tiền vào hợp lệ.' );
			return $this->response( 400, false, 'Giao dịch không hợp lệ.' );
		}

		$order = $this->find_transaction_order( $payload );
		if ( ! $order ) {
			$this->log( 'warning', 'Không tìm thấy đơn khớp webhook.', array( 'transaction_code' => $transaction_code ) );
			return $this->response( 200, true, 'Đã nhận webhook; không tìm thấy đơn khớp.' );
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
			$this->log( 'info', 'Bỏ qua webhook trùng.', $context );
			return $this->response( 200, true, 'Giao dịch đã được xử lý.' );
		}
		if ( 'underpaid' === $result ) {
			$this->log( 'warning', 'Webhook có số tiền thấp hơn tổng đơn.', $context );
			return $this->response( 200, true, 'Đã nhận webhook; số tiền chưa đủ.' );
		}
		if ( 'completed' === $result ) {
			$this->log( 'info', 'Đã xác nhận thanh toán đơn hàng.', $context );
			return $this->response( 200, true, 'Đã xác nhận thanh toán.' );
		}

		$this->log( 'warning', 'Webhook có dữ liệu thanh toán không hợp lệ.', $context );
		return $this->response( 400, false, 'Giao dịch không hợp lệ.' );
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
