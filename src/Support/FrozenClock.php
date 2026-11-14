<?php
/**
 * Manually controlled clock.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Support;

/**
 * Clock that only moves when told to. Used by tests and dry runs.
 */
final class FrozenClock implements Clock {

	/**
	 * Current time.
	 *
	 * @var int
	 */
	private $now;

	/**
	 * Constructor.
	 *
	 * @param int $now Start time.
	 */
	public function __construct( int $now ) {
		$this->now = $now;
	}

	/**
	 * Current time.
	 */
	public function now(): int {
		return $this->now;
	}

	/**
	 * Move the clock forward.
	 *
	 * @param int $seconds Seconds to advance.
	 */
	public function advance( int $seconds ): void {
		$this->now += $seconds;
	}
}
