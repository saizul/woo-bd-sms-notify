<?php
/**
 * Clock abstraction.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Support;

/**
 * Source of the current time, so expiry and rate windows can be tested.
 */
interface Clock {

	/**
	 * Current Unix timestamp in seconds.
	 */
	public function now(): int;
}
