<?php
/**
 * One-time codes for checkout verification.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Otp;

use InvalidArgumentException;
use Saizul\BdSms\Support\Clock;
use Saizul\BdSms\Support\KeyValueStore;

/**
 * Issues and verifies numeric one-time codes bound to a phone number and a
 * context (the checkout session).
 *
 * - Codes come from random_int() and are stored only as an HMAC-SHA256 keyed
 *   with a site secret, the phone number and the context.
 * - Codes expire, allow a limited number of wrong guesses and are cleared on
 *   success so they cannot be replayed.
 * - Sending is limited by a cooldown between sends and a maximum number of
 *   sends per window.
 */
final class OtpManager {

	/**
	 * Storage.
	 *
	 * @var KeyValueStore
	 */
	private $store;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private $clock;

	/**
	 * HMAC key.
	 *
	 * @var string
	 */
	private $secret;

	/**
	 * Rules.
	 *
	 * @var OtpConfig
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param KeyValueStore $store Storage.
	 * @param Clock         $clock Clock.
	 * @param string        $secret HMAC key (at least 16 bytes).
	 * @param OtpConfig     $config Rules.
	 * @throws InvalidArgumentException When the secret is too short.
	 */
	public function __construct( KeyValueStore $store, Clock $clock, string $secret, OtpConfig $config ) {
		if ( strlen( $secret ) < 16 ) {
			throw new InvalidArgumentException( 'The OTP secret must be at least 16 bytes.' );
		}
		$this->store  = $store;
		$this->clock  = $clock;
		$this->secret = $secret;
		$this->config = $config;
	}

	/**
	 * Rules.
	 */
	public function config(): OtpConfig {
		return $this->config;
	}

	/**
	 * Generate a new code for a phone number.
	 *
	 * @param string $phone Normalised phone number.
	 * @param string $context Context such as the checkout session id.
	 * @throws OtpException When the cooldown or the send limit applies.
	 */
	public function issue( string $phone, string $context ): OtpChallenge {
		$now    = $this->clock->now();
		$record = $this->load( $phone, $context );

		$window_start = (int) ( $record['window_start'] ?? 0 );
		$sends        = (int) ( $record['sends'] ?? 0 );
		if ( $now - $window_start >= $this->config->send_window ) {
			$window_start = $now;
			$sends        = 0;
		}

		$last_sent = (int) ( $record['last_sent'] ?? 0 );
		if ( $last_sent > 0 && $now - $last_sent < $this->config->resend_cooldown ) {
			throw new OtpException( OtpException::COOLDOWN, $this->config->resend_cooldown - ( $now - $last_sent ) );
		}
		if ( $sends >= $this->config->max_sends ) {
			throw new OtpException( OtpException::TOO_MANY_SENDS, $window_start + $this->config->send_window - $now );
		}

		$code = $this->generate_code();
		++$sends;
		$this->save(
			$phone,
			$context,
			array(
				'hash'         => $this->hash( $phone, $context, $code ),
				'expires'      => $now + $this->config->ttl,
				'attempts'     => 0,
				'last_sent'    => $now,
				'sends'        => $sends,
				'window_start' => $window_start,
				'verified_at'  => 0,
			)
		);

		return new OtpChallenge( $code, $now + $this->config->ttl, $this->config->max_sends - $sends, $this->config->resend_cooldown );
	}

	/**
	 * Check a code entered by the customer.
	 *
	 * @param string $phone Normalised phone number.
	 * @param string $context Context.
	 * @param string $code Code as typed (spaces and Bangla digits allowed).
	 */
	public function verify( string $phone, string $context, string $code ): OtpResult {
		$record = $this->load( $phone, $context );
		if ( null === $record ) {
			return new OtpResult( OtpResult::MISSING );
		}
		$now = $this->clock->now();
		if ( (int) $record['verified_at'] > 0 ) {
			return $this->is_verified( $phone, $context ) ? new OtpResult( OtpResult::VERIFIED ) : new OtpResult( OtpResult::EXPIRED );
		}
		if ( '' === (string) $record['hash'] ) {
			return new OtpResult( OtpResult::MISSING );
		}
		if ( (int) $record['attempts'] >= $this->config->max_attempts ) {
			return new OtpResult( OtpResult::LOCKED );
		}
		if ( $now >= (int) $record['expires'] ) {
			return new OtpResult( OtpResult::EXPIRED );
		}

		$code = self::clean_code( $code );
		if ( strlen( $code ) === $this->config->length && hash_equals( (string) $record['hash'], $this->hash( $phone, $context, $code ) ) ) {
			$record['hash']        = '';
			$record['verified_at'] = $now;
			$this->save( $phone, $context, $record );

			return new OtpResult( OtpResult::VERIFIED );
		}

		$record['attempts'] = (int) $record['attempts'] + 1;
		$this->save( $phone, $context, $record );
		$left = $this->config->max_attempts - $record['attempts'];

		return $left > 0 ? new OtpResult( OtpResult::INVALID, $left ) : new OtpResult( OtpResult::LOCKED );
	}

	/**
	 * Whether the phone was verified in this context recently.
	 *
	 * @param string $phone Normalised phone number.
	 * @param string $context Context.
	 */
	public function is_verified( string $phone, string $context ): bool {
		$record = $this->load( $phone, $context );
		if ( null === $record || (int) $record['verified_at'] <= 0 ) {
			return false;
		}

		return $this->clock->now() - (int) $record['verified_at'] < $this->config->verified_ttl;
	}

	/**
	 * Forget the record (after the order was placed).
	 *
	 * @param string $phone Normalised phone number.
	 * @param string $context Context.
	 */
	public function consume( string $phone, string $context ): void {
		$this->store->delete( $this->key( $phone, $context ) );
	}

	/**
	 * Seconds until a new code may be sent (0 when allowed now).
	 *
	 * @param string $phone Normalised phone number.
	 * @param string $context Context.
	 */
	public function cooldown_left( string $phone, string $context ): int {
		$record = $this->load( $phone, $context );
		if ( null === $record ) {
			return 0;
		}
		$elapsed = $this->clock->now() - (int) $record['last_sent'];

		return max( 0, $this->config->resend_cooldown - $elapsed );
	}

	/**
	 * Accept spaces, dashes and Bangla digits in a typed code.
	 *
	 * @param string $code Code as typed.
	 */
	public static function clean_code( string $code ): string {
		$code = strtr(
			$code,
			array(
				'০' => '0',
				'১' => '1',
				'২' => '2',
				'৩' => '3',
				'৪' => '4',
				'৫' => '5',
				'৬' => '6',
				'৭' => '7',
				'৮' => '8',
				'৯' => '9',
			)
		);

		return (string) preg_replace( '/\D+/', '', $code );
	}

	/**
	 * Random numeric code with leading zeros allowed.
	 */
	private function generate_code(): string {
		$code = '';
		for ( $i = 0; $i < $this->config->length; $i++ ) {
			$code .= (string) random_int( 0, 9 );
		}

		return $code;
	}

	/**
	 * HMAC of a code bound to phone and context.
	 *
	 * @param string $phone Phone.
	 * @param string $context Context.
	 * @param string $code Code.
	 */
	private function hash( string $phone, string $context, string $code ): string {
		return hash_hmac( 'sha256', $phone . '|' . $context . '|' . $code, $this->secret );
	}

	/**
	 * Storage key; does not reveal the phone number.
	 *
	 * @param string $phone Phone.
	 * @param string $context Context.
	 */
	private function key( string $phone, string $context ): string {
		return 'bdsms_otp_' . substr( hash_hmac( 'sha256', $phone . '|' . $context, $this->secret ), 0, 32 );
	}

	/**
	 * Load a record.
	 *
	 * @param string $phone Phone.
	 * @param string $context Context.
	 * @return array<string,mixed>|null
	 */
	private function load( string $phone, string $context ): ?array {
		$record = $this->store->get( $this->key( $phone, $context ) );
		if ( ! is_array( $record ) || ! isset( $record['hash'], $record['expires'], $record['attempts'], $record['last_sent'] ) ) {
			return null;
		}

		return $record + array(
			'sends'        => 1,
			'window_start' => $record['last_sent'],
			'verified_at'  => 0,
		);
	}

	/**
	 * Save a record for as long as any of its limits matter.
	 *
	 * @param string              $phone Phone.
	 * @param string              $context Context.
	 * @param array<string,mixed> $record Record.
	 */
	private function save( string $phone, string $context, array $record ): void {
		$now = $this->clock->now();
		$ttl = max(
			(int) $record['expires'] - $now,
			(int) $record['window_start'] + $this->config->send_window - $now,
			(int) $record['verified_at'] > 0 ? (int) $record['verified_at'] + $this->config->verified_ttl - $now : 0,
			1
		);
		$this->store->set( $this->key( $phone, $context ), $record, $ttl );
	}
}
