<?php
/**
 * Gateway that hands messages to other gateways.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Gateway;

/**
 * A gateway that forwards each message to one of several adapters and can
 * tell which one handled the last message, so the log records the provider
 * that was actually used.
 */
interface RoutedGateway extends Gateway {

	/**
	 * Id of the gateway that produced the last result ('' before the first send).
	 */
	public function last_gateway_id(): string;
}
