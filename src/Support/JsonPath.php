<?php
/**
 * Dotted path lookup in decoded JSON.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Support;

/**
 * Reads values such as "data.request_id" or "smsinfo.0.reference_id".
 */
final class JsonPath {

	/**
	 * Look up a dotted path.
	 *
	 * @param mixed  $data Decoded JSON (arrays).
	 * @param string $path Dotted path; numeric segments index lists.
	 * @return mixed|null The value, or null when any segment is missing.
	 */
	public static function get( $data, string $path ) {
		$path = trim( $path );
		if ( '' === $path ) {
			return $data;
		}
		foreach ( explode( '.', $path ) as $segment ) {
			if ( is_array( $data ) && array_key_exists( $segment, $data ) ) {
				$data = $data[ $segment ];
				continue;
			}
			if ( is_array( $data ) && ctype_digit( $segment ) && array_key_exists( (int) $segment, $data ) ) {
				$data = $data[ (int) $segment ];
				continue;
			}
			return null;
		}

		return $data;
	}

	/**
	 * Decode a JSON body into arrays, or null when it is not JSON.
	 *
	 * @param string $body Response body.
	 * @return array<mixed>|null
	 */
	public static function decode( string $body ): ?array {
		$body = trim( $body );
		if ( '' === $body || ( '{' !== $body[0] && '[' !== $body[0] ) ) {
			return null;
		}
		$decoded = json_decode( $body, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Convert a JSON scalar to a comparable string ("true", "0", "SUCCESS").
	 *
	 * @param mixed $value Value.
	 */
	public static function stringify( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( null === $value ) {
			return 'null';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Library code that runs without WordPress.
	}
}
