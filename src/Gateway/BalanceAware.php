<?php
/**
 * Optional balance lookup.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Gateway;

/**
 * Gateways that can report the account balance.
 */
interface BalanceAware {

	/**
	 * Account balance as reported by the provider, or null when unavailable.
	 */
	public function balance(): ?string;
}
