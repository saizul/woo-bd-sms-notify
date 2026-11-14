<?php
/**
 * Retry schedule.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Retry;

use Saizul\BdSms\Gateway\SendResult;

/**
 * Exponential back-off for messages a gateway could not take.
 *
 * Attempt 1 is the first send. With the defaults (3 attempts, 60 s base,
 * factor 4) a failed message is retried after 1 and 4 minutes.
 */
final class RetryPolicy {

	/**
	 * Total attempts including the first send.
	 *
	 * @var int
	 */
	private $max_attempts;

	/**
	 * Delay before the first retry, in seconds.
	 *
	 * @var int
	 */
	private $base_delay;

	/**
	 * Growth factor.
	 *
	 * @var int
	 */
	private $factor;

	/**
	 * Upper bound for a delay, in seconds.
	 *
	 * @var int
	 */
	private $max_delay;

	/**
	 * Constructor.
	 *
	 * @param int $max_attempts Total attempts (1 disables retries).
	 * @param int $base_delay First retry delay.
	 * @param int $factor Growth factor.
	 * @param int $max_delay Delay cap.
	 */
	public function __construct( int $max_attempts = 3, int $base_delay = 60, int $factor = 4, int $max_delay = 3600 ) {
		$this->max_attempts = max( 1, min( 10, $max_attempts ) );
		$this->base_delay   = max( 1, $base_delay );
		$this->factor       = max( 1, $factor );
		$this->max_delay    = max( $this->base_delay, $max_delay );
	}

	/**
	 * Total attempts.
	 */
	public function max_attempts(): int {
		return $this->max_attempts;
	}

	/**
	 * Whether a failed attempt should be retried.
	 *
	 * @param SendResult $result Result of the attempt.
	 * @param int        $attempt Number of the attempt that just failed (1-based).
	 */
	public function should_retry( SendResult $result, int $attempt ): bool {
		return ! $result->is_success() && $result->is_retryable() && $this->has_attempts_left( $attempt );
	}

	/**
	 * Whether another attempt is allowed after this one.
	 *
	 * @param int $attempt Number of the attempt that just happened (1-based).
	 */
	public function has_attempts_left( int $attempt ): bool {
		return $attempt < $this->max_attempts;
	}

	/**
	 * Delay before the next attempt.
	 *
	 * @param int $attempt Number of the attempt that just failed (1-based).
	 */
	public function delay( int $attempt ): int {
		$delay = $this->base_delay;
		for ( $i = 1; $i < $attempt; $i++ ) {
			$delay *= $this->factor;
			if ( $delay >= $this->max_delay ) {
				return $this->max_delay;
			}
		}

		return min( $delay, $this->max_delay );
	}
}
