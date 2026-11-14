<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Otp;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Otp\OtpConfig;
use Saizul\BdSms\Otp\OtpException;
use Saizul\BdSms\Otp\OtpManager;
use Saizul\BdSms\Otp\OtpResult;
use Saizul\BdSms\Support\ArrayStore;
use Saizul\BdSms\Support\FrozenClock;

final class OtpManagerTest extends TestCase {

	private const PHONE   = '01711234567';
	private const CONTEXT = 'session-abc';

	/** @var FrozenClock */
	private $clock;

	/** @var ArrayStore */
	private $store;

	protected function setUp(): void {
		$this->clock = new FrozenClock( 1700000000 );
		$this->store = new ArrayStore( $this->clock );
	}

	private function manager( ?OtpConfig $config = null ): OtpManager {
		return new OtpManager( $this->store, $this->clock, str_repeat( 'k', 32 ), $config ?? new OtpConfig( 6, 300, 3, 60, 3, 3600, 1800 ) );
	}

	public function test_issue_and_verify(): void {
		$manager   = $this->manager();
		$challenge = $manager->issue( self::PHONE, self::CONTEXT );

		self::assertMatchesRegularExpression( '/^\d{6}$/', $challenge->code() );
		self::assertSame( 1700000300, $challenge->expires_at() );
		self::assertSame( 2, $challenge->sends_left() );
		self::assertSame( 60, $challenge->cooldown() );
		self::assertFalse( $manager->is_verified( self::PHONE, self::CONTEXT ) );

		$result = $manager->verify( self::PHONE, self::CONTEXT, $challenge->code() );
		self::assertTrue( $result->is_verified() );
		self::assertTrue( $manager->is_verified( self::PHONE, self::CONTEXT ) );
	}

	public function test_code_is_not_stored_in_plain_text(): void {
		$manager   = $this->manager();
		$challenge = $manager->issue( self::PHONE, self::CONTEXT );
		$dump      = serialize( ( function () {
			return $this->items; // phpcs:ignore
		} )->call( $this->store ) );

		self::assertStringNotContainsString( '"' . $challenge->code() . '"', $dump );
		self::assertStringNotContainsString( self::PHONE, $dump );
	}

	public function test_typed_codes_are_cleaned(): void {
		$manager   = $this->manager();
		$challenge = $manager->issue( self::PHONE, self::CONTEXT );
		$bangla    = strtr( $challenge->code(), array( '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯' ) );

		self::assertSame( '123456', OtpManager::clean_code( ' 123-456 ' ) );
		self::assertTrue( $manager->verify( self::PHONE, self::CONTEXT, mb_substr( $bangla, 0, 3 ) . ' ' . mb_substr( $bangla, 3 ) )->is_verified() );
	}

	public function test_wrong_codes_lock_the_challenge(): void {
		$manager   = $this->manager();
		$challenge = $manager->issue( self::PHONE, self::CONTEXT );
		$wrong     = '000000' === $challenge->code() ? '111111' : '000000';

		$first = $manager->verify( self::PHONE, self::CONTEXT, $wrong );
		self::assertSame( OtpResult::INVALID, $first->status() );
		self::assertSame( 2, $first->attempts_left() );
		self::assertSame( 1, $manager->verify( self::PHONE, self::CONTEXT, $wrong )->attempts_left() );
		self::assertSame( OtpResult::LOCKED, $manager->verify( self::PHONE, self::CONTEXT, $wrong )->status() );

		// Even the right code fails once locked.
		self::assertSame( OtpResult::LOCKED, $manager->verify( self::PHONE, self::CONTEXT, $challenge->code() )->status() );
	}

	public function test_codes_expire(): void {
		$manager   = $this->manager();
		$challenge = $manager->issue( self::PHONE, self::CONTEXT );
		$this->clock->advance( 300 );

		self::assertSame( OtpResult::EXPIRED, $manager->verify( self::PHONE, self::CONTEXT, $challenge->code() )->status() );
	}

	public function test_codes_are_bound_to_phone_and_context(): void {
		$manager   = $this->manager();
		$challenge = $manager->issue( self::PHONE, self::CONTEXT );

		self::assertSame( OtpResult::MISSING, $manager->verify( '01811234567', self::CONTEXT, $challenge->code() )->status() );
		self::assertSame( OtpResult::MISSING, $manager->verify( self::PHONE, 'other-session', $challenge->code() )->status() );
		self::assertTrue( $manager->verify( self::PHONE, self::CONTEXT, $challenge->code() )->is_verified() );
		self::assertFalse( $manager->is_verified( '01811234567', self::CONTEXT ) );
	}

	public function test_wrong_length_is_invalid(): void {
		$manager   = $this->manager();
		$challenge = $manager->issue( self::PHONE, self::CONTEXT );

		self::assertSame( OtpResult::INVALID, $manager->verify( self::PHONE, self::CONTEXT, $challenge->code() . '9' )->status() );
	}

	public function test_resend_cooldown(): void {
		$manager = $this->manager();
		$manager->issue( self::PHONE, self::CONTEXT );
		$this->clock->advance( 20 );

		try {
			$manager->issue( self::PHONE, self::CONTEXT );
			self::fail( 'Expected a cooldown.' );
		} catch ( OtpException $e ) {
			self::assertSame( OtpException::COOLDOWN, $e->reason() );
			self::assertSame( 40, $e->retry_after() );
		}
		self::assertSame( 40, $manager->cooldown_left( self::PHONE, self::CONTEXT ) );

		$this->clock->advance( 40 );
		self::assertSame( 0, $manager->cooldown_left( self::PHONE, self::CONTEXT ) );
		$second = $manager->issue( self::PHONE, self::CONTEXT );
		self::assertSame( 1, $second->sends_left() );
	}

	public function test_a_new_code_replaces_the_old_one_and_resets_attempts(): void {
		$manager = $this->manager();
		$first   = $manager->issue( self::PHONE, self::CONTEXT );
		$manager->verify( self::PHONE, self::CONTEXT, 'x' );
		$this->clock->advance( 60 );
		$second = $manager->issue( self::PHONE, self::CONTEXT );

		if ( $first->code() !== $second->code() ) {
			self::assertSame( OtpResult::INVALID, $manager->verify( self::PHONE, self::CONTEXT, $first->code() )->status() );
		}
		self::assertTrue( $manager->verify( self::PHONE, self::CONTEXT, $second->code() )->is_verified() );
	}

	public function test_send_limit_per_window(): void {
		$manager = $this->manager();
		for ( $i = 0; $i < 3; $i++ ) {
			$manager->issue( self::PHONE, self::CONTEXT );
			$this->clock->advance( 60 );
		}

		try {
			$manager->issue( self::PHONE, self::CONTEXT );
			self::fail( 'Expected the send limit.' );
		} catch ( OtpException $e ) {
			self::assertSame( OtpException::TOO_MANY_SENDS, $e->reason() );
			self::assertSame( 3600 - 180, $e->retry_after() );
		}

		// The limit outlives the code itself and resets with the window.
		$this->clock->advance( 3600 - 180 );
		self::assertSame( 2, $manager->issue( self::PHONE, self::CONTEXT )->sends_left() );
	}

	public function test_verification_is_single_use_and_expires(): void {
		$manager   = $this->manager();
		$challenge = $manager->issue( self::PHONE, self::CONTEXT );
		$manager->verify( self::PHONE, self::CONTEXT, $challenge->code() );

		// Asking again with the same code is idempotent while the verification lasts.
		self::assertTrue( $manager->verify( self::PHONE, self::CONTEXT, $challenge->code() )->is_verified() );

		$this->clock->advance( 1800 );
		self::assertFalse( $manager->is_verified( self::PHONE, self::CONTEXT ) );
		self::assertSame( OtpResult::EXPIRED, $manager->verify( self::PHONE, self::CONTEXT, $challenge->code() )->status() );
	}

	public function test_consume_forgets_the_record(): void {
		$manager   = $this->manager();
		$challenge = $manager->issue( self::PHONE, self::CONTEXT );
		$manager->verify( self::PHONE, self::CONTEXT, $challenge->code() );
		$manager->consume( self::PHONE, self::CONTEXT );

		self::assertFalse( $manager->is_verified( self::PHONE, self::CONTEXT ) );
		self::assertSame( OtpResult::MISSING, $manager->verify( self::PHONE, self::CONTEXT, $challenge->code() )->status() );
	}

	public function test_code_length_is_configurable(): void {
		$manager = $this->manager( new OtpConfig( 4 ) );

		self::assertSame( 4, strlen( $manager->issue( self::PHONE, self::CONTEXT )->code() ) );
		self::assertSame( 8, ( new OtpConfig( 12 ) )->length );
	}

	public function test_config_from_settings_is_clamped(): void {
		$config = OtpConfig::from_array(
			array(
				'length'          => 2,
				'ttl'             => 5,
				'max_attempts'    => 100,
				'resend_cooldown' => -1,
			)
		);

		self::assertSame( 4, $config->length );
		self::assertSame( 60, $config->ttl );
		self::assertSame( 10, $config->max_attempts );
		self::assertSame( 0, $config->resend_cooldown );
	}

	public function test_requires_a_real_secret(): void {
		$this->expectException( InvalidArgumentException::class );
		new OtpManager( $this->store, $this->clock, 'short', new OtpConfig() );
	}
}
