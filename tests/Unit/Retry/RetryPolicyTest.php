<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Retry;

use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Gateway\SendResult;
use Saizul\BdSms\Retry\RetryPolicy;

final class RetryPolicyTest extends TestCase {

	public function test_exponential_delays_with_cap(): void {
		$policy = new RetryPolicy( 6, 60, 4, 3600 );

		self::assertSame( 60, $policy->delay( 1 ) );
		self::assertSame( 240, $policy->delay( 2 ) );
		self::assertSame( 960, $policy->delay( 3 ) );
		self::assertSame( 3600, $policy->delay( 4 ) );
		self::assertSame( 3600, $policy->delay( 5 ) );
	}

	public function test_only_retryable_failures_with_attempts_left(): void {
		$policy    = new RetryPolicy( 3 );
		$temporary = SendResult::failed( 'http', 'timeout', true );
		$permanent = SendResult::failed( '1007', 'Balance Insufficient', false );

		self::assertTrue( $policy->should_retry( $temporary, 1 ) );
		self::assertTrue( $policy->should_retry( $temporary, 2 ) );
		self::assertFalse( $policy->should_retry( $temporary, 3 ) );
		self::assertFalse( $policy->should_retry( $permanent, 1 ) );
		self::assertFalse( $policy->should_retry( SendResult::sent( 'x' ), 1 ) );
	}

	public function test_bounds(): void {
		self::assertSame( 1, ( new RetryPolicy( 0 ) )->max_attempts() );
		self::assertSame( 10, ( new RetryPolicy( 99 ) )->max_attempts() );
		self::assertFalse( ( new RetryPolicy( 1 ) )->has_attempts_left( 1 ) );
		self::assertSame( 1, ( new RetryPolicy( 3, 0 ) )->delay( 1 ) );
	}
}
