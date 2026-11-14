<?php
/**
 * Masking of personal data and secrets for display.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Support;

/**
 * Helpers that hide most of a phone number, OTP or API key.
 */
final class Masker {

	/**
	 * 01711234567 becomes 0171****567.
	 *
	 * @param string $phone Phone number.
	 */
	public static function phone( string $phone ): string {
		$length = strlen( $phone );
		if ( $length <= 7 ) {
			return str_repeat( '*', $length );
		}

		return substr( $phone, 0, 4 ) . str_repeat( '*', $length - 7 ) . substr( $phone, -3 );
	}

	/**
	 * Keep the last four characters of a secret.
	 *
	 * @param string $secret Secret value.
	 */
	public static function secret( string $secret ): string {
		$length = strlen( $secret );
		if ( 0 === $length ) {
			return '';
		}
		if ( $length <= 8 ) {
			return str_repeat( '*', $length );
		}

		return str_repeat( '*', 8 ) . substr( $secret, -4 );
	}

	/**
	 * Remove secrets from a string (URLs, request bodies, responses).
	 *
	 * @param string   $text Text to clean.
	 * @param string[] $secrets Values to hide.
	 */
	public static function redact( string $text, array $secrets ): string {
		foreach ( $secrets as $secret ) {
			$secret = (string) $secret;
			if ( strlen( $secret ) >= 4 ) {
				$text = str_replace( array( $secret, rawurlencode( $secret ), urlencode( $secret ) ), '[redacted]', $text ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- Form bodies encode spaces as "+".
			}
		}

		return $text;
	}
}
