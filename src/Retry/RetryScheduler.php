<?php
/**
 * Deferred retries.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Retry;

/**
 * Schedules a later attempt for a logged message. The plugin uses Action
 * Scheduler (bundled with WooCommerce).
 */
interface RetryScheduler {

	/**
	 * Schedule a retry.
	 *
	 * @param int $log_id Log entry id.
	 * @param int $delay Seconds from now.
	 */
	public function schedule( int $log_id, int $delay ): void;
}
