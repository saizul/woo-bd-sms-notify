<?php
/**
 * Plugin bootstrap.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\WP;

use Saizul\BdSms\WP\Admin\AdminPage;
use Saizul\BdSms\WP\Checkout\CodOtp;
use Saizul\BdSms\WP\Cli\SmsCommand;
use Saizul\BdSms\WP\Db\Installer;
use Saizul\BdSms\WP\Order\OrderScreen;
use Saizul\BdSms\WP\Order\StatusNotifier;
use Saizul\BdSms\WP\Queue\WpRetryScheduler;

/**
 * Wires the SMS services into WordPress and WooCommerce.
 */
final class Plugin {

	public const VERSION    = '0.1.0';
	public const PURGE_HOOK = 'woo_bd_sms_purge_log';

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Main plugin file.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Services, available after init.
	 *
	 * @var Services|null
	 */
	private $services = null;

	/**
	 * Constructor.
	 *
	 * @param string $file Main plugin file.
	 */
	private function __construct( string $file ) {
		$this->file = $file;
	}

	/**
	 * Create the singleton and attach bootstrap hooks.
	 *
	 * @param string $file Main plugin file.
	 */
	public static function boot( string $file ): self {
		if ( null === self::$instance ) {
			self::$instance = new self( $file );
			register_activation_hook( $file, array( self::class, 'activate' ) );
			register_deactivation_hook( $file, array( self::class, 'deactivate' ) );
			add_action( 'plugins_loaded', array( self::$instance, 'init' ), 20 );
			add_action( 'init', array( self::$instance, 'load_textdomain' ) );
		}

		return self::$instance;
	}

	/**
	 * The running instance.
	 */
	public static function instance(): ?self {
		return self::$instance;
	}

	/**
	 * Capability required to manage SMS.
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed for the SMS settings, log and manual sends.
		 *
		 * @param string $capability Default manage_woocommerce.
		 */
		return (string) apply_filters( 'woo_bd_sms_capability', 'manage_woocommerce' );
	}

	/**
	 * Activation: create the log table and the purge event.
	 */
	public static function activate(): void {
		Installer::install();
		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/**
	 * Deactivation: stop scheduled work. Data is kept.
	 */
	public static function deactivate(): void {
		WpRetryScheduler::clear_all();
	}

	/**
	 * Initialise after WooCommerce is loaded.
	 */
	public function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_woocommerce_notice' ) );
			return;
		}

		Installer::maybe_install();

		$services       = new Services( new Settings( Services::registry() ) );
		$this->services = $services;
		$notifier       = new StatusNotifier( $services );
		$notifier->register();
		( new CodOtp( $services ) )->register();

		add_action(
			WpRetryScheduler::HOOK,
			static function ( $log_id ) use ( $services ) {
				$services->sender()->retry( (int) $log_id );
			}
		);
		add_action( self::PURGE_HOOK, array( $this, 'purge_log' ) );

		if ( is_admin() ) {
			( new AdminPage( $services ) )->register();
			( new OrderScreen( $services, $notifier ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'bd-sms', new SmsCommand( $services, $notifier ) );
		}

		/**
		 * Fires when the plugin is ready.
		 *
		 * @param Services $services Services.
		 */
		do_action( 'woo_bd_sms_loaded', $services );
	}

	/**
	 * Services (null before init or without WooCommerce).
	 */
	public function services(): ?Services {
		return $this->services;
	}

	/**
	 * Delete old log entries.
	 */
	public function purge_log(): void {
		if ( null === $this->services ) {
			return;
		}
		$days = (int) $this->services->settings->get( 'log_retention_days' );
		if ( $days > 0 ) {
			$this->services->log->purge( time() - $days * DAY_IN_SECONDS );
		}
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'woo-bd-sms-notify', false, dirname( plugin_basename( $this->file ) ) . '/languages' );
	}

	/**
	 * Notice shown when WooCommerce is not active.
	 */
	public function missing_woocommerce_notice(): void {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'SMS Notify for WooCommerce needs WooCommerce to be installed and active.', 'woo-bd-sms-notify' ) . '</p></div>';
		}
	}
}
