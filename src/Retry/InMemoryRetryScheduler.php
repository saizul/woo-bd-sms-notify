<?php
/**
 * Recording retry scheduler.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Retry;

/**
 * Keeps scheduled retries in memory (tests, CLI dry runs).
 */
final class InMemoryRetryScheduler implements RetryScheduler {

	/**
	 * Scheduled retries as [log_id, delay].
	 *
	 * @var array<int,array{0:int,1:int}>
	 */
	public $scheduled = array();

	/**
	 * Schedule a retry.
	 *
	 * @param int $log_id Log entry id.
	 * @param int $delay Seconds from now.
	 */
	public function schedule( int $log_id, int $delay ): void {
		$this->scheduled[] = array( $log_id, $delay );
	}
}
