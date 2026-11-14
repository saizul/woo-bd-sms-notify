<?php
/**
 * Gateway contract.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Gateway;

/**
 * An SMS provider adapter.
 */
interface Gateway {

	/**
	 * Machine id, such as "bulksmsbd".
	 */
	public function id(): string;

	/**
	 * Display name.
	 */
	public function label(): string;

	/**
	 * Configuration problems (missing credentials, malformed URL...).
	 *
	 * @return string[] Empty when the gateway can send.
	 */
	public function validate(): array;

	/**
	 * Send one message. Never throws for gateway or network errors.
	 *
	 * @param Message $message Message.
	 */
	public function send( Message $message ): SendResult;

	/**
	 * Configured secret values, removed from logs.
	 *
	 * @return string[]
	 */
	public function secrets(): array;
}
