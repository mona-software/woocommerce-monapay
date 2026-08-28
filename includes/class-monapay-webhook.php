<?php
/**
 * Signed MONA Pay webhook receiver.
 *
 * @package WooCommerce_MonaPay
 */

defined( 'ABSPATH' ) || exit;

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
	 * Verify and apply an incoming bank transaction.
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
		if ( ! is_array( $payload ) || ! isset( $payload['amount'], $payload['description'], $payload['transaction_code'], $payload['account_number'] ) ) {
			$this->log( 'warning', 'Webhook có JSON hoặc trường bắt buộc không hợp lệ.' );
			return $this->response( 400, false, 'Payload không hợp lệ.' );
		}

		$transaction_code = sanitize_text_field( (string) $payload['transaction_code'] );
		if ( 'DUMMY123' === $transaction_code ) {
			$this->log( 'info', 'Đã xác minh webhook thử MONA Pay.' );
			return $this->response( 200, true, 'Webhook thử hợp lệ.' );
		}

		if ( '' === $transaction_code || ( isset( $payload['type'] ) && 'income' !== $payload['type'] ) || ! is_numeric( $payload['amount'] ) ) {
			$this->log( 'warning', 'Webhook không phải giao dịch tiền vào hợp lệ.' );
			return $this->response( 400, false, 'Giao dịch không hợp lệ.' );
		}

		$order = $this->find_order( $payload );
		if ( ! $order ) {
			$this->log(
				'warning',
				'Không tìm thấy đơn khớp webhook.',
				array( 'transaction_code' => $transaction_code )
			);
			return $this->response( 200, true, 'Đã nhận webhook; không tìm thấy đơn khớp.' );
		}

		$transaction_codes = $order->get_meta( '_monapay_txn_codes', true );
		$transaction_codes = is_array( $transaction_codes ) ? array_map( 'strval', $transaction_codes ) : array();
		if ( in_array( $transaction_code, $transaction_codes, true ) || $transaction_code === (string) $order->get_transaction_id() ) {
			$this->log(
				'info',
				'Bỏ qua webhook trùng.',
				array(
					'order_id'         => $order->get_id(),
					'transaction_code' => $transaction_code,
				)
			);
			return $this->response( 200, true, 'Giao dịch đã được xử lý.' );
		}

		$paid_amount = (int) round( (float) $payload['amount'] );
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
			$this->log(
				'warning',
				'Webhook có số tiền thấp hơn tổng đơn.',
				array(
					'order_id'         => $order->get_id(),
					'transaction_code' => $transaction_code,
				)
			);
			return $this->response( 200, true, 'Đã nhận webhook; số tiền chưa đủ.' );
		}

		$transaction_codes[] = $transaction_code;
		$order->update_meta_data( '_monapay_txn_codes', array_values( array_unique( $transaction_codes ) ) );
		$order->save();
		$order->payment_complete( $transaction_code );
		$order->add_order_note(
			sprintf(
				/* translators: %s: bank transaction code. */
				__( 'MONA Pay đã tự động xác nhận thanh toán. Mã giao dịch: %s.', 'woocommerce-monapay' ),
				$transaction_code
			)
		);

		if ( isset( $settings['autocomplete_orders'] ) && 'yes' === $settings['autocomplete_orders'] && ! $order->has_status( 'completed' ) ) {
			$order->update_status( 'completed', __( 'MONA Pay tự động hoàn tất đơn theo cấu hình.', 'woocommerce-monapay' ) );
		}

		$this->log(
			'info',
			'Đã xác nhận thanh toán đơn hàng.',
			array(
				'order_id'         => $order->get_id(),
				'transaction_code' => $transaction_code,
			)
		);

		return $this->response( 200, true, 'Đã xác nhận thanh toán.' );
	}

	/**
	 * Find a MONA Pay order by DH{id} first, then by its generated VA number.
	 *
	 * @param array $payload Validated webhook payload.
	 * @return WC_Order|false
	 */
	private function find_order( $payload ) {
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
		$context['source'] = 'woocommerce-monapay';
		wc_get_logger()->log( $level, $message, $context );
	}
}

