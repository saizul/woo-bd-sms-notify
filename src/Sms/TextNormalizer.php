<?php
/**
 * Message text clean-up.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Sms;

/**
 * Cleans message text before it is sent.
 *
 * A single curly quote or en dash pasted from a word processor turns an
 * English message into Unicode and more than halves its capacity, so these
 * characters can be replaced by their GSM-7 equivalents. Bangla text is
 * always left untouched.
 */
final class TextNormalizer {

	/**
	 * Typographic characters and their plain replacements.
	 *
	 * @var array<string,string>
	 */
	private const PUNCTUATION = array(
		"\u{2018}" => "'",
		"\u{2019}" => "'",
		"\u{201A}" => "'",
		"\u{201B}" => "'",
		"\u{2032}" => "'",
		"\u{201C}" => '"',
		"\u{201D}" => '"',
		"\u{201E}" => '"',
		"\u{2033}" => '"',
		"\u{2013}" => '-',
		"\u{2014}" => '-',
		"\u{2212}" => '-',
		"\u{2026}" => '...',
		"\u{00A0}" => ' ',
		"\u{2009}" => ' ',
		"\u{200A}" => ' ',
		"\u{202F}" => ' ',
		"\u{2022}" => '-',
		"\u{00B4}" => "'",
		"\u{0060}" => "'",
		"\u{00AB}" => '"',
		"\u{00BB}" => '"',
		"\t"       => ' ',
	);

	/**
	 * Strip markup, normalise line endings and trim spaces.
	 *
	 * @param string $text Text.
	 */
	public function clean( string $text ): string {
		$text = html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		// Remove zero-width and other invisible format characters except ZWJ/ZWNJ, which Bangla uses.
		$text = (string) preg_replace( '/[\x{200B}\x{2060}\x{FEFF}]/u', '', $text );
		$text = (string) preg_replace( '/[ \t]+/', ' ', $text );
		$text = (string) preg_replace( '/ *\n */', "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Replace typographic punctuation with GSM-7 characters.
	 *
	 * @param string $text Text.
	 */
	public function to_gsm_punctuation( string $text ): string {
		return strtr( $text, self::PUNCTUATION );
	}

	/**
	 * Clean the text and, when enabled, replace typographic punctuation.
	 *
	 * @param string $text Text.
	 * @param bool   $plain_punctuation Whether to replace typographic punctuation.
	 */
	public function normalize( string $text, bool $plain_punctuation = true ): string {
		$text = $this->clean( $text );

		return $plain_punctuation ? $this->to_gsm_punctuation( $text ) : $text;
	}
}
