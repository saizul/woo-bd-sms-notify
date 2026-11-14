<?php
/**
 * Sender settings.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Sms;

/**
 * Limits applied by {@see SmsSender}.
 */
final class SenderOptions {

	/**
	 * SMS per phone number per window (0 = unlimited).
	 *
	 * @var int
	 */
	public $per_phone_limit;

	/**
	 * Per-phone window in seconds.
	 *
	 * @var int
	 */
	public $per_phone_window;

	/**
	 * SMS for the whole site per window (0 = unlimited).
	 *
	 * @var int
	 */
	public $global_limit;

	/**
	 * Global window in seconds.
	 *
	 * @var int
	 */
	public $global_window;

	/**
	 * Longest message in segments; longer ones are not sent.
	 *
	 * @var int
	 */
	public $max_segments;

	/**
	 * Replace typographic punctuation with GSM-7 characters.
	 *
	 * @var bool
	 */
	public $plain_punctuation;

	/**
	 * Constructor.
	 *
	 * @param int  $per_phone_limit Per-phone limit.
	 * @param int  $per_phone_window Per-phone window.
	 * @param int  $global_limit Global limit.
	 * @param int  $global_window Global window.
	 * @param int  $max_segments Maximum segments.
	 * @param bool $plain_punctuation Replace punctuation.
	 */
	public function __construct( int $per_phone_limit = 10, int $per_phone_window = 3600, int $global_limit = 120, int $global_window = 60, int $max_segments = 6, bool $plain_punctuation = true ) {
		$this->per_phone_limit   = max( 0, $per_phone_limit );
		$this->per_phone_window  = max( 1, $per_phone_window );
		$this->global_limit      = max( 0, $global_limit );
		$this->global_window     = max( 1, $global_window );
		$this->max_segments      = max( 1, $max_segments );
		$this->plain_punctuation = $plain_punctuation;
	}

	/**
	 * Build from the settings array.
	 *
	 * @param array<string,mixed> $settings Settings ("rate" section and "max_segments").
	 */
	public static function from_settings( array $settings ): self {
		$rate = isset( $settings['rate'] ) && is_array( $settings['rate'] ) ? $settings['rate'] : array();

		return new self(
			(int) ( $rate['per_phone_limit'] ?? 10 ),
			(int) ( $rate['per_phone_window'] ?? 3600 ),
			(int) ( $rate['global_limit'] ?? 120 ),
			(int) ( $rate['global_window'] ?? 60 ),
			(int) ( $settings['max_segments'] ?? 6 ),
			! isset( $settings['plain_punctuation'] ) || ! empty( $settings['plain_punctuation'] )
		);
	}
}
