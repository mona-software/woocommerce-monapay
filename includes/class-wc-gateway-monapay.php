<?php
/**
 * WooCommerce VietQR payment gateway powered by MONA Pay.
 *
 * @package MonaPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class MonaPay_Gateway extends WC_Payment_Gateway {
	/** Constructor. */
	public function __construct() {
		$this->id                 = 'monapay_vietqr';
		$this->method_title       = __( 'MONA Pay VietQR', 'woocommerce-monapay' );
		$this->method_description = __( 'Tạo VietQR theo từng đơn và tự xác nhận thanh toán bằng webhook HMAC của MONA Pay.', 'woocommerce-monapay' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Chuyển khoản VietQR (tự xác nhận)', 'woocommerce-monapay' ) );
		$this->description = $this->get_option( 'description', __( 'Quét mã VietQR để chuyển khoản. Đơn hàng được xác nhận tự động khi MONA Pay nhận giao dịch.', 'woocommerce-monapay' ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_instructions' ), 20, 4 );
		add_action( 'woocommerce_view_order', array( $this, 'view_order_instructions' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/** Define settings shown under WooCommerce > Payments. */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'                => array(
				'title'   => __( 'Bật/tắt', 'woocommerce-monapay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Bật thanh toán MONA Pay VietQR', 'woocommerce-monapay' ),
				'default' => 'no',
			),
			'title'                  => array(
				'title'       => __( 'Tiêu đề', 'woocommerce-monapay' ),
				'type'        => 'text',
				'default'     => __( 'Chuyển khoản VietQR (tự xác nhận)', 'woocommerce-monapay' ),
				'desc_tip'    => true,
				'description' => __( 'Tên phương thức khách thấy tại trang thanh toán.', 'woocommerce-monapay' ),
			),
			'description'            => array(
				'title'       => __( 'Mô tả', 'woocommerce-monapay' ),
				'type'        => 'textarea',
				'default'     => __( 'Quét mã VietQR để chuyển khoản. Đơn hàng được xác nhận tự động khi MONA Pay nhận giao dịch.', 'woocommerce-monapay' ),
				'description' => __( 'Nội dung hiển thị bên dưới phương thức thanh toán.', 'woocommerce-monapay' ),
			),
			'api_heading'            => array(
				'title'       => __( 'Kết nối MONA Pay', 'woocommerce-monapay' ),
				'type'        => 'title',
				'description' => __( 'Vào my.monapay.vn → API Keys → Tạo key, hoặc dùng khối “Đưa cho AI” sau khi đăng ký.', 'woocommerce-monapay' ),
			),
			'base_url'               => array(
				'title'             => __( 'Base URL', 'woocommerce-monapay' ),
				'type'              => 'text',
				'default'           => 'https://api.monapay.vn',
				'description'       => __( 'URL gốc của MONA Pay API, không thêm /api/v1.', 'woocommerce-monapay' ),
				'custom_attributes' => array( 'required' => 'required' ),
			),
			'client_id'              => array(
				'title'             => __( 'Client ID', 'woocommerce-monapay' ),
				'type'              => 'text',
				'description'       => __( 'Lấy tại my.monapay.vn → API Keys → Tạo key.', 'woocommerce-monapay' ),
				'custom_attributes' => array( 'autocomplete' => 'off' ),
			),
			'client_secret'          => array(
				'title'             => __( 'Client Secret', 'woocommerce-monapay' ),
				'type'              => 'password',
				'description'       => __( 'Secret chỉ hiển thị một lần khi tạo key; plugin dùng để lấy token và gửi header X-Client-Secret.', 'woocommerce-monapay' ),
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
			),
			'qr_heading'             => array(
				'title'       => __( 'Thông tin VietQR', 'woocommerce-monapay' ),
				'type'        => 'title',
				'description' => __( 'Sao chép đúng các giá trị ACB/MONA Pay đã cấp cho tài khoản nhận tiền.', 'woocommerce-monapay' ),
			),
			'virtual_account_prefix' => array(
				'title'             => __( 'Đầu số tài khoản ảo', 'woocommerce-monapay' ),
				'type'              => 'text',
				'description'       => __( 'virtualAccountPrefix, dài từ 1 đến 10 ký tự.', 'woocommerce-monapay' ),
				'custom_attributes' => array( 'maxlength' => '10' ),
			),
			'owner_number'           => array(
				'title'       => __( 'Số tài khoản nhận tiền', 'woocommerce-monapay' ),
				'type'        => 'text',
				'description' => __( 'ownerNumber của tài khoản ACB.', 'woocommerce-monapay' ),
			),
			'owner_type'             => array(
				'title'   => __( 'Loại chủ tài khoản', 'woocommerce-monapay' ),
				'type'    => 'select',
				'default' => 'ORG',
				'options' => array(
					'PER' => __( 'Cá nhân (PER)', 'woocommerce-monapay' ),
					'ORG' => __( 'Tổ chức (ORG)', 'woocommerce-monapay' ),
				),
			),
			'merchant_id'            => array(
				'title' => __( 'Merchant ID', 'woocommerce-monapay' ),
				'type'  => 'text',
			),
			'terminal_id'            => array(
				'title' => __( 'Terminal ID', 'woocommerce-monapay' ),
				'type'  => 'text',
			),
			'beneficiary_name'       => array(
				'title'             => __( 'Tên người thụ hưởng', 'woocommerce-monapay' ),
				'type'              => 'text',
				'description'       => __( 'beneficiaryName hiển thị trên ứng dụng ngân hàng, tối đa 100 ký tự.', 'woocommerce-monapay' ),
				'custom_attributes' => array( 'maxlength' => '100' ),
			),
			'sandbox_virtual_account' => array(
				'title'       => __( 'Số VA để thử sandbox', 'woocommerce-monapay' ),
				'type'        => 'text',
				'description' => __( 'Số tài khoản ảo đầy đủ đã nối ACB. Lưu trường này để bật nút tạo giao dịch thử 10.000 VND.', 'woocommerce-monapay' ),
			),
			'webhook_heading'        => array(
				'title'       => __( 'Webhook tự xác nhận', 'woocommerce-monapay' ),
				'type'        => 'title',
				'description' => __( 'Dùng HMAC-SHA256 để chỉ chấp nhận webhook thật từ MONA Pay.', 'woocommerce-monapay' ),
			),
			'webhook_secret'         => array(
				'title'       => __( 'Secret HMAC webhook', 'woocommerce-monapay' ),
				'type'        => 'webhook_secret',
				'description' => __( 'Dùng cùng secret này khi tạo cấu hình webhook HMAC_SHA256 trên MONA Pay. Nên dài ít nhất 32 ký tự.', 'woocommerce-monapay' ),
			),
			'autocomplete_orders'    => array(
				'title'       => __( 'Tự hoàn tất đơn', 'woocommerce-monapay' ),
				'type'        => 'checkbox',
				'label'       => __( 'Chuyển thẳng đơn sang Hoàn tất sau khi nhận đủ tiền', 'woocommerce-monapay' ),
				'default'     => 'no',
				'description' => __( 'Nếu tắt, WooCommerce chọn Đang xử lý/Hoàn tất theo loại sản phẩm sau payment_complete().', 'woocommerce-monapay' ),
			),
			'show_branding'          => array(
				'title'       => __( 'Nhận diện MONA Pay', 'woocommerce-monapay' ),
				'type'        => 'checkbox',
				'label'       => __( 'Hiển thị dòng xác nhận tự động bởi MONA Pay dưới ảnh QR', 'woocommerce-monapay' ),
				'default'     => 'no',
				'description' => __( 'Tuỳ chọn này mặc định bật và có thể tắt bất kỳ lúc nào.', 'woocommerce-monapay' ),
			),
			'webhook_tools'          => array(
				'title' => __( 'Cấu hình và kiểm tra', 'woocommerce-monapay' ),
				'type'  => 'webhook_tools',
			),
		);
	}

	/** Only offer the gateway for configured VND checkouts. */
	public function is_available() {
		if ( ! parent::is_available() || 'VND' !== get_woocommerce_currency() ) {
			return false;
		}

		$required = array( 'base_url', 'client_secret', 'virtual_account_prefix', 'owner_number', 'owner_type', 'merchant_id', 'terminal_id', 'beneficiary_name', 'webhook_secret' );
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
	 * Generate and persist a VietQR, then place the order on hold.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array|null
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Không tìm thấy đơn hàng để tạo VietQR.', 'woocommerce-monapay' ), 'error' );
			return null;
		}

		if ( 'VND' !== $order->get_currency() ) {
			wc_add_notice( __( 'MONA Pay VietQR chỉ hỗ trợ đơn hàng VND.', 'woocommerce-monapay' ), 'error' );
			return null;
		}

		$amount = (int) round( (float) $order->get_total() );
		if ( $amount <= 0 || $amount > 1000000000 ) {
			wc_add_notice( __( 'Tổng đơn vượt giới hạn tạo VietQR của MONA Pay.', 'woocommerce-monapay' ), 'error' );
			return null;
		}

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
				if ( ! empty( $data['virtual_account_number'] ) ) {
					$order->update_meta_data( '_monapay_virtual_account_number', sanitize_text_field( (string) $data['virtual_account_number'] ) );
				}
				$order->save();
				wc_reduce_stock_levels( $order_id );
			} catch ( Exception $exception ) {
				$this->log(
					'error',
					$exception->getMessage(),
					array( 'order_id' => $order_id )
				);
				wc_add_notice( __( 'Chưa thể tạo mã VietQR. Vui lòng thử lại hoặc chọn phương thức thanh toán khác.', 'woocommerce-monapay' ), 'error' );
				return null;
			}
		}

		if ( ! $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'on-hold', __( 'Đã tạo VietQR MONA Pay; đang chờ giao dịch ngân hàng.', 'woocommerce-monapay' ) );
		}

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

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
		if ( $order && 'monapay_vietqr' === $order->get_payment_method() && ! $order->is_paid() ) {
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
			$account = (string) $order->get_meta( '_monapay_virtual_account_number', true );
			echo "\n" . esc_html__( 'THANH TOÁN VIETQR MONA PAY', 'woocommerce-monapay' ) . "\n";
			echo esc_html__( 'Nội dung chuyển khoản:', 'woocommerce-monapay' ) . ' DH' . esc_html( (string) $order->get_id() ) . "\n";
			if ( '' !== $account ) {
				echo esc_html__( 'Số tài khoản ảo:', 'woocommerce-monapay' ) . ' ' . esc_html( $account ) . "\n";
			}
			echo esc_html__( 'Mở ảnh QR:', 'woocommerce-monapay' ) . ' ' . esc_url_raw( MonaPay_QR_Endpoint::get_url( $order ) ) . "\n\n";
			return;
		}

		$this->render_payment_box( $order, true );
	}

	/** Render the shared payment panel. */
	private function render_payment_box( $order, $email = false ) {
		if ( 'monapay_vietqr' !== $order->get_payment_method() || '' === (string) $order->get_meta( '_monapay_qr_data', true ) ) {
			return;
		}

		$account = (string) $order->get_meta( '_monapay_virtual_account_number', true );
		$qr_url  = MonaPay_QR_Endpoint::get_url( $order );
		$style   = $email ? 'border:1px solid #e5e7eb;padding:20px;margin:20px 0;text-align:center;' : 'border:1px solid #e5e7eb;border-radius:8px;padding:24px;margin:24px 0;text-align:center;';
		?>
		<section class="woocommerce-monapay-payment" style="<?php echo esc_attr( $style ); ?>">
			<h2><?php esc_html_e( 'Quét VietQR để thanh toán', 'woocommerce-monapay' ); ?></h2>
			<p><?php esc_html_e( 'Quý khách vui lòng kiểm tra thông tin thanh toán trước khi thực hiện giao dịch.', 'woocommerce-monapay' ); ?></p>
			<p style="font-size:24px;font-weight:700;margin:12px 0;"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></p>
			<p><img src="<?php echo esc_url( $qr_url ); ?>" width="320" height="320" alt="<?php esc_attr_e( 'Mã VietQR thanh toán đơn hàng', 'woocommerce-monapay' ); ?>" style="display:block;max-width:100%;height:auto;margin:16px auto;" /></p>
			<?php if ( ! $email && 'yes' === $this->get_option( 'show_branding', 'no' ) ) : ?>
				<p style="font-size:12px;margin:-6px 0 16px;color:#6b7280;">
					<a href="<?php echo esc_url( 'https://monapay.vn?utm_source=woocommerce' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Xác nhận thanh toán tự động bởi MONA Pay', 'woocommerce-monapay' ); ?></a>
				</p>
			<?php endif; ?>
			<p>
				<?php if ( '' !== $account ) : ?>
					<strong><?php esc_html_e( 'Số tài khoản ảo:', 'woocommerce-monapay' ); ?></strong> <?php echo esc_html( $account ); ?><br />
				<?php endif; ?>
				<strong><?php esc_html_e( 'Người thụ hưởng:', 'woocommerce-monapay' ); ?></strong> <?php echo esc_html( (string) $this->get_option( 'beneficiary_name' ) ); ?><br />
				<strong><?php esc_html_e( 'Nội dung chuyển khoản:', 'woocommerce-monapay' ); ?></strong> <?php echo esc_html( 'DH' . $order->get_id() ); ?>
			</p>
			<p><?php esc_html_e( 'Đơn hàng sẽ được xác nhận tự động sau khi ngân hàng ghi nhận giao dịch.', 'woocommerce-monapay' ); ?></p>
		</section>
		<?php
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
			$this->add_error( __( 'Base URL MONA Pay không hợp lệ.', 'woocommerce-monapay' ) );
			return (string) $this->get_option( 'base_url', 'https://api.monapay.vn' );
		}
		return $value;
	}

	/** Only accept the two ownerType values defined by the QR API. */
	public function validate_owner_type_field( $key, $value ) {
		unset( $key );
		return in_array( $value, array( 'PER', 'ORG' ), true ) ? $value : 'ORG';
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

	/** Keep passwords and secrets on one line while preserving punctuation. */
	public function validate_password_field( $key, $value ) {
		unset( $key );
		return sanitize_text_field( wp_unslash( $value ) );
	}

	/** Validate webhook secret length. */
	public function validate_webhook_secret_field( $key, $value ) {
		$value = sanitize_text_field( wp_unslash( $value ) );
		if ( '' !== $value && strlen( $value ) < 32 ) {
			$this->add_error( __( 'Secret HMAC webhook phải dài ít nhất 32 ký tự.', 'woocommerce-monapay' ) );
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
					<button type="button" class="button" id="monapay-generate-secret"><?php esc_html_e( 'Tự sinh secret', 'woocommerce-monapay' ); ?></button>
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
					<?php esc_html_e( 'Trong dashboard my.monapay.vn, tạo webhook với URL trên, auth_type HMAC_SHA256, payload application/json và cùng Secret HMAC. Lưu cài đặt này trước khi gửi thử.', 'woocommerce-monapay' ); ?>
					<a href="https://my.monapay.vn/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Mở MONA Pay Dashboard', 'woocommerce-monapay' ); ?></a>
				</p>
				<p>
					<button type="button" class="button button-secondary" id="monapay-test-webhook"><?php esc_html_e( 'Bắn webhook thử', 'woocommerce-monapay' ); ?></button>
					<button type="button" class="button button-secondary" id="monapay-test-sandbox"<?php disabled( '' === $va ); ?>><?php esc_html_e( 'Tạo giao dịch thử (sandbox)', 'woocommerce-monapay' ); ?></button>
					<span class="spinner" id="monapay-test-spinner"></span>
				</p>
				<?php if ( '' === $va ) : ?>
					<p class="description"><?php esc_html_e( 'Nhập và lưu “Số VA để thử sandbox” để bật nút giao dịch thử.', 'woocommerce-monapay' ); ?></p>
				<?php endif; ?>
				<p id="monapay-test-result" aria-live="polite"></p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/** Load admin-only JavaScript on this gateway's settings screen. */
	public function enqueue_admin_assets() {
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		if ( 'wc-settings' !== $page || 'checkout' !== $tab || $this->id !== $section ) {
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
				'confirmWebhook' => __( 'Bắn một webhook giả lập từ MONA Pay tới website này?', 'woocommerce-monapay' ),
				'confirmSandbox' => __( 'Tạo giao dịch sandbox 10.000 VND cho VA đã cấu hình?', 'woocommerce-monapay' ),
				'testingWebhook' => __( 'Đang bắn webhook thử…', 'woocommerce-monapay' ),
				'testingSandbox' => __( 'Đang tạo giao dịch sandbox…', 'woocommerce-monapay' ),
				'genericError'   => __( 'Không thể hoàn tất thao tác thử.', 'woocommerce-monapay' ),
				'generated'      => __( 'Đã tạo secret mới. Hãy lưu cài đặt và cập nhật cùng secret trên MONA Pay.', 'woocommerce-monapay' ),
			)
		);
	}

	/** Secure AJAX handler for POST /client-webhooks/test. */
	public function ajax_test_webhook() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Bạn không có quyền thực hiện thao tác này.', 'woocommerce-monapay' ) ), 403 );
		}
		check_ajax_referer( 'monapay_admin', 'nonce' );

		$secret = (string) $this->get_option( 'webhook_secret' );
		if ( strlen( $secret ) < 32 ) {
			wp_send_json_error( array( 'message' => __( 'Hãy lưu Secret HMAC dài ít nhất 32 ký tự trước.', 'woocommerce-monapay' ) ), 400 );
		}

		try {
			$api = new MonaPay_API( $this->get_api_settings() );
			$api->test_webhook( rest_url( 'monapay/v1/webhook' ), $secret );
			wp_send_json_success( array( 'message' => __( 'Webhook thử đã được MONA Pay gửi và endpoint trả về thành công.', 'woocommerce-monapay' ) ) );
		} catch ( Exception $exception ) {
			$this->log( 'error', $exception->getMessage(), array( 'operation' => 'test_webhook' ) );
			wp_send_json_error( array( 'message' => sanitize_text_field( $exception->getMessage() ) ), 400 );
		}
	}

	/** Secure AJAX handler for POST /sandbox/transactions. */
	public function ajax_test_sandbox() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Bạn không có quyền thực hiện thao tác này.', 'woocommerce-monapay' ) ), 403 );
		}
		check_ajax_referer( 'monapay_admin', 'nonce' );

		$virtual_account = sanitize_text_field( (string) $this->get_option( 'sandbox_virtual_account', '' ) );
		if ( '' === $virtual_account ) {
			wp_send_json_error( array( 'message' => __( 'Hãy nhập và lưu số VA đầy đủ trước khi tạo giao dịch sandbox.', 'woocommerce-monapay' ) ), 400 );
		}

		try {
			$api  = new MonaPay_API( $this->get_api_settings() );
			$data = $api->create_sandbox_transaction( $virtual_account, 10000, 'WooCommerce test sandbox' );
			$code = isset( $data['transaction_code'] ) ? sanitize_text_field( (string) $data['transaction_code'] ) : '';
			$message = '' !== $code
				? sprintf(
					/* translators: %s: sandbox transaction code. */
					__( 'Đã tạo giao dịch sandbox 10.000 VND. Mã giao dịch: %s.', 'woocommerce-monapay' ),
					$code
				)
				: __( 'Đã tạo giao dịch sandbox 10.000 VND; MONA Pay đang gửi webhook.', 'woocommerce-monapay' );
			wp_send_json_success( array( 'message' => $message ) );
		} catch ( Exception $exception ) {
			$this->log( 'error', $exception->getMessage(), array( 'operation' => 'test_sandbox' ) );
			wp_send_json_error( array( 'message' => sanitize_text_field( $exception->getMessage() ) ), 400 );
		}
	}

	/** Write to the WooCommerce logger without credentials or payload secrets. */
	private function log( $level, $message, $context = array() ) {
		$context['source'] = 'woocommerce-monapay';
		wc_get_logger()->log( $level, $message, $context );
	}
}
