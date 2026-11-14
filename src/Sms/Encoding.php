<?php
/**
 * SMS character sets.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Sms;

/**
 * GSM 03.38 default alphabet and its extension table.
 */
final class Encoding {

	public const GSM7 = 'GSM-7';
	public const UCS2 = 'UCS-2';

	/**
	 * Characters of the GSM 03.38 basic character set (one septet each).
	 * The escape character itself is left out: it is never typed by users.
	 */
	public const GSM7_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

	/**
	 * Characters of the extension table (escape + character: two septets each).
	 */
	public const GSM7_EXTENDED = "\f^{}\\[~]|€";

	/**
	 * Lookup tables, built once.
	 *
	 * @var array<string,int>|null
	 */
	private static $costs = null;

	/**
	 * Septet cost of a single character: 1, 2, or 0 when it is not in GSM-7.
	 *
	 * @param string $character One UTF-8 character.
	 */
	public static function gsm7_cost( string $character ): int {
		if ( null === self::$costs ) {
			self::$costs = array();
			foreach ( self::split( self::GSM7_BASIC ) as $char ) {
				self::$costs[ $char ] = 1;
			}
			foreach ( self::split( self::GSM7_EXTENDED ) as $char ) {
				self::$costs[ $char ] = 2;
			}
		}

		return self::$costs[ $character ] ?? 0;
	}

	/**
	 * UTF-16 code units needed for a character (2 for characters outside the BMP).
	 *
	 * @param string $character One UTF-8 character.
	 */
	public static function ucs2_units( string $character ): int {
		return strlen( $character ) >= 4 ? 2 : 1;
	}

	/**
	 * Split a UTF-8 string into characters.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function split( string $text ): array {
		if ( '' === $text ) {
			return array();
		}
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			// Invalid UTF-8: fall back to bytes so counting still terminates.
			$chars = str_split( $text );
		}

		return $chars;
	}

	/**
	 * Whether the text contains Bangla script (U+0980 to U+09FF).
	 *
	 * @param string $text Text.
	 */
	public static function has_bangla( string $text ): bool {
		return 1 === preg_match( '/[\x{0980}-\x{09FF}]/u', $text );
	}
}
