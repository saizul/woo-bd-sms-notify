<?php
/**
 * Transient-backed store.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\WP\Store;

use Saizul\BdSms\Support\KeyValueStore;

/**
 * {@see KeyValueStore} on top of WordPress transients (and therefore the
 * object cache when one is installed).
 */
final class TransientStore implements KeyValueStore {

	/**
	 * Read.
	 *
	 * @param string $key Key.
	 * @return mixed|null
	 */
	public function get( string $key ) {
		$value = get_transient( $this->name( $key ) );

		return false === $value ? null : $value;
	}

	/**
	 * Write.
	 *
	 * @param string $key Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl Seconds.
	 */
	public function set( string $key, $value, int $ttl ): void {
		set_transient( $this->name( $key ), $value, max( 1, $ttl ) );
	}

	/**
	 * Delete.
	 *
	 * @param string $key Key.
	 */
	public function delete( string $key ): void {
		delete_transient( $this->name( $key ) );
	}

	/**
	 * Transient name (at most 172 characters).
	 *
	 * @param string $key Key.
	 */
	private function name( string $key ): string {
		$name = 'woo_bd_sms_' . $key;

		return strlen( $name ) > 172 ? 'woo_bd_sms_' . md5( $key ) : $name;
	}
}
