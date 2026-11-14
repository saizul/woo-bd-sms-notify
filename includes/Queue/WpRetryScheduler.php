<?php
/**
 * Retry scheduling with Action Scheduler.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\WP\Queue;

use Saizul\BdSms\Retry\RetryScheduler;

/**
 * Schedules `woo_bd_sms_retry` actions. Uses Action Scheduler (shipped with
 * WooCommerce) and falls back to WP-Cron.
 */
final class WpRetryScheduler implements RetryScheduler {

	public const HOOK  = 'woo_bd_sms_retry';
	public const GROUP = 'woo-bd-sms';

	/**
	 * Schedule a retry.
	 *
	 * @param int $log_id Log entry id.
	 * @param int $delay Seconds from now.
	 */
	public function schedule( int $log_id, int $delay ): void {
		$when = time() + max( 1, $delay );
		$args = array( $log_id );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $when, self::HOOK, $args, self::GROUP );
			return;
		}
		wp_schedule_single_event( $when, self::HOOK, $args );
	}

	/**
	 * Run a callback in the background when possible, or now.
	 *
	 * @param string            $hook Action hook.
	 * @param array<int,scalar> $args Positional arguments.
	 * @return bool True when queued, false when the caller should run it now.
	 */
	public static function enqueue( string $hook, array $args ): bool {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( $hook, $args, self::GROUP );
			return true;
		}

		return false;
	}

	/**
	 * Remove every pending action of the plugin.
	 */
	public static function clear_all(): void {
		foreach ( array( self::HOOK, 'woo_bd_sms_order_status', 'woo_bd_sms_purge_log' ) as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, null, self::GROUP );
			}
			wp_unschedule_hook( $hook );
		}
	}
}
