<?php
/**
 * Wall clock.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Support;

/**
 * Clock backed by time().
 */
final class SystemClock implements Clock {

	/**
	 * Current Unix timestamp.
	 */
	public function now(): int {
		return time();
	}
}
