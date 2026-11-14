<?php
/**
 * Success rule for the generic gateway.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Gateway;

use InvalidArgumentException;
use Saizul\BdSms\Http\Response;
use Saizul\BdSms\Support\JsonPath;

/**
 * Decides whether a provider response means "accepted".
 *
 * Types:
 * - http_2xx:     any 2xx status.
 * - contains:     2xx and the body contains the value (case-insensitive).
 * - not_contains: 2xx and the body does not contain the value.
 * - regex:        2xx and the body matches the pattern (no delimiters, case-insensitive).
 * - json:         2xx and every condition holds. Conditions are joined with
 *                 "&&": "status=SUCCESS && code=200", "error!=1", "data.id" (present and not empty).
 */
final class SuccessMatcher {

	public const HTTP_2XX     = 'http_2xx';
	public const CONTAINS     = 'contains';
	public const NOT_CONTAINS = 'not_contains';
	public const REGEX        = 'regex';
	public const JSON         = 'json';

	public const TYPES = array( self::HTTP_2XX, self::CONTAINS, self::NOT_CONTAINS, self::REGEX, self::JSON );

	/**
	 * Matcher type.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Rule value.
	 *
	 * @var string
	 */
	private $value;

	/**
	 * Constructor.
	 *
	 * @param string $type One of the type constants.
	 * @param string $value Rule value.
	 * @throws InvalidArgumentException For an unknown type, empty value or invalid pattern.
	 */
	public function __construct( string $type, string $value = '' ) {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown success rule "%s".', $type ) );
		}
		$value = trim( $value );
		if ( self::HTTP_2XX !== $type && '' === $value ) {
			throw new InvalidArgumentException( 'The success rule needs a value.' );
		}
		if ( self::REGEX === $type && ! self::is_valid_pattern( $value ) ) {
			throw new InvalidArgumentException( 'The success pattern is not a valid regular expression.' );
		}
		$this->type  = $type;
		$this->value = $value;
	}

	/**
	 * Whether the response means the message was accepted.
	 *
	 * @param Response $response Response.
	 */
	public function matches( Response $response ): bool {
		if ( ! $response->is_success() ) {
			return false;
		}
		$body = $response->body();
		switch ( $this->type ) {
			case self::CONTAINS:
				return false !== stripos( $body, $this->value );
			case self::NOT_CONTAINS:
				return false === stripos( $body, $this->value );
			case self::REGEX:
				return 1 === preg_match( self::delimit( $this->value ), $body );
			case self::JSON:
				return $this->matches_json( $body );
			default:
				return true;
		}
	}

	/**
	 * Evaluate JSON conditions.
	 *
	 * @param string $body Body.
	 */
	private function matches_json( string $body ): bool {
		$json = JsonPath::decode( $body );
		if ( null === $json ) {
			return false;
		}
		foreach ( self::conditions( $this->value ) as $condition ) {
			list( $path, $operator, $expected ) = $condition;
			$actual                             = JsonPath::get( $json, $path );
			if ( '' === $operator ) {
				if ( null === $actual || '' === $actual || false === $actual || array() === $actual ) {
					return false;
				}
				continue;
			}
			$equal = null !== $actual && 0 === strcasecmp( JsonPath::stringify( $actual ), $expected );
			if ( ( '=' === $operator ) !== $equal ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Parse "a=b && c!=d && e".
	 *
	 * @param string $value Conditions.
	 * @return array<int,array{0:string,1:string,2:string}> [path, operator, expected].
	 */
	public static function conditions( string $value ): array {
		$conditions = array();
		foreach ( preg_split( '/\s*&&\s*|\n/', trim( $value ) ) ?: array() as $part ) { // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- preg_split returns false only on a bad pattern.
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			if ( preg_match( '/^([^=!]+?)\s*(!=|=)\s*(.*)$/', $part, $m ) ) {
				$conditions[] = array( trim( $m[1] ), $m[2], trim( $m[3], " \t\"'" ) );
			} else {
				$conditions[] = array( $part, '', '' );
			}
		}

		return $conditions;
	}

	/**
	 * Wrap a user pattern in delimiters.
	 *
	 * @param string $pattern Pattern without delimiters.
	 */
	private static function delimit( string $pattern ): string {
		return '~' . str_replace( '~', '\~', $pattern ) . '~i';
	}

	/**
	 * Whether a user pattern compiles.
	 *
	 * @param string $pattern Pattern without delimiters.
	 */
	public static function is_valid_pattern( string $pattern ): bool {
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Suppress the compile warning of an invalid user pattern.
			static function (): bool {
				return true;
			}
		);
		$result = preg_match( self::delimit( $pattern ), '' );
		restore_error_handler();

		return false !== $result;
	}
}
