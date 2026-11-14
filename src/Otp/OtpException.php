<?php
/**
 * OTP issue refusal.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Otp;

use RuntimeException;

/**
 * Raised when a new code may not be sent yet.
 */
final class OtpException extends RuntimeException {

	public const COOLDOWN       = 'cooldown';
	public const TOO_MANY_SENDS = 'too_many_sends';

	/**
	 * Reason constant.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * Seconds until a new code may be requested.
	 *
	 * @var int
	 */
	private $retry_after;

	/**
	 * Constructor.
	 *
	 * @param string $reason Reason constant.
	 * @param int    $retry_after Seconds to wait.
	 */
	public function __construct( string $reason, int $retry_after ) {
		parent::__construct( sprintf( 'OTP refused (%1$s), retry in %2$d seconds.', $reason, $retry_after ) );
		$this->reason      = $reason;
		$this->retry_after = $retry_after;
	}

	/**
	 * Reason constant.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Seconds until a new code may be requested.
	 */
	public function retry_after(): int {
		return $this->retry_after;
	}
}
