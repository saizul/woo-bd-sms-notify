<?php
/**
 * OTP rules.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Otp;

/**
 * Length, lifetime and abuse limits of one-time codes.
 */
final class OtpConfig {

	/**
	 * Digits in a code (4 to 8).
	 *
	 * @var int
	 */
	public $length;

	/**
	 * Seconds a code is valid.
	 *
	 * @var int
	 */
	public $ttl;

	/**
	 * Wrong guesses allowed per code.
	 *
	 * @var int
	 */
	public $max_attempts;

	/**
	 * Seconds between two sends to the same number.
	 *
	 * @var int
	 */
	public $resend_cooldown;

	/**
	 * Codes that may be sent to one number per send window.
	 *
	 * @var int
	 */
	public $max_sends;

	/**
	 * Length of the send window in seconds.
	 *
	 * @var int
	 */
	public $send_window;

	/**
	 * Seconds a successful verification stays valid for checkout.
	 *
	 * @var int
	 */
	public $verified_ttl;

	/**
	 * Constructor.
	 *
	 * @param int $length Digits.
	 * @param int $ttl Code lifetime.
	 * @param int $max_attempts Wrong guesses per code.
	 * @param int $resend_cooldown Seconds between sends.
	 * @param int $max_sends Sends per window.
	 * @param int $send_window Window length.
	 * @param int $verified_ttl Lifetime of a verification.
	 */
	public function __construct( int $length = 6, int $ttl = 300, int $max_attempts = 5, int $resend_cooldown = 60, int $max_sends = 3, int $send_window = 3600, int $verified_ttl = 1800 ) {
		$this->length          = max( 4, min( 8, $length ) );
		$this->ttl             = max( 60, min( 3600, $ttl ) );
		$this->max_attempts    = max( 1, min( 10, $max_attempts ) );
		$this->resend_cooldown = max( 0, min( 3600, $resend_cooldown ) );
		$this->max_sends       = max( 1, min( 20, $max_sends ) );
		$this->send_window     = max( 60, min( 86400, $send_window ) );
		$this->verified_ttl    = max( 60, min( 86400, $verified_ttl ) );
	}

	/**
	 * Build from settings values.
	 *
	 * @param array<string,mixed> $settings OTP settings.
	 */
	public static function from_array( array $settings ): self {
		return new self(
			(int) ( $settings['length'] ?? 6 ),
			(int) ( $settings['ttl'] ?? 300 ),
			(int) ( $settings['max_attempts'] ?? 5 ),
			(int) ( $settings['resend_cooldown'] ?? 60 ),
			(int) ( $settings['max_sends'] ?? 3 ),
			(int) ( $settings['send_window'] ?? 3600 ),
			(int) ( $settings['verified_ttl'] ?? 1800 )
		);
	}
}
