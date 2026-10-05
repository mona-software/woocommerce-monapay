<?php
/**
 * Plugin Name:       MONA Pay for WooCommerce
 * Plugin URI:        https://monapay.vn/
 * Description:       Automatic bank-transfer confirmation with VietQR, virtual accounts, and signed webhooks for WooCommerce.
 * Version:           0.3.5
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            The MONA Group
 * Author URI:        https://mona.software/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mona-pay-for-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   10.1
 *
 * @package MonaPay_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MONAPAY_WC_VERSION', '0.3.5' );
define( 'MONAPAY_WC_FILE', __FILE__ );
define( 'MONAPAY_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'MONAPAY_WC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare compatibility with WooCommerce features used by this plugin.
 */
function monapay_declare_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'monapay_declare_compatibility' );

/**
 * Show a dependency notice when WooCommerce is unavailable.
 */
function monapay_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	// Guideline 11: keep the dependency notice to the screens where it is actionable.
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && ! in_array( $screen->id, array( 'dashboard', 'plugins', 'plugin-install' ), true ) && 0 !== strpos( (string) $screen->id, 'woocommerce' ) ) {
		return;
	}
	?>
	<div class="notice notice-error is-dismissible">
		<p><?php esc_html_e( 'MONA Pay for WooCommerce needs WooCommerce to be installed and active.', 'mona-pay-for-woocommerce' ); ?></p>
	</div>
	<?php
}

/**
 * Load plugin classes after WooCommerce has initialized its gateway base class.
 */
function monapay_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'monapay_missing_woocommerce_notice' );
		return;
	}

	require_once MONAPAY_WC_PATH . 'includes/monapay-functions.php';
	require_once MONAPAY_WC_PATH . 'includes/class-monapay-api.php';
	require_once MONAPAY_WC_PATH . 'includes/class-monapay-qr-endpoint.php';
	require_once MONAPAY_WC_PATH . 'includes/class-monapay-webhook.php';
	require_once MONAPAY_WC_PATH . 'includes/class-monapay-return.php';
	require_once MONAPAY_WC_PATH . 'includes/class-monapay-gateway.php';

	monapay_upgrade_030_settings();
	$GLOBALS['monapay_qr_endpoint'] = new MonaPay_QR_Endpoint();
	$GLOBALS['monapay_webhook']     = new MonaPay_Webhook();
	$GLOBALS['monapay_return']      = new MonaPay_Return();
	add_action( 'wp_ajax_monapay_test_webhook', 'monapay_handle_test_webhook' );
	add_action( 'wp_ajax_monapay_test_sandbox', 'monapay_handle_test_sandbox' );
}
add_action( 'plugins_loaded', 'monapay_init', 20 );

/**
 * Offer MONA Pay in the Cart and Checkout blocks.
 *
 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Blocks payment method registry.
 */
function monapay_register_blocks_support( $registry ) {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}
	require_once MONAPAY_WC_PATH . 'includes/class-monapay-blocks-support.php';
	$registry->register( new MonaPay_Blocks_Support() );
}
add_action( 'woocommerce_blocks_payment_method_type_registration', 'monapay_register_blocks_support' );

/** Preserve the 0.2.0 inline behavior on configured stores during upgrade. */
function monapay_upgrade_030_settings() {
	$option   = 'woocommerce_monapay_vietqr_settings';
	$settings = get_option( $option, false );
	if ( is_array( $settings ) && ! empty( $settings ) && ! array_key_exists( 'payment_mode', $settings ) ) {
		$settings['payment_mode'] = 'inline';
		update_option( $option, $settings );
	}
}

/**
 * Resolve the gateway explicitly for admin-ajax requests and send a test.
 */
function monapay_handle_test_webhook() {
	$gateway = new MonaPay_Gateway();
	$gateway->ajax_test_webhook();
}

/**
 * Resolve the gateway explicitly for admin-ajax requests and create a sandbox transaction.
 */
function monapay_handle_test_sandbox() {
	$gateway = new MonaPay_Gateway();
	$gateway->ajax_test_sandbox();
}

/**
 * Add MONA Pay to WooCommerce payment gateways.
 *
 * @param array $gateways Registered gateway classes.
 * @return array
 */
function monapay_add_gateway( $gateways ) {
	$gateways[] = 'MonaPay_Gateway';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'monapay_add_gateway' );
