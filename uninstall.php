<?php
/**
 * Uninstall routine.
 *
 * Scheduled actions are always removed. Settings, the send log table and
 * stored counters are removed only when "Delete settings and the log when
 * the plugin is deleted" is enabled. Order meta written by the plugin
 * (sent markers, OTP verification) belongs to the orders and is kept.
 *
 * @package Saizul\BdSms
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'woo_bd_sms_retry', 'woo_bd_sms_order_status', 'woo_bd_sms_purge_log' ) as $woo_bd_sms_hook ) {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( $woo_bd_sms_hook, null, 'woo-bd-sms' );
	}
	wp_unschedule_hook( $woo_bd_sms_hook );
}

$woo_bd_sms_settings = get_option( 'woo_bd_sms_settings', array() );
if ( ! is_array( $woo_bd_sms_settings ) || empty( $woo_bd_sms_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$woo_bd_sms_table = $wpdb->prefix . 'woo_bd_sms_log';
$wpdb->query( "DROP TABLE IF EXISTS {$woo_bd_sms_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-off cleanup; the table name comes from the prefix.

delete_option( 'woo_bd_sms_settings' );
delete_option( 'woo_bd_sms_db_version' );

// Rate-limit counters, OTP records and notices are transients; remove any left over.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_woo_bd_sms_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_woo_bd_sms_' ) . '%'
	)
);
