<?php
/**
 * Placeholder substitution for SMS templates.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Template;

/**
 * Replaces {placeholder} and {placeholder|fallback} tokens.
 *
 * - Names are case-insensitive and may contain letters, digits, "_" and ".".
 * - A missing or empty value is replaced by the fallback (or nothing).
 * - Unknown placeholders are removed, so customers never see raw braces.
 * - Use {{ and }} for literal braces.
 *
 * Values are inserted as plain text; templates cannot run code.
 */
final class TemplateRenderer {

	private const PATTERN = '/\{\s*([a-z0-9_.]+)\s*(?:\|([^{}]*))?\}/i';

	/**
	 * Render a template.
	 *
	 * @param string                    $template Template text.
	 * @param array<string,scalar|null> $values Placeholder values.
	 */
	public function render( string $template, array $values ): string {
		$values = array_change_key_case( $values, CASE_LOWER );
		$open   = "\u{E000}";
		$close  = "\u{E001}";
		$text   = str_replace( array( '{{', '}}' ), array( $open, $close ), $template );

		$text = (string) preg_replace_callback(
			self::PATTERN,
			static function ( array $m ) use ( $values ): string {
				$name  = strtolower( $m[1] );
				$value = isset( $values[ $name ] ) ? trim( (string) $values[ $name ] ) : '';
				if ( '' === $value ) {
					return isset( $m[2] ) ? trim( $m[2] ) : '';
				}

				return $value;
			},
			$text
		);

		return str_replace( array( $open, $close ), array( '{', '}' ), $text );
	}

	/**
	 * Placeholders used by a template.
	 *
	 * @param string $template Template text.
	 * @return string[] Lower-case names in order of first use.
	 */
	public function placeholders( string $template ): array {
		$template = str_replace( array( '{{', '}}' ), '', $template );
		preg_match_all( self::PATTERN, $template, $matches );
		$names = array_map( 'strtolower', $matches[1] );

		return array_values( array_unique( $names ) );
	}

	/**
	 * Placeholders in a template that are not in the list of known names.
	 *
	 * @param string   $template Template text.
	 * @param string[] $known Known placeholder names.
	 * @return string[]
	 */
	public function unknown_placeholders( string $template, array $known ): array {
		$known = array_map( 'strtolower', $known );

		return array_values( array_diff( $this->placeholders( $template ), $known ) );
	}
}
