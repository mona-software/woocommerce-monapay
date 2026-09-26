<?php
/**
 * WooCommerce VietQR payment gateway powered by MONA Pay.
 *
 * @package MonaPay_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MonaPay_Gateway extends WC_Payment_Gateway {
	/** Constructor. */
	public function __construct() {
		$this->id                 = 'monapay_vietqr';
		$this->method_title       = __( 'MONA Pay VietQR', 'mona-pay-for-woocommerce' );
		$this->method_description = __( 'Chuyển khách sang trang MONA Pay hoặc hiện VietQR tại cửa hàng, sau đó tự xác nhận bằng webhook HMAC.', 'mona-pay-for-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Chuyển khoản VietQR (tự xác nhận)', 'mona-pay-for-woocommerce' ) );
		$this->description = $this->get_option( 'description', __( 'Quét mã VietQR để chuyển khoản. Đơn hàng được xác nhận tự động khi MONA Pay nhận giao dịch.', 'mona-pay-for-woocommerce' ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_instructions' ), 20, 4 );
		add_action( 'woocommerce_view_order', array( $this, 'view_order_instructions' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( $this, 'admin_sandbox_notice' ) );
		add_filter( 'woocommerce_order_actions', array( $this, 'add_sandbox_order_action' ), 10, 2 );
		add_action( 'woocommerce_order_action_monapay_create_sandbox_transaction', array( $this, 'create_order_sandbox_transaction' ) );
	}

	/** Define settings shown under WooCommerce > Payments. */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'                => array(
				'title'   => __( 'Bật/tắt', 'mona-pay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Bật thanh toán MONA Pay VietQR', 'mona-pay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'                  => array(
				'title'       => __( 'Tiêu đề', 'mona-pay-for-woocommerce' ),
				'type'        => 'text',
				'default'     => __( 'Chuyển khoản VietQR (tự xác nhận)', 'mona-pay-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'Tên phương thức khách thấy tại trang thanh toán.', 'mona-pay-for-woocommerce' ),
			),
			'description'            => array(
				'title'       => __( 'Mô tả', 'mona-pay-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => __( 'Quét mã VietQR để chuyển khoản. Đơn hàng được xác nhận tự động khi MONA Pay nhận giao dịch.', 'mona-pay-for-woocommerce' ),
				'description' => __( 'Nội dung hiển thị bên dưới phương thức thanh toán.', 'mona-pay-for-woocommerce' ),
			),
			'payment_mode'           => array(
				'title'       => __( 'Cách thanh toán', 'mona-pay-for-woocommerce' ),
				'type'        => 'select',
				'default'     => 'redirect',
				'description' => __( 'Chọn nơi quý khách xem mã QR và thực hiện thanh toán.', 'mona-pay-for-woocommerce' ),
				'options'     => array(
					'redirect' => __( 'Chuyển sang trang thanh toán MONA Pay', 'mona-pay-for-woocommerce' ),
					'inline'   => __( 'Hiện QR tại cửa hàng', 'mona-pay-for-woocommerce' ),
				),
			),
			'sandbox_mode'           => array(
				'title'       => __( 'Chế độ thử (sandbox)', 'mona-pay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Không chuyển tiền thật, dùng khi chưa nối ngân hàng', 'mona-pay-for-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Phiên thanh toán thử chỉ dành cho kiểm tra tích hợp. Phải tắt chế độ sandbox trước khi bán thật.', 'mona-pay-for-woocommerce' ),
			),
			'api_heading'            => array(
				'title'       => __( 'Kết nối MONA Pay', 'mona-pay-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Vào my.monapay.vn → API Keys → Tạo key, hoặc dùng khối “Đưa cho AI” sau khi đăng ký.', 'mona-pay-for-woocommerce' ),
			),
			'base_url'               => array(
				'title'             => __( 'Base URL', 'mona-pay-for-woocommerce' ),
				'type'              => 'text',
				'default'           => 'https://api.monapay.vn',
				'description'       => __( 'URL gốc của MONA Pay API, không thêm /api/v1.', 'mona-pay-for-woocommerce' ),
				'custom_attributes' => array( 'required' => 'required' ),
			),
			'client_id'              => array(
				'title'             => __( 'Client ID', 'mona-pay-for-woocommerce' ),
				'type'              => 'text',
				'description'       => __( 'Lấy tại my.monapay.vn → API Keys → Tạo key.', 'mona-pay-for-woocommerce' ),
				'custom_attributes' => array( 'autocomplete' => 'off' ),
			),
			'client_secret'          => array(
				'title'             => __( 'Client Secret', 'mona-pay-for-woocommerce' ),
				'type'              => 'password',
				'description'       => __( 'Secret chỉ hiển thị một lần khi tạo key; plugin dùng để lấy token và gửi header X-Client-Secret.', 'mona-pay-for-woocommerce' ),
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
			),
			'return_signature_secret' => array(
				'title'             => __( 'Secret chữ ký quay về', 'mona-pay-for-woocommerce' ),
				'type'              => 'password',
				'description'       => sprintf(
					/* translators: %s: MONA Pay hosted checkout documentation URL. */
					__( 'Lấy tại MONA Pay Dashboard → Cài đặt → Trang thanh toán. <a href="%s" target="_blank" rel="noopener noreferrer">Xem hướng dẫn</a>.', 'mona-pay-for-woocommerce' ),
					esc_url( 'https://monapay.vn/docs/api/trang-thanh-toan/' )
				),
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
			),
			'qr_heading'             => array(
				'title'       => __( 'Thông tin VietQR', 'mona-pay-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Sao chép đúng các giá trị ACB/MONA Pay đã cấp cho tài khoản nhận tiền.', 'mona-pay-for-woocommerce' ),
			),
			'virtual_account_prefix' => array(
				'title'             => __( 'Đầu số tài khoản ảo', 'mona-pay-for-woocommerce' ),
				'type'              => 'text',
				'description'       => __( 'virtualAccountPrefix, dài từ 1 đến 10 ký tự.', 'mona-pay-for-woocommerce' ),
				'custom_attributes' => array( 'maxlength' => '10' ),
			),
			'owner_number'           => array(
				'title'       => __( 'Số tài khoản nhận tiền', 'mona-pay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'ownerNumber của tài khoản ACB.', 'mona-pay-for-woocommerce' ),
			),
			'owner_type'             => array(
				'title'   => __( 'Loại chủ tài khoản', 'mona-pay-for-woocommerce' ),
				'type'    => 'select',
				'default' => 'ORG',
				'options' => array(
					'PER' => __( 'Cá nhân (PER)', 'mona-pay-for-woocommerce' ),
					'ORG' => __( 'Tổ chức (ORG)', 'mona-pay-for-woocommerce' ),
				),
			),
			'merchant_id'            => array(
				'title' => __( 'Merchant ID', 'mona-pay-for-woocommerce' ),
				'type'  => 'text',
			),
			'terminal_id'            => array(
				'title' => __( 'Terminal ID', 'mona-pay-for-woocommerce' ),
				'type'  => 'text',
			),
			'beneficiary_name'       => array(
				'title'             => __( 'Tên người thụ hưởng', 'mona-pay-for-woocommerce' ),
				'type'              => 'text',
				'description'       => __( 'beneficiaryName hiển thị trên ứng dụng ngân hàng, tối đa 100 ký tự.', 'mona-pay-for-woocommerce' ),
				'custom_attributes' => array( 'maxlength' => '100' ),
			),
			'sandbox_virtual_account' => array(
				'title'       => __( 'Số VA để thử sandbox', 'mona-pay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Số tài khoản ảo đầy đủ đã nối ACB. Lưu trường này để bật nút tạo giao dịch thử 10.000 VND.', 'mona-pay-for-woocommerce' ),
			),
			'webhook_heading'        => array(
				'title'       => __( 'Webhook tự xác nhận', 'mona-pay-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Dùng HMAC-SHA256 để chỉ chấp nhận webhook thật từ MONA Pay.', 'mona-pay-for-woocommerce' ),
			),
			'webhook_secret'         => array(
				'title'       => __( 'Secret HMAC webhook', 'mona-pay-for-woocommerce' ),
				'type'        => 'webhook_secret',
				'description' => __( 'Dùng cùng secret này khi tạo cấu hình webhook HMAC_SHA256 trên MONA Pay. Nên dài ít nhất 32 ký tự.', 'mona-pay-for-woocommerce' ),
			),
			'autocomplete_orders'    => array(
				'title'       => __( 'Tự hoàn tất đơn', 'mona-pay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Chuyển thẳng đơn sang Hoàn tất sau khi nhận đủ tiền', 'mona-pay-for-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Nếu tắt, WooCommerce chọn Đang xử lý/Hoàn tất theo loại sản phẩm sau payment_complete().', 'mona-pay-for-woocommerce' ),
			),
			'show_branding'          => array(
				'title'       => __( 'Nhận diện MONA Pay', 'mona-pay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Hiển thị dòng xác nhận tự động bởi MONA Pay trên trang đơn hàng', 'mona-pay-for-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Tuỳ chọn này mặc định TẮT; chỉ bật khi quý khách chủ động muốn ghi nhận MONA Pay trên trang đơn hàng.', 'mona-pay-for-woocommerce' ),
			),
			'webhook_tools'          => array(
				'title' => __( 'Cấu hình và kiểm tra', 'mona-pay-for-woocommerce' ),
				'type'  => 'webhook_tools',
			),
		);
	}

	/** Only offer the gateway for configured VND checkouts. */
	public function is_available() {
		if ( ! parent::is_available() || 'VND' !== get_woocommerce_currency() ) {
			return false;
		}

		$required = array( 'base_url', 'client_secret', 'webhook_secret' );
		if ( 'redirect' === $this->get_payment_mode() ) {
			$required[] = 'return_signature_secret';
		} else {
			$required = array_merge( $required, array( 'virtual_account_prefix', 'owner_number', 'owner_type', 'merchant_id', 'terminal_id', 'beneficiary_name' ) );
		}
		foreach ( $required as $key ) {
			if ( '' === trim( (string) $this->get_option( $key, '' ) ) ) {
				return false;
			}
		}

		$client_id = trim( (string) $this->get_option( 'client_id', '' ) );
		if ( '' === $client_id ) {
			$username = trim( $this->get_legacy_api_setting( 'username' ) );
			$password = trim( $this->get_legacy_api_setting( 'password' ) );
			if ( '' === $username || '' === $password ) {
				return false;
			}
		}
		return true;
	}

	/** Display checkout instructions before the order is placed. */
	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}
	}

	/**
	 * Start the configured MONA Pay payment flow.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array|null
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Không tìm thấy đơn hàng để thanh toán qua MONA Pay.', 'mona-pay-for-woocommerce' ), 'error' );
			return null;
		}

		if ( 'VND' !== $order->get_currency() ) {
			wc_add_notice( __( 'MONA Pay chỉ hỗ trợ đơn hàng VND.', 'mona-pay-for-woocommerce' ), 'error' );
			return null;
		}

		$amount = (int) round( (float) $order->get_total() );
		if ( $amount <= 0 || $amount > 1000000000 ) {
			wc_add_notice( __( 'Tổng đơn vượt giới hạn thanh toán của MONA Pay.', 'mona-pay-for-woocommerce' ), 'error' );
			return null;
		}

		if ( 'redirect' === $this->get_payment_mode() ) {
			return $this->process_redirect_payment( $order, $amount );
		}

		return $this->process_inline_payment( $order, $amount );
	}

	/** Create a hosted checkout and redirect the customer to MONA Pay. */
	private function process_redirect_payment( $order, $amount ) {
		if ( $amount < 1000 ) {
			wc_add_notice( __( 'Tổng đơn cần từ 1.000 VND để thanh toán trên MONA Pay.', 'mona-pay-for-woocommerce' ), 'error' );
			return null;
		}

		$checkout_url      = (string) $order->get_meta( '_monapay_checkout_url', true );
		$checkout_status   = (string) $order->get_meta( '_monapay_checkout_status', true );
		$current_sandbox   = $this->is_sandbox_enabled();
		$checkout_sandbox  = 'yes' === (string) $order->get_meta( '_monapay_sandbox', true );
		$sandbox_changed   = '' !== $checkout_url && $current_sandbox !== $checkout_sandbox;
		if ( '' !== $checkout_url && ! $sandbox_changed && ! in_array( $checkout_status, array( 'cancelled', 'expired' ), true ) ) {
			$order->update_meta_data( '_monapay_payment_mode', 'redirect' );
			$order->save();
			wc_reduce_stock_levels( $order->get_id() );
			$this->empty_cart();
			return array( 'result' => 'success', 'redirect' => $checkout_url );
		}

		$attempt = max( 1, (int) $order->get_meta( '_monapay_checkout_attempt', true ) );
		if ( $sandbox_changed || in_array( $checkout_status, array( 'cancelled', 'expired' ), true ) ) {
			$attempt++;
			$order->delete_meta_data( '_monapay_checkout_id' );
			$order->delete_meta_data( '_monapay_checkout_token' );
			$order->delete_meta_data( '_monapay_checkout_url' );
			$order->delete_meta_data( '_monapay_virtual_account_number' );
		}
		$order->update_meta_data( '_monapay_checkout_attempt', $attempt );
		$order->update_meta_data( '_monapay_checkout_status', 'creating' );
		$order->save();

		$payer_email = trim( (string) $order->get_billing_email() );
		$payer_name  = trim( (string) $order->get_formatted_billing_full_name() );
		$payer_name  = function_exists( 'mb_substr' ) ? mb_substr( $payer_name, 0, 255 ) : substr( $payer_name, 0, 255 );
		$return_args = array(
			'order_id' => $order->get_id(),
			'key'      => $order->get_order_key(),
		);
		$payload     = array(
			'amount'      => $amount,
			'order_code'  => 'DH' . $order->get_id(),
			'description' => 'Thanh toán đơn hàng DH' . $order->get_id(),
			'return_url'  => add_query_arg( $return_args, WC()->api_request_url( 'monapay_return' ) ),
			'cancel_url'  => add_query_arg( $return_args, wc_get_checkout_url() ),
			'metadata'    => array( 'wc_order_id' => $order->get_id() ),
		);
		if ( '' !== $payer_email ) {
			$payload['payer_email'] = $payer_email;
		}
		if ( '' !== $payer_name ) {
			$payload['payer_name'] = $payer_name;
		}
		$payload = monapay_prepare_checkout_payload( $payload, $current_sandbox ? 'yes' : 'no' );

		try {
			$api  = new MonaPay_API( $this->get_api_settings() );
			$data = $api->create_checkout( $payload, 'wc-' . $order->get_id() . '-' . $attempt );
			$created_checkout_url = isset( $data['checkout_url'] ) ? esc_url_raw( (string) $data['checkout_url'] ) : '';
			if ( empty( $data['id'] ) || empty( $data['token'] ) || ! wp_http_validate_url( $created_checkout_url ) || 0 !== strpos( $created_checkout_url, 'https://' ) ) {
				throw new Exception( 'Response tạo checkout thiếu id, token hoặc checkout_url.' );
			}

			$order->update_meta_data( '_monapay_checkout_id', sanitize_text_field( (string) $data['id'] ) );
			$order->update_meta_data( '_monapay_checkout_token', sanitize_text_field( (string) $data['token'] ) );
			$order->update_meta_data( '_monapay_checkout_url', $created_checkout_url );
			$order->update_meta_data( '_monapay_checkout_status', 'pending' );
			$order->update_meta_data( '_monapay_order_id', (string) $order->get_id() );
			$order->update_meta_data( '_monapay_payment_mode', 'redirect' );
			if ( ! empty( $data['virtual_account_number'] ) ) {
				$order->update_meta_data( '_monapay_virtual_account_number', sanitize_text_field( (string) $data['virtual_account_number'] ) );
			}
			$this->mark_order_sandbox_mode( $order, $current_sandbox );
			$order->save();

			if ( ! $order->has_status( 'pending' ) ) {
				$order->update_status( 'pending', __( 'Đã tạo trang thanh toán MONA Pay; đang chờ giao dịch.', 'mona-pay-for-woocommerce' ) );
			} else {
				$order->add_order_note( __( 'Đã tạo trang thanh toán MONA Pay; đang chờ giao dịch.', 'mona-pay-for-woocommerce' ) );
			}
			wc_reduce_stock_levels( $order->get_id() );
		} catch ( Exception $exception ) {
			$this->log( 'error', $exception->getMessage(), array( 'order_id' => $order->get_id(), 'operation' => 'create_checkout' ) );
			wc_add_notice( __( 'Chưa thể mở trang thanh toán MONA Pay. Vui lòng thử lại hoặc chọn phương thức khác.', 'mona-pay-for-woocommerce' ), 'error' );
			return null;
		}

		$this->empty_cart();
		return array(
			'result'   => 'success',
			'redirect' => $created_checkout_url,
		);
	}

	/** Generate and persist an inline VietQR, then place the order on hold. */
	private function process_inline_payment( $order, $amount ) {
		$order_id = $order->get_id();

		$qr_data = (string) $order->get_meta( '_monapay_qr_data', true );
		if ( '' === $qr_data ) {
			try {
				$api  = new MonaPay_API( $this->get_api_settings() );
				$data = $api->generate_qr(
					array(
						'ownerNumber'         => (string) $this->get_option( 'owner_number' ),
						'ownerType'           => (string) $this->get_option( 'owner_type', 'ORG' ),
						'merchantId'          => (string) $this->get_option( 'merchant_id' ),
						'terminalId'          => (string) $this->get_option( 'terminal_id' ),
						'orderId'             => (string) $order->get_id(),
						'virtualAccountPrefix' => (string) $this->get_option( 'virtual_account_prefix' ),
						'beneficiaryName'     => (string) $this->get_option( 'beneficiary_name' ),
						'amount'              => $amount,
						'description'         => 'DH' . $order->get_id(),
					)
				);

				if ( empty( $data['id'] ) || empty( $data['qr_data_url'] ) ) {
					throw new Exception( 'Response tạo QR thiếu id hoặc qr_data_url.' );
				}

				$order->update_meta_data( '_monapay_qr_code_id', sanitize_text_field( (string) $data['id'] ) );
				$order->update_meta_data( '_monapay_qr_data', sanitize_text_field( (string) $data['qr_data_url'] ) );
				$order->update_meta_data( '_monapay_order_id', (string) $order->get_id() );
				$order->update_meta_data( '_monapay_payment_mode', 'inline' );
				if ( ! empty( $data['virtual_account_number'] ) ) {
					$order->update_meta_data( '_monapay_virtual_account_number', sanitize_text_field( (string) $data['virtual_account_number'] ) );
				}
				$this->mark_order_sandbox_mode( $order, $this->is_sandbox_enabled() );
				$order->save();
				wc_reduce_stock_levels( $order_id );
			} catch ( Exception $exception ) {
				$this->log(
					'error',
					$exception->getMessage(),
					array( 'order_id' => $order_id )
				);
				wc_add_notice( __( 'Chưa thể tạo mã VietQR. Vui lòng thử lại hoặc chọn phương thức thanh toán khác.', 'mona-pay-for-woocommerce' ), 'error' );
				return null;
			}
		}

		if ( ! $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'on-hold', __( 'Đã tạo VietQR MONA Pay; đang chờ giao dịch ngân hàng.', 'mona-pay-for-woocommerce' ) );
		}

		$this->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/** Render QR instructions on the thank-you page. */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$this->render_payment_box( $order );
		}
	}

	/** Render the same instructions when the customer views an order. */
	public function view_order_instructions( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && 'monapay_vietqr' === $order->get_payment_method() ) {
			$this->render_payment_box( $order );
		}
	}

	/**
	 * Add QR instructions to customer emails.
	 *
	 * @param WC_Order $order         Order being emailed.
	 * @param bool     $sent_to_admin Whether this is an admin email.
	 * @param bool     $plain_text    Whether this is plain text.
	 * @param WC_Email $email         Email instance.
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text, $email ) {
		unset( $email );
		if ( $sent_to_admin || ! ( $order instanceof WC_Order ) || 'monapay_vietqr' !== $order->get_payment_method() || $order->is_paid() ) {
			return;
		}

		if ( $plain_text ) {
			if ( $this->is_order_sandbox( $order ) ) {
				echo "\n" . esc_html__( 'Đơn thử nghiệm (sandbox), không chuyển tiền thật', 'mona-pay-for-woocommerce' ) . "\n";
			}
			if ( 'redirect' === $this->get_order_payment_mode( $order ) ) {
				echo "\n" . esc_html__( 'THANH TOÁN MONA PAY', 'mona-pay-for-woocommerce' ) . "\n";
				echo esc_html__( 'Mở trang thanh toán:', 'mona-pay-for-woocommerce' ) . ' ' . esc_url_raw( (string) $order->get_meta( '_monapay_checkout_url', true ) ) . "\n\n";
				return;
			}
			$account = (string) $order->get_meta( '_monapay_virtual_account_number', true );
			echo "\n" . esc_html__( 'THANH TOÁN VIETQR MONA PAY', 'mona-pay-for-woocommerce' ) . "\n";
			echo esc_html__( 'Nội dung chuyển khoản:', 'mona-pay-for-woocommerce' ) . ' DH' . esc_html( (string) $order->get_id() ) . "\n";
			if ( '' !== $account ) {
				echo esc_html__( 'Số tài khoản ảo:', 'mona-pay-for-woocommerce' ) . ' ' . esc_html( $account ) . "\n";
			}
			echo esc_html__( 'Mở ảnh QR:', 'mona-pay-for-woocommerce' ) . ' ' . esc_url_raw( MonaPay_QR_Endpoint::get_url( $order ) ) . "\n\n";
			return;
		}

		$this->render_payment_box( $order, true );
	}

	/** Render the shared payment panel. */
	private function render_payment_box( $order, $email = false ) {
		if ( 'monapay_vietqr' !== $order->get_payment_method() ) {
			return;
		}
		if ( 'redirect' === $this->get_order_payment_mode( $order ) ) {
			$this->render_redirect_payment_box( $order, $email );
			return;
		}
		if ( '' === (string) $order->get_meta( '_monapay_qr_data', true ) ) {
			return;
		}

		$account = (string) $order->get_meta( '_monapay_virtual_account_number', true );
		$qr_url  = MonaPay_QR_Endpoint::get_url( $order );
		$style   = $email ? 'border:1px solid #e5e7eb;padding:20px;margin:20px 0;text-align:center;' : 'border:1px solid #e5e7eb;border-radius:8px;padding:24px;margin:24px 0;text-align:center;';
		?>
		<section class="woocommerce-monapay-payment" style="<?php echo esc_attr( $style ); ?>">
			<h2><?php esc_html_e( 'Quét VietQR để thanh toán', 'mona-pay-for-woocommerce' ); ?></h2>
			<?php $this->render_sandbox_warning( $order ); ?>
			<p><?php esc_html_e( 'Quý khách vui lòng kiểm tra thông tin thanh toán trước khi thực hiện giao dịch.', 'mona-pay-for-woocommerce' ); ?></p>
			<p style="font-size:24px;font-weight:700;margin:12px 0;"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></p>
			<p><img src="<?php echo esc_url( $qr_url ); ?>" width="320" height="320" alt="<?php esc_attr_e( 'Mã VietQR thanh toán đơn hàng', 'mona-pay-for-woocommerce' ); ?>" style="display:block;max-width:100%;height:auto;margin:16px auto;" /></p>
			<?php if ( ! $email && 'yes' === $this->get_option( 'show_branding', 'no' ) ) : ?>
				<p style="font-size:12px;margin:-6px 0 16px;color:#6b7280;">
					<a href="<?php echo esc_url( 'https://monapay.vn?utm_source=woocommerce' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Xác nhận thanh toán tự động bởi MONA Pay', 'mona-pay-for-woocommerce' ); ?></a>
				</p>
			<?php endif; ?>
			<p>
				<?php if ( '' !== $account ) : ?>
					<strong><?php esc_html_e( 'Số tài khoản ảo:', 'mona-pay-for-woocommerce' ); ?></strong> <?php echo esc_html( $account ); ?><br />
				<?php endif; ?>
				<strong><?php esc_html_e( 'Người thụ hưởng:', 'mona-pay-for-woocommerce' ); ?></strong> <?php echo esc_html( (string) $this->get_option( 'beneficiary_name' ) ); ?><br />
				<strong><?php esc_html_e( 'Nội dung chuyển khoản:', 'mona-pay-for-woocommerce' ); ?></strong> <?php echo esc_html( 'DH' . $order->get_id() ); ?>
			</p>
			<p><?php esc_html_e( 'Đơn hàng sẽ được xác nhận tự động sau khi ngân hàng ghi nhận giao dịch.', 'mona-pay-for-woocommerce' ); ?></p>
		</section>
		<?php
	}

	/** Render hosted-checkout status and the reopen button. */
	private function render_redirect_payment_box( $order, $email = false ) {
		$checkout_url    = (string) $order->get_meta( '_monapay_checkout_url', true );
		$checkout_status = (string) $order->get_meta( '_monapay_checkout_status', true );
		$is_pending      = ! $order->is_paid();
		$status_text     = $order->is_paid() ? __( 'Đã thanh toán', 'mona-pay-for-woocommerce' ) : __( 'Đang chờ thanh toán', 'mona-pay-for-woocommerce' );
		if ( $is_pending && 'cancelled' === $checkout_status ) {
			$status_text = __( 'Chưa thanh toán', 'mona-pay-for-woocommerce' );
		}
		$style = $email ? 'border:1px solid #e5e7eb;padding:20px;margin:20px 0;text-align:center;' : 'border:1px solid #e5e7eb;border-radius:8px;padding:24px;margin:24px 0;text-align:center;';
		?>
		<section class="woocommerce-monapay-payment" style="<?php echo esc_attr( $style ); ?>">
			<h2><?php esc_html_e( 'Thanh toán MONA Pay', 'mona-pay-for-woocommerce' ); ?></h2>
			<?php $this->render_sandbox_warning( $order ); ?>
			<p><strong><?php esc_html_e( 'Trạng thái:', 'mona-pay-for-woocommerce' ); ?></strong> <?php echo esc_html( $status_text ); ?></p>
			<p style="font-size:24px;font-weight:700;margin:12px 0;"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></p>
			<?php if ( $is_pending && '' !== $checkout_url ) : ?>
				<p><a href="<?php echo esc_url( $checkout_url ); ?>" class="<?php echo esc_attr( $email ? 'button' : 'button alt' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Mở lại trang thanh toán', 'mona-pay-for-woocommerce' ); ?></a></p>
			<?php endif; ?>
			<?php if ( ! $email && 'yes' === $this->get_option( 'show_branding', 'no' ) ) : ?>
				<p style="font-size:12px;margin:16px 0 0;color:#6b7280;"><a href="<?php echo esc_url( 'https://monapay.vn?utm_source=woocommerce' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Xác nhận thanh toán tự động bởi MONA Pay', 'mona-pay-for-woocommerce' ); ?></a></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/** Return the configured mode, constrained to supported values. */
	private function get_payment_mode() {
		$mode = (string) $this->get_option( 'payment_mode', 'redirect' );
		return 'inline' === $mode ? 'inline' : 'redirect';
	}

	/** Return whether new payments should use MONA Pay sandbox mode. */
	private function is_sandbox_enabled() {
		return 'yes' === (string) $this->get_option( 'sandbox_mode', 'no' );
	}

	/** Return whether an order was created while sandbox mode was enabled. */
	private function is_order_sandbox( $order ) {
		return 'yes' === (string) $order->get_meta( '_monapay_sandbox', true );
	}

	/** Persist the mode used for this order and add the sandbox audit note once. */
	private function mark_order_sandbox_mode( $order, $is_sandbox ) {
		$stored_mode = (string) $order->get_meta( '_monapay_sandbox', true );
		$order->update_meta_data( '_monapay_sandbox', $is_sandbox ? 'yes' : 'no' );

		if ( $is_sandbox && 'yes' !== $stored_mode ) {
			$order->add_order_note( __( '[Sandbox] Đơn thử nghiệm, không chuyển tiền thật.', 'mona-pay-for-woocommerce' ) );
		}
	}

	/** Render the order-scoped sandbox warning in either payment presentation. */
	private function render_sandbox_warning( $order ) {
		if ( ! $this->is_order_sandbox( $order ) ) {
			return;
		}
		?>
		<p style="background:#fff3cd;border:1px solid #ffecb5;color:#664d03;padding:10px 12px;margin:12px 0;"><strong><?php esc_html_e( 'Đơn thử nghiệm (sandbox), không chuyển tiền thật', 'mona-pay-for-woocommerce' ); ?></strong></p>
		<?php
	}

	/** Resolve the mode stored on an order, with a fallback for 0.2.0 orders. */
	private function get_order_payment_mode( $order ) {
		$mode = (string) $order->get_meta( '_monapay_payment_mode', true );
		if ( in_array( $mode, array( 'redirect', 'inline' ), true ) ) {
			return $mode;
		}
		return '' !== (string) $order->get_meta( '_monapay_qr_data', true ) ? 'inline' : $this->get_payment_mode();
	}

	/** Empty the active cart after a payment session is ready. */
	private function empty_cart() {
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
	}

	/** Return API-only settings for the client. */
	private function get_api_settings() {
		return array(
			'base_url'      => (string) $this->get_option( 'base_url', 'https://api.monapay.vn' ),
			'client_id'     => (string) $this->get_option( 'client_id' ),
			'username'      => $this->get_legacy_api_setting( 'username' ),
			'password'      => $this->get_legacy_api_setting( 'password' ),
			'client_secret' => (string) $this->get_option( 'client_secret' ),
		);
	}

	/**
	 * Read an old 0.1.0 credential directly without exposing it in the settings form.
	 *
	 * @param string $key Legacy option key.
	 * @return string
	 */
	private function get_legacy_api_setting( $key ) {
		$settings = get_option( $this->get_option_key(), array() );
		return is_array( $settings ) && isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
	}

	/** Validate and normalize the API base URL before saving. */
	public function validate_base_url_field( $key, $value ) {
		unset( $key );
		$value = untrailingslashit( esc_url_raw( trim( (string) $value ) ) );
		$value = preg_replace( '#/api/v1/?$#i', '', $value );
		if ( ! wp_http_validate_url( $value ) ) {
			$this->add_error( __( 'Base URL MONA Pay không hợp lệ.', 'mona-pay-for-woocommerce' ) );
			return (string) $this->get_option( 'base_url', 'https://api.monapay.vn' );
		}
		return $value;
	}

	/** Only accept the two ownerType values defined by the QR API. */
	public function validate_owner_type_field( $key, $value ) {
		unset( $key );
		return in_array( $value, array( 'PER', 'ORG' ), true ) ? $value : 'ORG';
	}

	/** Only accept the two supported checkout presentation modes. */
	public function validate_payment_mode_field( $key, $value ) {
		unset( $key );
		return 'inline' === $value ? 'inline' : 'redirect';
	}

	/** Sanitize identifiers without interpreting punctuation as markup. */
	public function validate_client_id_field( $key, $value ) {
		unset( $key );
		return sanitize_text_field( wp_unslash( $value ) );
	}

	/** Sanitize the saved virtual account number. */
	public function validate_sandbox_virtual_account_field( $key, $value ) {
		unset( $key );
		return sanitize_text_field( wp_unslash( $value ) );
	}

	/** Keep the client secret on one line while preserving punctuation. */
	public function validate_client_secret_field( $key, $value ) {
		unset( $key );
		return sanitize_text_field( wp_unslash( $value ) );
	}

	/** Keep the hosted checkout return secret on one line. */
	public function validate_return_signature_secret_field( $key, $value ) {
		unset( $key );
		return sanitize_text_field( wp_unslash( $value ) );
	}

	/** Keep passwords and secrets on one line while preserving punctuation. */
	public function validate_password_field( $key, $value ) {
		unset( $key );
		return sanitize_text_field( wp_unslash( $value ) );
	}

	/** Validate webhook secret length. */
	public function validate_webhook_secret_field( $key, $value ) {
		$value = sanitize_text_field( wp_unslash( $value ) );
		if ( '' !== $value && strlen( $value ) < 32 ) {
			$this->add_error( __( 'Secret HMAC webhook phải dài ít nhất 32 ký tự.', 'mona-pay-for-woocommerce' ) );
			return (string) $this->get_option( $key, '' );
		}
		return $value;
	}

	/** Custom password field with a cryptographic generate button. */
	public function generate_webhook_secret_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$data      = wp_parse_args(
			$data,
			array(
				'title'       => '',
				'description' => '',
			)
		);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label for="<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $data['title'] ); ?></label></th>
			<td class="forminp">
				<fieldset>
					<input class="input-text regular-input" type="password" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" value="<?php echo esc_attr( $this->get_option( $key ) ); ?>" autocomplete="new-password" />
					<button type="button" class="button" id="monapay-generate-secret"><?php esc_html_e( 'Tự sinh secret', 'mona-pay-for-woocommerce' ); ?></button>
					<p class="description"><?php echo esc_html( $data['description'] ); ?></p>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/** Custom settings row containing the callback URL and test button. */
	public function generate_webhook_tools_html( $key, $data ) {
		unset( $key );
		$data = wp_parse_args( $data, array( 'title' => '' ) );
		$url  = rest_url( 'monapay/v1/webhook' );
		$va   = trim( (string) $this->get_option( 'sandbox_virtual_account', '' ) );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<p><code><?php echo esc_html( $url ); ?></code></p>
				<p class="description">
					<?php esc_html_e( 'Trong dashboard my.monapay.vn, tạo webhook với URL trên, auth_type HMAC_SHA256, payload application/json và cùng Secret HMAC. Lưu cài đặt này trước khi gửi thử.', 'mona-pay-for-woocommerce' ); ?>
					<a href="https://my.monapay.vn/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Mở MONA Pay Dashboard', 'mona-pay-for-woocommerce' ); ?></a>
				</p>
				<p>
					<button type="button" class="button button-secondary" id="monapay-test-webhook"><?php esc_html_e( 'Bắn webhook thử', 'mona-pay-for-woocommerce' ); ?></button>
					<button type="button" class="button button-secondary" id="monapay-test-sandbox"<?php disabled( '' === $va ); ?>><?php esc_html_e( 'Tạo giao dịch thử (sandbox)', 'mona-pay-for-woocommerce' ); ?></button>
					<span class="spinner" id="monapay-test-spinner"></span>
				</p>
				<?php if ( '' === $va ) : ?>
					<p class="description"><?php esc_html_e( 'Nhập và lưu “Số VA để thử sandbox” để bật nút giao dịch thử.', 'mona-pay-for-woocommerce' ); ?></p>
				<?php endif; ?>
				<p id="monapay-test-result" aria-live="polite"></p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/** Warn administrators on this gateway's settings screen while sandbox is active. */
	public function admin_sandbox_notice() {
		if ( ! $this->is_sandbox_enabled() || ! $this->is_gateway_settings_screen() ) {
			return;
		}
		?>
		<div class="notice notice-warning inline">
			<p><strong><?php esc_html_e( 'MONA Pay đang bật chế độ thử (sandbox).', 'mona-pay-for-woocommerce' ); ?></strong> <?php esc_html_e( 'Không có tiền thật được chuyển; hãy tắt chế độ này trước khi bán thật.', 'mona-pay-for-woocommerce' ); ?></p>
		</div>
		<?php
	}

	/** Add an order action for simulating payment of an unpaid sandbox order. */
	public function add_sandbox_order_action( $actions, $order ) {
		if ( $this->can_create_order_sandbox_transaction( $order ) ) {
			$actions['monapay_create_sandbox_transaction'] = __( 'MONA Pay: Tạo giao dịch thử (sandbox)', 'mona-pay-for-woocommerce' );
		}

		return $actions;
	}

	/** Request a full-value sandbox transaction for an order and wait for its webhook. */
	public function create_order_sandbox_transaction( $order ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $this->can_create_order_sandbox_transaction( $order ) ) {
			return;
		}

		$amount          = (int) round( (float) $order->get_total() );
		$virtual_account = $this->get_order_sandbox_virtual_account( $order );
		if ( '' === $virtual_account ) {
			$order->add_order_note( __( '[Sandbox] Chưa thể tạo giao dịch thử: checkout và cài đặt chưa có số tài khoản ảo.', 'mona-pay-for-woocommerce' ) );
			return;
		}

		try {
			$api  = new MonaPay_API( $this->get_api_settings() );
			$data = $api->create_sandbox_transaction( $virtual_account, $amount, 'DH' . $order->get_id() );
			$code = isset( $data['transaction_code'] ) ? sanitize_text_field( (string) $data['transaction_code'] ) : '';
			$note = '' !== $code
				? sprintf(
					/* translators: %s: sandbox transaction code. */
					__( '[Sandbox] Đã tạo giao dịch thử cho đơn này. Mã giao dịch: %s. Đang chờ webhook xác nhận.', 'mona-pay-for-woocommerce' ),
					$code
				)
				: __( '[Sandbox] Đã tạo giao dịch thử cho đơn này. Đang chờ webhook xác nhận.', 'mona-pay-for-woocommerce' );
			$order->add_order_note( $note );
		} catch ( Exception $exception ) {
			$this->log( 'error', $exception->getMessage(), array( 'order_id' => $order->get_id(), 'operation' => 'order_sandbox_transaction' ) );
			$order->add_order_note(
				sprintf(
					/* translators: %s: MONA Pay API error. */
					__( '[Sandbox] Không thể tạo giao dịch thử: %s', 'mona-pay-for-woocommerce' ),
					sanitize_text_field( $exception->getMessage() )
				)
			);
		}
	}

	/** Check all invariants before exposing or running the sandbox order action. */
	private function can_create_order_sandbox_transaction( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_payment_method' ) ) {
			return false;
		}

		$amount = (int) round( (float) $order->get_total() );
		return $this->is_sandbox_enabled()
			&& $this->is_order_sandbox( $order )
			&& 'monapay_vietqr' === $order->get_payment_method()
			&& ! $order->is_paid()
			&& $order->has_status( array( 'pending', 'on-hold', 'failed' ) )
			&& $amount > 0
			&& $amount <= 1000000000;
	}

	/** Resolve the checkout VA first, then fall back to the configured test VA. */
	private function get_order_sandbox_virtual_account( $order ) {
		$virtual_account = trim( sanitize_text_field( (string) $order->get_meta( '_monapay_virtual_account_number', true ) ) );
		$checkout_id     = trim( sanitize_text_field( (string) $order->get_meta( '_monapay_checkout_id', true ) ) );

		if ( '' === $virtual_account && '' !== $checkout_id ) {
			try {
				$api      = new MonaPay_API( $this->get_api_settings() );
				$checkout = $api->get_checkout( $checkout_id );
				if ( ! empty( $checkout['virtual_account_number'] ) ) {
					$virtual_account = trim( sanitize_text_field( (string) $checkout['virtual_account_number'] ) );
					$order->update_meta_data( '_monapay_virtual_account_number', $virtual_account );
					$order->save();
				}
			} catch ( Exception $exception ) {
				$this->log( 'warning', $exception->getMessage(), array( 'order_id' => $order->get_id(), 'operation' => 'read_sandbox_checkout' ) );
			}
		}

		if ( '' === $virtual_account ) {
			$virtual_account = trim( sanitize_text_field( (string) $this->get_option( 'sandbox_virtual_account', '' ) ) );
		}

		return $virtual_account;
	}

	/** Return whether the current request is this gateway's settings page. */
	private function is_gateway_settings_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin screen routing; no state is changed.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin screen routing; no state is changed.
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin screen routing; no state is changed.
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		return 'wc-settings' === $page && 'checkout' === $tab && $this->id === $section;
	}

	/** Load admin-only JavaScript on this gateway's settings screen. */
	public function enqueue_admin_assets() {
		if ( ! $this->is_gateway_settings_screen() ) {
			return;
		}

		wp_enqueue_script(
			'woocommerce-monapay-admin',
			MONAPAY_WC_URL . 'assets/js/admin-settings.js',
			array( 'jquery' ),
			MONAPAY_WC_VERSION,
			true
		);
		wp_localize_script(
			'woocommerce-monapay-admin',
			'monaPayAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'monapay_admin' ),
				'confirmWebhook' => __( 'Bắn một webhook giả lập từ MONA Pay tới website này?', 'mona-pay-for-woocommerce' ),
				'confirmSandbox' => __( 'Tạo giao dịch sandbox 10.000 VND cho VA đã cấu hình?', 'mona-pay-for-woocommerce' ),
				'testingWebhook' => __( 'Đang bắn webhook thử…', 'mona-pay-for-woocommerce' ),
				'testingSandbox' => __( 'Đang tạo giao dịch sandbox…', 'mona-pay-for-woocommerce' ),
				'genericError'   => __( 'Không thể hoàn tất thao tác thử.', 'mona-pay-for-woocommerce' ),
				'generated'      => __( 'Đã tạo secret mới. Hãy lưu cài đặt và cập nhật cùng secret trên MONA Pay.', 'mona-pay-for-woocommerce' ),
			)
		);
	}

	/** Secure AJAX handler for POST /client-webhooks/test. */
	public function ajax_test_webhook() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Bạn không có quyền thực hiện thao tác này.', 'mona-pay-for-woocommerce' ) ), 403 );
		}
		check_ajax_referer( 'monapay_admin', 'nonce' );

		$secret = (string) $this->get_option( 'webhook_secret' );
		if ( strlen( $secret ) < 32 ) {
			wp_send_json_error( array( 'message' => __( 'Hãy lưu Secret HMAC dài ít nhất 32 ký tự trước.', 'mona-pay-for-woocommerce' ) ), 400 );
		}

		try {
			$api = new MonaPay_API( $this->get_api_settings() );
			$api->test_webhook( rest_url( 'monapay/v1/webhook' ), $secret );
			wp_send_json_success( array( 'message' => __( 'Webhook thử đã được MONA Pay gửi và endpoint trả về thành công.', 'mona-pay-for-woocommerce' ) ) );
		} catch ( Exception $exception ) {
			$this->log( 'error', $exception->getMessage(), array( 'operation' => 'test_webhook' ) );
			wp_send_json_error( array( 'message' => sanitize_text_field( $exception->getMessage() ) ), 400 );
		}
	}

	/** Secure AJAX handler for POST /sandbox/transactions. */
	public function ajax_test_sandbox() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Bạn không có quyền thực hiện thao tác này.', 'mona-pay-for-woocommerce' ) ), 403 );
		}
		check_ajax_referer( 'monapay_admin', 'nonce' );

		$virtual_account = sanitize_text_field( (string) $this->get_option( 'sandbox_virtual_account', '' ) );
		if ( '' === $virtual_account ) {
			wp_send_json_error( array( 'message' => __( 'Hãy nhập và lưu số VA đầy đủ trước khi tạo giao dịch sandbox.', 'mona-pay-for-woocommerce' ) ), 400 );
		}

		try {
			$api  = new MonaPay_API( $this->get_api_settings() );
			$data = $api->create_sandbox_transaction( $virtual_account, 10000, 'WooCommerce test sandbox' );
			$code = isset( $data['transaction_code'] ) ? sanitize_text_field( (string) $data['transaction_code'] ) : '';
			$message = '' !== $code
				? sprintf(
					/* translators: %s: sandbox transaction code. */
					__( 'Đã tạo giao dịch sandbox 10.000 VND. Mã giao dịch: %s.', 'mona-pay-for-woocommerce' ),
					$code
				)
				: __( 'Đã tạo giao dịch sandbox 10.000 VND; MONA Pay đang gửi webhook.', 'mona-pay-for-woocommerce' );
			wp_send_json_success( array( 'message' => $message ) );
		} catch ( Exception $exception ) {
			$this->log( 'error', $exception->getMessage(), array( 'operation' => 'test_sandbox' ) );
			wp_send_json_error( array( 'message' => sanitize_text_field( $exception->getMessage() ) ), 400 );
		}
	}

	/** Write to the WooCommerce logger without credentials or payload secrets. */
	private function log( $level, $message, $context = array() ) {
		$context['source'] = 'mona-pay-for-woocommerce';
		wc_get_logger()->log( $level, $message, $context );
	}
}
