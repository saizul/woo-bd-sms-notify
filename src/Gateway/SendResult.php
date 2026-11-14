<?php
/**
 * Gateway outcome.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Gateway;

/**
 * Whether a gateway accepted a message, and why not.
 */
final class SendResult {

	/**
	 * Accepted by the gateway.
	 *
	 * @var bool
	 */
	private $success;

	/**
	 * Gateway message or request id.
	 *
	 * @var string
	 */
	private $message_id;

	/**
	 * Gateway or plugin error code.
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * Human-readable error.
	 *
	 * @var string
	 */
	private $error_message;

	/**
	 * Whether trying again later could succeed.
	 *
	 * @var bool
	 */
	private $retryable;

	/**
	 * HTTP status, 0 when no response was received.
	 *
	 * @var int
	 */
	private $http_status;

	/**
	 * Response body (truncated).
	 *
	 * @var string
	 */
	private $raw;

	/**
	 * Constructor.
	 *
	 * @param bool   $success Accepted.
	 * @param string $message_id Gateway id.
	 * @param string $error_code Error code.
	 * @param string $error_message Error message.
	 * @param bool   $retryable Retryable.
	 * @param int    $http_status HTTP status.
	 * @param string $raw Response body.
	 */
	private function __construct( bool $success, string $message_id, string $error_code, string $error_message, bool $retryable, int $http_status, string $raw ) {
		$this->success       = $success;
		$this->message_id    = $message_id;
		$this->error_code    = $error_code;
		$this->error_message = $error_message;
		$this->retryable     = $retryable;
		$this->http_status   = $http_status;
		$this->raw           = self::truncate( $raw );
	}

	/**
	 * Accepted.
	 *
	 * @param string $message_id Gateway id.
	 * @param int    $http_status HTTP status.
	 * @param string $raw Response body.
	 */
	public static function sent( string $message_id, int $http_status = 200, string $raw = '' ): self {
		return new self( true, $message_id, '', '', false, $http_status, $raw );
	}

	/**
	 * Rejected.
	 *
	 * @param string $error_code Error code.
	 * @param string $error_message Error message.
	 * @param bool   $retryable Whether to try again.
	 * @param int    $http_status HTTP status.
	 * @param string $raw Response body.
	 */
	public static function failed( string $error_code, string $error_message, bool $retryable, int $http_status = 0, string $raw = '' ): self {
		return new self( false, '', $error_code, $error_message, $retryable, $http_status, $raw );
	}

	/**
	 * Accepted by the gateway.
	 */
	public function is_success(): bool {
		return $this->success;
	}

	/**
	 * Gateway id.
	 */
	public function message_id(): string {
		return $this->message_id;
	}

	/**
	 * Error code.
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Error message.
	 */
	public function error_message(): string {
		return $this->error_message;
	}

	/**
	 * Whether trying again later could succeed.
	 */
	public function is_retryable(): bool {
		return $this->retryable;
	}

	/**
	 * HTTP status.
	 */
	public function http_status(): int {
		return $this->http_status;
	}

	/**
	 * Response body (at most 2000 bytes).
	 */
	public function raw(): string {
		return $this->raw;
	}

	/**
	 * Copy with secrets removed from the raw body.
	 *
	 * @param string[] $secrets Values to hide.
	 */
	public function redacted( array $secrets ): self {
		$copy      = clone $this;
		$copy->raw = \Saizul\BdSms\Support\Masker::redact( $this->raw, $secrets );

		return $copy;
	}

	/**
	 * Cut a string to 2000 bytes without breaking UTF-8.
	 *
	 * @param string $text Text.
	 */
	private static function truncate( string $text ): string {
		if ( strlen( $text ) <= 2000 ) {
			return $text;
		}
		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $text, 0, 2000, 'UTF-8' ) . '...';
		}
		$cut = substr( $text, 0, 2000 );
		// Drop bytes of a character cut in half.
		$tries = 0;
		while ( $tries < 3 && 1 !== preg_match( '//u', $cut ) ) {
			$cut = substr( $cut, 0, -1 );
			++$tries;
		}

		return $cut . '...';
	}
}
