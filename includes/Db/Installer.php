<?php
/**
 * Database schema.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\WP\Db;

/**
 * Creates and upgrades the send log table.
 */
final class Installer {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'woo_bd_sms_db_version';

	/**
	 * Table name with prefix.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'woo_bd_sms_log';
	}

	/**
	 * Create or upgrade the table when the schema version changed.
	 */
	public static function maybe_install(): void {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}
		self::install();
	}

	/**
	 * Create or upgrade the table with dbDelta().
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				type varchar(20) NOT NULL DEFAULT '',
				event varchar(60) NOT NULL DEFAULT '',
				phone varchar(32) NOT NULL DEFAULT '',
				message text NOT NULL,
				gateway varchar(40) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT '',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				segments smallint(5) unsigned NOT NULL DEFAULT 0,
				encoding varchar(8) NOT NULL DEFAULT '',
				provider_id varchar(100) NOT NULL DEFAULT '',
				error_code varchar(40) NOT NULL DEFAULT '',
				error_message varchar(255) NOT NULL DEFAULT '',
				response text NOT NULL,
				next_attempt_at bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY order_id (order_id),
				KEY phone (phone),
				KEY created_at (created_at)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Drop the table (uninstall).
	 */
	public static function drop(): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
		delete_option( self::DB_VERSION_OPTION );
	}
}
