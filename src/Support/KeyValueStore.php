<?php
/**
 * Expiring key-value storage.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Support;

/**
 * Minimal store used for rate-limit counters and OTP records.
 *
 * The WordPress implementation uses transients; tests use {@see ArrayStore}.
 */
interface KeyValueStore {

	/**
	 * Read a value.
	 *
	 * @param string $key Key.
	 * @return mixed|null Stored value, or null when missing or expired.
	 */
	public function get( string $key );

	/**
	 * Store a value.
	 *
	 * @param string $key Key.
	 * @param mixed  $value Value (scalar or array).
	 * @param int    $ttl Lifetime in seconds, at least 1.
	 */
	public function set( string $key, $value, int $ttl ): void;

	/**
	 * Remove a value.
	 *
	 * @param string $key Key.
	 */
	public function delete( string $key ): void;
}
