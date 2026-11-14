<?php
/**
 * Verification outcome.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Otp;

/**
 * Result of checking a code.
 */
final class OtpResult {

	public const VERIFIED = 'verified';
	public const INVALID  = 'invalid';
	public const EXPIRED  = 'expired';
	public const LOCKED   = 'locked';
	public const MISSING  = 'missing';

	/**
	 * Status constant.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Guesses left for the current code.
	 *
	 * @var int
	 */
	private $attempts_left;

	/**
	 * Constructor.
	 *
	 * @param string $status Status constant.
	 * @param int    $attempts_left Guesses left.
	 */
	public function __construct( string $status, int $attempts_left = 0 ) {
		$this->status        = $status;
		$this->attempts_left = $attempts_left;
	}

	/**
	 * Status constant.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Whether the code was correct.
	 */
	public function is_verified(): bool {
		return self::VERIFIED === $this->status;
	}

	/**
	 * Guesses left for the current code.
	 */
	public function attempts_left(): int {
		return $this->attempts_left;
	}
}
