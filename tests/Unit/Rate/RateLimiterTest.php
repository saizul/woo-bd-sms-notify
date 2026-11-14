<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Rate;

use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Rate\RateLimit;
use Saizul\BdSms\Rate\RateLimiter;
use Saizul\BdSms\Support\ArrayStore;
use Saizul\BdSms\Support\FrozenClock;

final class RateLimiterTest extends TestCase {

	/** @var FrozenClock */
	private $clock;

	/** @var RateLimiter */
	private $limiter;

	protected function setUp(): void {
		$this->clock   = new FrozenClock( 3600 * 1000 );
		$this->limiter = new RateLimiter( new ArrayStore( $this->clock ), $this->clock );
	}

	public function test_allows_up_to_the_limit_per_window(): void {
		$rule = array( new RateLimit( 'phone:01711234567', 3, 60 ) );

		self::assertTrue( $this->limiter->attempt( $rule )->allowed() );
		self::assertTrue( $this->limiter->attempt( $rule )->allowed() );
		self::assertTrue( $this->limiter->attempt( $rule )->allowed() );

		$blocked = $this->limiter->attempt( $rule );
		self::assertFalse( $blocked->allowed() );
		self::assertSame( 60, $blocked->retry_after() );
		self::assertSame( 'phone:01711234567', $blocked->bucket() );

		$this->clock->advance( 59 );
		self::assertFalse( $this->limiter->attempt( $rule )->allowed() );
		self::assertSame( 1, $this->limiter->check( $rule )->retry_after() );
		$this->clock->advance( 1 );
		self::assertTrue( $this->limiter->attempt( $rule )->allowed() );
	}

	public function test_buckets_are_independent(): void {
		$a = array( new RateLimit( 'phone:a', 1, 60 ) );
		$b = array( new RateLimit( 'phone:b', 1, 60 ) );

		self::assertTrue( $this->limiter->attempt( $a )->allowed() );
		self::assertTrue( $this->limiter->attempt( $b )->allowed() );
		self::assertFalse( $this->limiter->attempt( $a )->allowed() );
	}

	public function test_blocked_attempts_do_not_consume_other_rules(): void {
		$phone  = new RateLimit( 'phone:a', 1, 3600 );
		$global = new RateLimit( 'global', 10, 60 );

		$this->limiter->attempt( array( $phone, $global ) );
		$this->limiter->attempt( array( $phone, $global ) );
		$this->limiter->attempt( array( $phone, $global ) );

		self::assertSame( 1, $this->limiter->used( $global ) );
	}

	public function test_longest_wait_wins(): void {
		$phone  = new RateLimit( 'phone:a', 1, 3600 );
		$global = new RateLimit( 'global', 1, 60 );
		$this->limiter->attempt( array( $phone, $global ) );

		$decision = $this->limiter->check( array( $phone, $global ) );
		self::assertSame( 3600, $decision->retry_after() );
		self::assertSame( 'phone:a', $decision->bucket() );
	}

	public function test_zero_disables_a_rule(): void {
		$rule = array( new RateLimit( 'global', 0, 60 ) );
		for ( $i = 0; $i < 50; $i++ ) {
			self::assertTrue( $this->limiter->attempt( $rule )->allowed() );
		}
		self::assertSame( 0, $this->limiter->used( $rule[0] ) );
	}

	public function test_windows_align_to_the_clock(): void {
		$this->clock->advance( 45 );
		$rule = new RateLimit( 'global', 1, 60 );
		$this->limiter->attempt( array( $rule ) );

		self::assertSame( 15, $this->limiter->check( array( $rule ) )->retry_after() );
	}
}
