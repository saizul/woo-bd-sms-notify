<?php
/**
 * When to switch to the backup gateway.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Gateway;

/**
 * Rules for {@see FailoverGateway}.
 *
 * - "temporary" (default) moves a message to the backup gateway only when the
 *   primary failure could pass with time: network errors, HTTP 5xx and 429,
 *   and provider codes the adapter marks as retryable. A rejected number or
 *   text is not sent again through another provider.
 * - "any" also fails over on permanent errors, for example a missing API key,
 *   an empty balance or a sender ID the primary provider has not approved.
 *
 * Extra provider codes (such as BulkSMSBD 1007, insufficient balance) can be
 * listed to fail over on them in "temporary" mode as well.
 *
 * After `threshold` consecutive primary failures the primary is skipped for
 * `cooldown` seconds (a simple circuit breaker), so a dead provider does not
 * add a timeout to every SMS. 0 disables the breaker.
 */
final class FailoverPolicy {

	public const MODE_TEMPORARY = 'temporary';
	public const MODE_ANY       = 'any';

	/**
	 * Mode.
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Extra error codes that trigger a failover.
	 *
	 * @var string[]
	 */
	private $codes;

	/**
	 * Consecutive failures before the primary is skipped (0 = never).
	 *
	 * @var int
	 */
	private $threshold;

	/**
	 * Seconds the primary is skipped for.
	 *
	 * @var int
	 */
	private $cooldown;

	/**
	 * Constructor.
	 *
	 * @param string   $mode "temporary" or "any".
	 * @param string[] $codes Extra error codes.
	 * @param int      $threshold Breaker threshold, 0 disables it.
	 * @param int      $cooldown Breaker cooldown in seconds.
	 */
	public function __construct( string $mode = self::MODE_TEMPORARY, array $codes = array(), int $threshold = 3, int $cooldown = 300 ) {
		$this->mode      = self::MODE_ANY === $mode ? self::MODE_ANY : self::MODE_TEMPORARY;
		$this->codes     = self::parse_codes( $codes );
		$this->threshold = max( 0, min( 100, $threshold ) );
		$this->cooldown  = max( 10, min( 86400, $cooldown ) );
	}

	/**
	 * Build from the "failover" settings section.
	 *
	 * @param array<string,mixed> $section Settings section.
	 */
	public static function from_array( array $section ): self {
		$codes = $section['codes'] ?? array();
		if ( is_string( $codes ) ) {
			$codes = explode( ',', $codes );
		}

		return new self(
			(string) ( $section['mode'] ?? self::MODE_TEMPORARY ),
			is_array( $codes ) ? array_map( 'strval', $codes ) : array(),
			(int) ( $section['threshold'] ?? 3 ),
			(int) ( $section['cooldown'] ?? 300 )
		);
	}

	/**
	 * Clean a list of codes: trimmed, unique, at most 40 characters each.
	 *
	 * @param string[] $codes Codes.
	 * @return string[]
	 */
	public static function parse_codes( array $codes ): array {
		$out = array();
		foreach ( $codes as $code ) {
			$code = (string) preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $code );
			if ( '' !== $code && strlen( $code ) <= 40 ) {
				$out[ $code ] = $code;
			}
		}

		return array_values( $out );
	}

	/**
	 * Whether a primary failure should be sent through the backup.
	 *
	 * @param SendResult $result Primary result.
	 */
	public function should_fail_over( SendResult $result ): bool {
		if ( $result->is_success() ) {
			return false;
		}
		if ( self::MODE_ANY === $this->mode || $result->is_retryable() ) {
			return true;
		}

		return in_array( $result->error_code(), $this->codes, true );
	}

	/**
	 * Mode.
	 */
	public function mode(): string {
		return $this->mode;
	}

	/**
	 * Extra codes.
	 *
	 * @return string[]
	 */
	public function codes(): array {
		return $this->codes;
	}

	/**
	 * Breaker threshold (0 = disabled).
	 */
	public function threshold(): int {
		return $this->threshold;
	}

	/**
	 * Breaker cooldown in seconds.
	 */
	public function cooldown(): int {
		return $this->cooldown;
	}
}
