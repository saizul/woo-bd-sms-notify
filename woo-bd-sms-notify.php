<?php
/**
 * Plugin Name:          SMS Notify for WooCommerce (Bangladesh)
 * Plugin URI:           https://github.com/saizul/woo-bd-sms-notify
 * Description:          Order status SMS for Bangladeshi stores through BulkSMSBD, SSL Wireless, Alpha SMS or any HTTP gateway, with Bangla segment counting, retries, a send log and COD phone verification.
 * Version:              0.1.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Saizul Amin
 * Author URI:           https://saizul.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          woo-bd-sms-notify
 * Domain Path:          /languages
 * WC requires at least: 7.1
 * WC tested up to:      9.4
 *
 * @package Saizul\BdSms
 */

defined( 'ABSPATH' ) || exit;

if ( PHP_VERSION_ID < 70400 ) {
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'SMS Notify for WooCommerce requires PHP 7.4 or newer.', 'woo-bd-sms-notify' ) . '</p></div>';
			}
		}
	);
	return;
}

define( 'WOO_BD_SMS_FILE', __FILE__ );
define( 'WOO_BD_SMS_VERSION', '0.1.0' );

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	// PSR-4 autoloader for installs without Composer (the release zip).
	spl_autoload_register(
		static function ( $class_name ) {
			$prefixes = array(
				'Saizul\\BdSms\\WP\\' => __DIR__ . '/includes/',
				'Saizul\\BdSms\\'     => __DIR__ . '/src/',
			);
			foreach ( $prefixes as $prefix => $dir ) {
				if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
					continue;
				}
				$file = $dir . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
				return;
			}
		}
	);
}

// Declare compatibility with High-Performance Order Storage (custom order tables).
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

\Saizul\BdSms\WP\Plugin::boot( __FILE__ );
