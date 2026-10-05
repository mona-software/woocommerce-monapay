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
		$this->method_description = __( 'Send customers to the MONA Pay payment page or show a VietQR in your store, then confirm payment automatically with an HMAC-signed webhook.', 'mona-pay-for-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Bank transfer (VietQR, confirmed automatically)', 'mona-pay-for-woocommerce' ) );
		$this->description = $this->get_option( 'description', __( 'Scan the VietQR code to transfer the money. Your order is confirmed automatically when MONA Pay receives the transaction.', 'mona-pay-for-woocommerce' ) );
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
			'enabled'                 => array(
				'title'   => __( 'Enable/Disable', 'mona-pay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable MONA Pay VietQR', 'mona-pay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'                   => array(
				'title'       => __( 'Title', 'mona-pay-for-woocommerce' ),
				'type'        => 'text',
				'default'     => __( 'Bank transfer (VietQR, confirmed automatically)', 'mona-pay-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'The name customers see at checkout.', 'mona-pay-for-woocommerce' ),
			),
			'description'             => array(
				'title'       => __( 'Description', 'mona-pay-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => __( 'Scan the VietQR code to transfer the money. Your order is confirmed automatically when MONA Pay receives the transaction.', 'mona-pay-for-woocommerce' ),
				'description' => __( 'Text shown below the payment method.', 'mona-pay-for-woocommerce' ),
			),
			'payment_mode'            => array(
				'title'       => __( 'Payment flow', 'mona-pay-for-woocommerce' ),
				'type'        => 'select',
				'default'     => 'redirect',
				'description' => __( 'Choose where customers see the QR code and pay.', 'mona-pay-for-woocommerce' ),
				'options'     => array(
					'redirect' => __( 'Redirect to the MONA Pay payment page', 'mona-pay-for-woocommerce' ),
					'inline'   => __( 'Show the QR in your store', 'mona-pay-for-woocommerce' ),
				),
			),
			'sandbox_mode'            => array(
				'title'       => __( 'Test mode (sandbox)', 'mona-pay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'No real money moves; use it before a bank account is connected', 'mona-pay-for-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Test payment sessions are for integration testing only. Turn sandbox mode off before taking real orders.', 'mona-pay-for-woocommerce' ),
			),
			'api_heading'             => array(
				'title'       => __( 'MONA Pay connection', 'mona-pay-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Go to my.monapay.vn → API Keys → Create key after you sign up.', 'mona-pay-for-woocommerce' ),
			),
			'base_url'                => array(
				'title'             => __( 'Base URL', 'mona-pay-for-woocommerce' ),
				'type'              => 'text',
				'default'           => 'https://api.monapay.vn',
				'description'       => __( 'Root URL of the MONA Pay API, without /api/v1.', 'mona-pay-for-woocommerce' ),
				'custom_attributes' => array( 'required' => 'required' ),
			),
			'client_id'               => array(
				'title'             => __( 'Client ID', 'mona-pay-for-woocommerce' ),
				'type'              => 'text',
				'description'       => __( 'Find it at my.monapay.vn → API Keys → Create key.', 'mona-pay-for-woocommerce' ),
				'custom_attributes' => array( 'autocomplete' => 'off' ),
			),
			'client_secret'           => array(
				'title'             => __( 'Client Secret', 'mona-pay-for-woocommerce' ),
				'type'              => 'password',
				'description'       => __( 'The secret is shown only once when the key is created. The plugin uses it to get a token and to send the X-Client-Secret header.', 'mona-pay-for-woocommerce' ),
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
			),
			'return_signature_secret' => array(
				'title'             => __( 'Return signature secret', 'mona-pay-for-woocommerce' ),
				'type'              => 'password',
				'description'       => sprintf(
					/* translators: %s: MONA Pay hosted checkout documentation URL. */
					__( 'Find it in the MONA Pay dashboard → Settings → Payment page. <a href="%s" target="_blank" rel="noopener noreferrer">Read the guide</a>.', 'mona-pay-for-woocommerce' ),
					esc_url( 'https://monapay.vn/docs/api/trang-thanh-toan/' )
				),
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
			),
			'qr_heading'              => array(
				'title'       => __( 'VietQR details', 'mona-pay-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Copy the exact values that ACB and MONA Pay issued for the receiving account.', 'mona-pay-for-woocommerce' ),
			),
			'virtual_account_prefix'  => array(
				'title'             => __( 'Virtual account prefix', 'mona-pay-for-woocommerce' ),
				'type'              => 'text',
				'description'       => __( 'virtualAccountPrefix, 1 to 10 characters.', 'mona-pay-for-woocommerce' ),
				'custom_attributes' => array( 'maxlength' => '10' ),
			),
			'owner_number'            => array(
				'title'       => __( 'Receiving account number', 'mona-pay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'ownerNumber of the ACB account.', 'mona-pay-for-woocommerce' ),
			),
			'owner_type'              => array(
				'title'   => __( 'Account holder type', 'mona-pay-for-woocommerce' ),
				'type'    => 'select',
				'default' => 'ORG',
				'options' => array(
					'PER' => __( 'Individual (PER)', 'mona-pay-for-woocommerce' ),
					'ORG' => __( 'Organization (ORG)', 'mona-pay-for-woocommerce' ),
				),
			),
			'merchant_id'             => array(
				'title' => __( 'Merchant ID', 'mona-pay-for-woocommerce' ),
				'type'  => 'text',
			),
			'terminal_id'             => array(
				'title' => __( 'Terminal ID', 'mona-pay-for-woocommerce' ),
				'type'  => 'text',
			),
			'beneficiary_name'        => array(
				'title'             => __( 'Beneficiary name', 'mona-pay-for-woocommerce' ),
				'type'              => 'text',
				'description'       => __( 'beneficiaryName shown in the banking app, up to 100 characters.', 'mona-pay-for-woocommerce' ),
				'custom_attributes' => array( 'maxlength' => '100' ),
			),
			'sandbox_virtual_account' => array(
				'title'       => __( 'Sandbox test virtual account', 'mona-pay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'The full virtual account number linked to ACB. Save this field to enable the 10,000 VND test transaction button.', 'mona-pay-for-woocommerce' ),
			),
			'webhook_heading'         => array(
				'title'       => __( 'Automatic confirmation webhook', 'mona-pay-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'HMAC-SHA256 makes sure only genuine webhooks from MONA Pay are accepted.', 'mona-pay-for-woocommerce' ),
			),
			'webhook_secret'          => array(
				'title'       => __( 'Secret HMAC webhook', 'mona-pay-for-woocommerce' ),
				'type'        => 'webhook_secret',
				'description' => __( 'Use this same secret when you create the HMAC_SHA256 webhook in MONA Pay. It should be at least 32 characters long.', 'mona-pay-for-woocommerce' ),
			),
			'autocomplete_orders'     => array(
				'title'       => __( 'Complete orders automatically', 'mona-pay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Move the order straight to Completed once the full amount arrives', 'mona-pay-for-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'When off, WooCommerce picks Processing or Completed by product type after payment_complete().', 'mona-pay-for-woocommerce' ),
			),
			'show_branding'           => array(
				'title'       => __( 'MONA Pay credit line', 'mona-pay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Show an "automatic confirmation by MONA Pay" line on the order page', 'mona-pay-for-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Off by default. Turn it on only if you want to credit MONA Pay on the order page.', 'mona-pay-for-woocommerce' ),
			),
			'webhook_tools'           => array(
				'title' => __( 'Setup and testing', 'mona-pay-for-woocommerce' ),
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
	 * @return array Always an array: the Store API used by the blocks checkout fatals on null.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'The order to pay with MONA Pay was not found.', 'mona-pay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( 'VND' !== $order->get_currency() ) {
			wc_add_notice( __( 'MONA Pay only supports orders in VND.', 'mona-pay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$amount = (int) round( (float) $order->get_total() );
		if ( $amount <= 0 || $amount > 1000000000 ) {
			wc_add_notice( __( 'The order total exceeds the MONA Pay payment limit.', 'mona-pay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( 'redirect' === $this->get_payment_mode() ) {
			return $this->process_redirect_payment( $order, $amount );
		}

		return $this->process_inline_payment( $order, $amount );
	}

	/** Create a hosted checkout and redirect the customer to MONA Pay. */
	private function process_redirect_payment( $order, $amount ) {
		if ( $amount < 1000 ) {
			wc_add_notice( __( 'The order total must be at least 1,000 VND to pay with MONA Pay.', 'mona-pay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$checkout_url     = (string) $order->get_meta( '_monapay_checkout_url', true );
		$checkout_status  = (string) $order->get_meta( '_monapay_checkout_status', true );
		$current_sandbox  = $this->is_sandbox_enabled();
		$checkout_sandbox = 'yes' === (string) $order->get_meta( '_monapay_sandbox', true );
		$sandbox_changed  = '' !== $checkout_url && $current_sandbox !== $checkout_sandbox;
		if ( '' !== $checkout_url && ! $sandbox_changed && ! in_array( $checkout_status, array( 'cancelled', 'expired' ), true ) ) {
			$order->update_meta_data( '_monapay_payment_mode', 'redirect' );
			$order->save();
			wc_reduce_stock_levels( $order->get_id() );
			$this->empty_cart();
			return array(
				'result'   => 'success',
				'redirect' => $checkout_url,
			);
		}

		$attempt = max( 1, (int) $order->get_meta( '_monapay_checkout_attempt', true ) );
		if ( $sandbox_changed || in_array( $checkout_status, array( 'cancelled', 'expired' ), true ) ) {
			++$attempt;
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
			// Shown to the payer on the MONA Pay page and on the bank statement, so it stays in Vietnamese.
			'description' => (string) apply_filters( 'monapay_checkout_description', 'Thanh toán đơn hàng DH' . $order->get_id(), $order ),
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
			$api                  = new MonaPay_API( $this->get_api_settings() );
			$data                 = $api->create_checkout( $payload, 'wc-' . $order->get_id() . '-' . $attempt );
			$created_checkout_url = isset( $data['checkout_url'] ) ? esc_url_raw( (string) $data['checkout_url'] ) : '';
			if ( empty( $data['id'] ) || empty( $data['token'] ) || ! wp_http_validate_url( $created_checkout_url ) || 0 !== strpos( $created_checkout_url, 'https://' ) ) {
				throw new Exception( 'The create-checkout response is missing id, token or checkout_url.' );
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
				$order->update_status( 'pending', __( 'MONA Pay payment page created; waiting for the transaction.', 'mona-pay-for-woocommerce' ) );
			} else {
				$order->add_order_note( __( 'MONA Pay payment page created; waiting for the transaction.', 'mona-pay-for-woocommerce' ) );
			}
			wc_reduce_stock_levels( $order->get_id() );
		} catch ( Exception $exception ) {
			$this->log(
				'error',
				$exception->getMessage(),
				array(
					'order_id'  => $order->get_id(),
					'operation' => 'create_checkout',
				)
			);
			wc_add_notice( __( 'The MONA Pay payment page could not be opened. Please try again or choose another payment method.', 'mona-pay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
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
						'ownerNumber'          => (string) $this->get_option( 'owner_number' ),
						'ownerType'            => (string) $this->get_option( 'owner_type', 'ORG' ),
						'merchantId'           => (string) $this->get_option( 'merchant_id' ),
						'terminalId'           => (string) $this->get_option( 'terminal_id' ),
						'orderId'              => (string) $order->get_id(),
						'virtualAccountPrefix' => (string) $this->get_option( 'virtual_account_prefix' ),
						'beneficiaryName'      => (string) $this->get_option( 'beneficiary_name' ),
						'amount'               => $amount,
						'description'          => 'DH' . $order->get_id(),
					)
				);

				if ( empty( $data['id'] ) || empty( $data['qr_data_url'] ) ) {
					throw new Exception( 'The generate-QR response is missing id or qr_data_url.' );
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
				wc_add_notice( __( 'The VietQR code could not be created. Please try again or choose another payment method.', 'mona-pay-for-woocommerce' ), 'error' );
				return array( 'result' => 'failure' );
			}
		}

		if ( ! $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'on-hold', __( 'MONA Pay VietQR created; waiting for the bank transaction.', 'mona-pay-for-woocommerce' ) );
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
				echo "\n" . esc_html__( 'Test order (sandbox); no real money is transferred', 'mona-pay-for-woocommerce' ) . "\n";
			}
			if ( 'redirect' === $this->get_order_payment_mode( $order ) ) {
				echo "\n" . esc_html__( 'MONA PAY PAYMENT', 'mona-pay-for-woocommerce' ) . "\n";
				echo esc_html__( 'Open the payment page:', 'mona-pay-for-woocommerce' ) . ' ' . esc_url_raw( (string) $order->get_meta( '_monapay_checkout_url', true ) ) . "\n\n";
				return;
			}
			$account = (string) $order->get_meta( '_monapay_virtual_account_number', true );
			echo "\n" . esc_html__( 'MONA PAY VIETQR PAYMENT', 'mona-pay-for-woocommerce' ) . "\n";
			echo esc_html__( 'Transfer message:', 'mona-pay-for-woocommerce' ) . ' DH' . esc_html( (string) $order->get_id() ) . "\n";
			if ( '' !== $account ) {
				echo esc_html__( 'Virtual account number:', 'mona-pay-for-woocommerce' ) . ' ' . esc_html( $account ) . "\n";
			}
			echo esc_html__( 'Open the QR image:', 'mona-pay-for-woocommerce' ) . ' ' . esc_url_raw( MonaPay_QR_Endpoint::get_url( $order ) ) . "\n\n";
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
			<h2><?php esc_html_e( 'Scan the VietQR to pay', 'mona-pay-for-woocommerce' ); ?></h2>
			<?php $this->render_sandbox_warning( $order ); ?>
			<p><?php esc_html_e( 'Please check the payment details before you make the transfer.', 'mona-pay-for-woocommerce' ); ?></p>
			<p style="font-size:24px;font-weight:700;margin:12px 0;"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></p>
			<p><img src="<?php echo esc_url( $qr_url ); ?>" width="320" height="320" alt="<?php esc_attr_e( 'VietQR code to pay for the order', 'mona-pay-for-woocommerce' ); ?>" style="display:block;max-width:100%;height:auto;margin:16px auto;" /></p>
			<?php if ( ! $email && 'yes' === $this->get_option( 'show_branding', 'no' ) ) : ?>
				<p style="font-size:12px;margin:-6px 0 16px;color:#6b7280;">
					<a href="<?php echo esc_url( 'https://monapay.vn?utm_source=woocommerce' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Payment confirmed automatically by MONA Pay', 'mona-pay-for-woocommerce' ); ?></a>
				</p>
			<?php endif; ?>
			<p>
				<?php if ( '' !== $account ) : ?>
					<strong><?php esc_html_e( 'Virtual account number:', 'mona-pay-for-woocommerce' ); ?></strong> <?php echo esc_html( $account ); ?><br />
				<?php endif; ?>
				<strong><?php esc_html_e( 'Beneficiary:', 'mona-pay-for-woocommerce' ); ?></strong> <?php echo esc_html( (string) $this->get_option( 'beneficiary_name' ) ); ?><br />
				<strong><?php esc_html_e( 'Transfer message:', 'mona-pay-for-woocommerce' ); ?></strong> <?php echo esc_html( 'DH' . $order->get_id() ); ?>
			</p>
			<p><?php esc_html_e( 'Your order is confirmed automatically once the bank records the transaction.', 'mona-pay-for-woocommerce' ); ?></p>
		</section>
		<?php
	}

	/** Render hosted-checkout status and the reopen button. */
	private function render_redirect_payment_box( $order, $email = false ) {
		$checkout_url    = (string) $order->get_meta( '_monapay_checkout_url', true );
		$checkout_status = (string) $order->get_meta( '_monapay_checkout_status', true );
		$is_pending      = ! $order->is_paid();
		$status_text     = $order->is_paid() ? __( 'Paid', 'mona-pay-for-woocommerce' ) : __( 'Waiting for payment', 'mona-pay-for-woocommerce' );
		if ( $is_pending && 'cancelled' === $checkout_status ) {
			$status_text = __( 'Not paid', 'mona-pay-for-woocommerce' );
		}
		$style = $email ? 'border:1px solid #e5e7eb;padding:20px;margin:20px 0;text-align:center;' : 'border:1px solid #e5e7eb;border-radius:8px;padding:24px;margin:24px 0;text-align:center;';
		?>
		<section class="woocommerce-monapay-payment" style="<?php echo esc_attr( $style ); ?>">
			<h2><?php esc_html_e( 'MONA Pay payment', 'mona-pay-for-woocommerce' ); ?></h2>
			<?php $this->render_sandbox_warning( $order ); ?>
			<p><strong><?php esc_html_e( 'Status:', 'mona-pay-for-woocommerce' ); ?></strong> <?php echo esc_html( $status_text ); ?></p>
			<p style="font-size:24px;font-weight:700;margin:12px 0;"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></p>
			<?php if ( $is_pending && '' !== $checkout_url ) : ?>
				<p><a href="<?php echo esc_url( $checkout_url ); ?>" class="<?php echo esc_attr( $email ? 'button' : 'button alt' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Reopen the payment page', 'mona-pay-for-woocommerce' ); ?></a></p>
			<?php endif; ?>
			<?php if ( ! $email && 'yes' === $this->get_option( 'show_branding', 'no' ) ) : ?>
				<p style="font-size:12px;margin:16px 0 0;color:#6b7280;"><a href="<?php echo esc_url( 'https://monapay.vn?utm_source=woocommerce' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Payment confirmed automatically by MONA Pay', 'mona-pay-for-woocommerce' ); ?></a></p>
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
			$order->add_order_note( __( '[Sandbox] Test order; no real money is transferred.', 'mona-pay-for-woocommerce' ) );
		}
	}

	/** Render the order-scoped sandbox warning in either payment presentation. */
	private function render_sandbox_warning( $order ) {
		if ( ! $this->is_order_sandbox( $order ) ) {
			return;
		}
		?>
		<p style="background:#fff3cd;border:1px solid #ffecb5;color:#664d03;padding:10px 12px;margin:12px 0;"><strong><?php esc_html_e( 'Test order (sandbox); no real money is transferred', 'mona-pay-for-woocommerce' ); ?></strong></p>
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
			$this->add_error( __( 'The MONA Pay base URL is not valid.', 'mona-pay-for-woocommerce' ) );
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
			$this->add_error( __( 'The webhook HMAC secret must be at least 32 characters long.', 'mona-pay-for-woocommerce' ) );
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
					<button type="button" class="button" id="monapay-generate-secret"><?php esc_html_e( 'Generate secret', 'mona-pay-for-woocommerce' ); ?></button>
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
					<?php esc_html_e( 'In the my.monapay.vn dashboard, create a webhook with the URL above, auth_type HMAC_SHA256, payload application/json and the same HMAC secret. Save these settings before sending a test.', 'mona-pay-for-woocommerce' ); ?>
					<a href="https://my.monapay.vn/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open the MONA Pay dashboard', 'mona-pay-for-woocommerce' ); ?></a>
				</p>
				<p>
					<button type="button" class="button button-secondary" id="monapay-test-webhook"><?php esc_html_e( 'Send a test webhook', 'mona-pay-for-woocommerce' ); ?></button>
					<button type="button" class="button button-secondary" id="monapay-test-sandbox"<?php disabled( '' === $va ); ?>><?php esc_html_e( 'Create a test transaction (sandbox)', 'mona-pay-for-woocommerce' ); ?></button>
					<span class="spinner" id="monapay-test-spinner"></span>
				</p>
				<?php if ( '' === $va ) : ?>
					<p class="description"><?php esc_html_e( 'Enter and save the “Sandbox test virtual account” to enable the test transaction button.', 'mona-pay-for-woocommerce' ); ?></p>
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
			<p><strong><?php esc_html_e( 'MONA Pay is in test mode (sandbox).', 'mona-pay-for-woocommerce' ); ?></strong> <?php esc_html_e( 'No real money is transferred. Turn this mode off before taking real orders.', 'mona-pay-for-woocommerce' ); ?></p>
		</div>
		<?php
	}

	/** Add an order action for simulating payment of an unpaid sandbox order. */
	public function add_sandbox_order_action( $actions, $order ) {
		if ( $this->can_create_order_sandbox_transaction( $order ) ) {
			$actions['monapay_create_sandbox_transaction'] = __( 'MONA Pay: create a test transaction (sandbox)', 'mona-pay-for-woocommerce' );
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
			$order->add_order_note( __( '[Sandbox] Could not create a test transaction: neither the checkout nor the settings have a virtual account number.', 'mona-pay-for-woocommerce' ) );
			return;
		}

		try {
			$api  = new MonaPay_API( $this->get_api_settings() );
			$data = $api->create_sandbox_transaction( $virtual_account, $amount, 'DH' . $order->get_id() );
			$code = isset( $data['transaction_code'] ) ? sanitize_text_field( (string) $data['transaction_code'] ) : '';
			$note = '' !== $code
				? sprintf(
					/* translators: %s: sandbox transaction code. */
					__( '[Sandbox] Test transaction created for this order. Transaction code: %s. Waiting for the confirmation webhook.', 'mona-pay-for-woocommerce' ),
					$code
				)
				: __( '[Sandbox] Test transaction created for this order. Waiting for the confirmation webhook.', 'mona-pay-for-woocommerce' );
			$order->add_order_note( $note );
		} catch ( Exception $exception ) {
			$this->log(
				'error',
				$exception->getMessage(),
				array(
					'order_id'  => $order->get_id(),
					'operation' => 'order_sandbox_transaction',
				)
			);
			$order->add_order_note(
				sprintf(
					/* translators: %s: MONA Pay API error. */
					__( '[Sandbox] Could not create a test transaction: %s', 'mona-pay-for-woocommerce' ),
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
				$this->log(
					'warning',
					$exception->getMessage(),
					array(
						'order_id'  => $order->get_id(),
						'operation' => 'read_sandbox_checkout',
					)
				);
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
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'monapay_admin' ),
				'confirmWebhook' => __( 'Send a simulated webhook from MONA Pay to this website?', 'mona-pay-for-woocommerce' ),
				'confirmSandbox' => __( 'Create a 10,000 VND sandbox transaction for the configured virtual account?', 'mona-pay-for-woocommerce' ),
				'testingWebhook' => __( 'Sending the test webhook…', 'mona-pay-for-woocommerce' ),
				'testingSandbox' => __( 'Creating the sandbox transaction…', 'mona-pay-for-woocommerce' ),
				'genericError'   => __( 'The test could not be completed.', 'mona-pay-for-woocommerce' ),
				'generated'      => __( 'A new secret was generated. Save the settings and update the same secret in MONA Pay.', 'mona-pay-for-woocommerce' ),
			)
		);
	}

	/** Secure AJAX handler for POST /client-webhooks/test. */
	public function ajax_test_webhook() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'mona-pay-for-woocommerce' ) ), 403 );
		}
		check_ajax_referer( 'monapay_admin', 'nonce' );

		$secret = (string) $this->get_option( 'webhook_secret' );
		if ( strlen( $secret ) < 32 ) {
			wp_send_json_error( array( 'message' => __( 'Save an HMAC secret of at least 32 characters first.', 'mona-pay-for-woocommerce' ) ), 400 );
		}

		try {
			$api = new MonaPay_API( $this->get_api_settings() );
			$api->test_webhook( rest_url( 'monapay/v1/webhook' ), $secret );
			wp_send_json_success( array( 'message' => __( 'MONA Pay sent the test webhook and your endpoint answered successfully.', 'mona-pay-for-woocommerce' ) ) );
		} catch ( Exception $exception ) {
			$this->log( 'error', $exception->getMessage(), array( 'operation' => 'test_webhook' ) );
			wp_send_json_error( array( 'message' => sanitize_text_field( $exception->getMessage() ) ), 400 );
		}
	}

	/** Secure AJAX handler for POST /sandbox/transactions. */
	public function ajax_test_sandbox() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'mona-pay-for-woocommerce' ) ), 403 );
		}
		check_ajax_referer( 'monapay_admin', 'nonce' );

		$virtual_account = sanitize_text_field( (string) $this->get_option( 'sandbox_virtual_account', '' ) );
		if ( '' === $virtual_account ) {
			wp_send_json_error( array( 'message' => __( 'Enter and save the full virtual account number before creating a sandbox transaction.', 'mona-pay-for-woocommerce' ) ), 400 );
		}

		try {
			$api     = new MonaPay_API( $this->get_api_settings() );
			$data    = $api->create_sandbox_transaction( $virtual_account, 10000, 'WooCommerce test sandbox' );
			$code    = isset( $data['transaction_code'] ) ? sanitize_text_field( (string) $data['transaction_code'] ) : '';
			$message = '' !== $code
				? sprintf(
					/* translators: %s: sandbox transaction code. */
					__( '10,000 VND sandbox transaction created. Transaction code: %s.', 'mona-pay-for-woocommerce' ),
					$code
				)
				: __( '10,000 VND sandbox transaction created; MONA Pay is sending the webhook.', 'mona-pay-for-woocommerce' );
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
