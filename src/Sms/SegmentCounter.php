<?php
/**
 * SMS segment counting.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Sms;

/**
 * Works out the encoding and the number of SMS a text needs.
 *
 * - GSM-7: 160 septets in one SMS, 153 per part when concatenated. Characters
 *   of the extension table (such as € [ ] { } ~ ^ | \) take two septets and are
 *   never split across parts.
 * - UCS-2 (any character outside GSM-7, for example Bangla): 70 code units in
 *   one SMS, 67 per part. Characters outside the Basic Multilingual Plane
 *   (most emoji) take two units and are never split.
 */
final class SegmentCounter {

	public const GSM7_SINGLE = 160;
	public const GSM7_MULTI  = 153;
	public const UCS2_SINGLE = 70;
	public const UCS2_MULTI  = 67;

	/**
	 * Count segments.
	 *
	 * @param string $text Message text (UTF-8).
	 */
	public function count( string $text ): SegmentInfo {
		$chars    = Encoding::split( $text );
		$encoding = self::detect( $chars );
		$costs    = array();
		$units    = 0;
		foreach ( $chars as $char ) {
			$cost    = self::cost( $char, $encoding );
			$costs[] = $cost;
			$units  += $cost;
		}

		$single = Encoding::GSM7 === $encoding ? self::GSM7_SINGLE : self::UCS2_SINGLE;
		$multi  = Encoding::GSM7 === $encoding ? self::GSM7_MULTI : self::UCS2_MULTI;

		if ( 0 === $units ) {
			return new SegmentInfo( $encoding, 0, 0, array(), $single );
		}
		if ( $units <= $single ) {
			return new SegmentInfo( $encoding, $units, count( $chars ), array( $text ), $single );
		}

		$parts   = array();
		$current = '';
		$used    = 0;
		foreach ( $chars as $i => $char ) {
			if ( $used + $costs[ $i ] > $multi ) {
				$parts[] = $current;
				$current = '';
				$used    = 0;
			}
			$current .= $char;
			$used    += $costs[ $i ];
		}
		$parts[] = $current;

		return new SegmentInfo( $encoding, $units, count( $chars ), $parts, $multi );
	}

	/**
	 * Encoding a text needs.
	 *
	 * @param string $text Text.
	 */
	public function encoding_of( string $text ): string {
		return self::detect( Encoding::split( $text ) );
	}

	/**
	 * Length of a text in units of the given encoding.
	 *
	 * @param string $text Text.
	 * @param string $encoding Encoding constant.
	 */
	public static function units_of( string $text, string $encoding ): int {
		$units = 0;
		foreach ( Encoding::split( $text ) as $char ) {
			$units += self::cost( $char, $encoding );
		}

		return $units;
	}

	/**
	 * Detect the encoding of a list of characters.
	 *
	 * @param string[] $chars Characters.
	 */
	private static function detect( array $chars ): string {
		foreach ( $chars as $char ) {
			if ( 0 === Encoding::gsm7_cost( $char ) ) {
				return Encoding::UCS2;
			}
		}

		return Encoding::GSM7;
	}

	/**
	 * Cost of a character in an encoding.
	 *
	 * @param string $char Character.
	 * @param string $encoding Encoding.
	 */
	private static function cost( string $char, string $encoding ): int {
		return Encoding::GSM7 === $encoding ? Encoding::gsm7_cost( $char ) : Encoding::ucs2_units( $char );
	}
}
