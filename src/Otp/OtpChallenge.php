<?php
/**
 * Issued code.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Otp;

/**
 * A freshly generated code. The plain code only exists in this object, long
 * enough to be sent by SMS; storage only keeps its HMAC.
 */
final class OtpChallenge {

	/**
	 * Plain code.
	 *
	 * @var string
	 */
	private $code;

	/**
	 * Expiry timestamp.
	 *
	 * @var int
	 */
	private $expires_at;

	/**
	 * Sends left in the current window.
	 *
	 * @var int
	 */
	private $sends_left;

	/**
	 * Seconds until another code may be requested.
	 *
	 * @var int
	 */
	private $cooldown;

	/**
	 * Constructor.
	 *
	 * @param string $code Plain code.
	 * @param int    $expires_at Expiry.
	 * @param int    $sends_left Sends left.
	 * @param int    $cooldown Resend cooldown.
	 */
	public function __construct( string $code, int $expires_at, int $sends_left, int $cooldown ) {
		$this->code       = $code;
		$this->expires_at = $expires_at;
		$this->sends_left = $sends_left;
		$this->cooldown   = $cooldown;
	}

	/**
	 * Plain code.
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Expiry timestamp.
	 */
	public function expires_at(): int {
		return $this->expires_at;
	}

	/**
	 * Sends left in the current window.
	 */
	public function sends_left(): int {
		return $this->sends_left;
	}

	/**
	 * Seconds until another code may be requested.
	 */
	public function cooldown(): int {
		return $this->cooldown;
	}
}
