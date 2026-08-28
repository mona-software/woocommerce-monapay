<?php
/**
 * Plugin Name:       MONA Pay for WooCommerce
 * Plugin URI:        https://monapay.vn/
 * Description:       Nhận thanh toán chuyển khoản VietQR và tự động xác nhận đơn hàng qua MONA Pay.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            The MONA Group
 * Author URI:        https://mona.software/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woocommerce-monapay
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   10.1
 *
 * @package WooCommerce_MonaPay
 */

defined( 'ABSPATH' ) || exit;

define( 'WOOCOMMERCE_MONAPAY_VERSION', '0.1.0' );
define( 'WOOCOMMERCE_MONAPAY_FILE', __FILE__ );
define( 'WOOCOMMERCE_MONAPAY_PATH', plugin_dir_path( __FILE__ ) );
define( 'WOOCOMMERCE_MONAPAY_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare compatibility with WooCommerce features used by this plugin.
 */
function woocommerce_monapay_declare_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'woocommerce_monapay_declare_compatibility' );

/**
 * Show a dependency notice when WooCommerce is unavailable.
 */
function woocommerce_monapay_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'MONA Pay for WooCommerce cần WooCommerce được cài đặt và kích hoạt.', 'woocommerce-monapay' ); ?></p>
	</div>
	<?php
}

/**
 * Load plugin classes after WooCommerce has initialized its gateway base class.
 */
function woocommerce_monapay_init() {
	load_plugin_textdomain( 'woocommerce-monapay', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'woocommerce_monapay_missing_woocommerce_notice' );
		return;
	}

	require_once WOOCOMMERCE_MONAPAY_PATH . 'includes/monapay-functions.php';
	require_once WOOCOMMERCE_MONAPAY_PATH . 'includes/class-monapay-api.php';
	require_once WOOCOMMERCE_MONAPAY_PATH . 'includes/class-monapay-qr-code.php';
	require_once WOOCOMMERCE_MONAPAY_PATH . 'includes/class-monapay-qr-endpoint.php';
	require_once WOOCOMMERCE_MONAPAY_PATH . 'includes/class-monapay-webhook.php';
	require_once WOOCOMMERCE_MONAPAY_PATH . 'includes/class-wc-gateway-monapay.php';

	$GLOBALS['woocommerce_monapay_qr_endpoint'] = new MonaPay_QR_Endpoint();
	$GLOBALS['woocommerce_monapay_webhook']     = new MonaPay_Webhook();
	add_action( 'wp_ajax_monapay_test_webhook', 'woocommerce_monapay_handle_test_webhook' );
}
add_action( 'plugins_loaded', 'woocommerce_monapay_init', 20 );

/**
 * Resolve the gateway explicitly for admin-ajax requests and send a test.
 */
function woocommerce_monapay_handle_test_webhook() {
	$gateway = new WC_Gateway_MonaPay();
	$gateway->ajax_test_webhook();
}

/**
 * Add MONA Pay to WooCommerce payment gateways.
 *
 * @param array $gateways Registered gateway classes.
 * @return array
 */
function woocommerce_monapay_add_gateway( $gateways ) {
	$gateways[] = 'WC_Gateway_MonaPay';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'woocommerce_monapay_add_gateway' );
