<?php
/**
 * Remove the data this plugin stored when it is deleted from the Plugins screen.
 *
 * Order meta is left in place: it is the store's payment record for past orders.
 *
 * @package MonaPay_WooCommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'woocommerce_monapay_vietqr_settings' );

// Cached API tokens and any per-order locks left behind by an interrupted request.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall; the option names are not known in advance.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_monapay_token_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_monapay_token_' ) . '%',
		$wpdb->esc_like( 'monapay_lock_' ) . '%'
	)
);
// phpcs:enable
