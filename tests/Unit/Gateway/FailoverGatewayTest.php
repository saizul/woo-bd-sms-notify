<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Gateway;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Gateway\AlphaSmsGateway;
use Saizul\BdSms\Gateway\BulkSmsBdGateway;
use Saizul\BdSms\Gateway\FailoverGateway;
use Saizul\BdSms\Gateway\FailoverPolicy;
use Saizul\BdSms\Gateway\Message;
use Saizul\BdSms\Gateway\SendResult;
use Saizul\BdSms\Http\FakeHttpClient;
use Saizul\BdSms\Log\InMemoryLogRepository;
use Saizul\BdSms\Log\LogEntry;
use Saizul\BdSms\Rate\RateLimiter;
use Saizul\BdSms\Retry\InMemoryRetryScheduler;
use Saizul\BdSms\Retry\RetryPolicy;
use Saizul\BdSms\Sms\OutgoingSms;
use Saizul\BdSms\Sms\SenderOptions;
use Saizul\BdSms\Sms\SmsSender;
use Saizul\BdSms\Support\ArrayStore;
use Saizul\BdSms\Support\FrozenClock;

final class FailoverGatewayTest extends TestCase {

	private const BULK_OK    = '{"response_code":202,"message_id":7788}';
	private const BULK_EMPTY = '{"response_code":1007,"error_message":"Balance Insufficient"}';
	private const ALPHA_OK   = '{"error":0,"msg":"Request successfully submitted","data":{"request_id":555}}';

	/** @var FakeHttpClient */
	private $primary_http;

	/** @var FakeHttpClient */
	private $backup_http;

	/** @var FrozenClock */
	private $clock;

	/** @var ArrayStore */
	private $store;

	protected function setUp(): void {
		$this->primary_http = new FakeHttpClient();
		$this->backup_http  = new FakeHttpClient();
		$this->clock        = new FrozenClock( 1700000000 );
		$this->store        = new ArrayStore( $this->clock );
	}

	private function gateway( ?FailoverPolicy $policy = null ): FailoverGateway {
		return new FailoverGateway(
			new BulkSmsBdGateway(
				$this->primary_http,
				array(
					'api_key'   => 'bulk-test-key',
					'sender_id' => '8809617000000',
				)
			),
			new AlphaSmsGateway( $this->backup_http, array( 'api_key' => 'alpha-test-key' ) ),
			$policy ?? new FailoverPolicy(),
			$this->store,
			$this->clock
		);
	}

	private function message(): Message {
		return new Message( '8801711234567', 'Hi' );
	}

	public function test_primary_success_does_not_touch_the_backup(): void {
		$this->primary_http->queue( 200, self::BULK_OK );
		$gateway = $this->gateway();

		$result = $gateway->send( $this->message() );

		self::assertTrue( $result->is_success() );
		self::assertSame( 'bulksmsbd', $gateway->last_gateway_id() );
		self::assertSame( 'bulksmsbd', $gateway->id() );
		self::assertSame( array(), $this->backup_http->requests );
	}

	public function test_temporary_primary_failure_goes_to_the_backup(): void {
		$this->primary_http->queue_error( 'timeout' );
		$this->backup_http->queue( 200, self::ALPHA_OK );
		$gateway = $this->gateway();

		$result = $gateway->send( $this->message() );

		self::assertTrue( $result->is_success() );
		self::assertSame( '555', $result->message_id() );
		self::assertSame( 'alpha_sms', $gateway->last_gateway_id() );
		self::assertSame( '8801711234567', $this->backup_http->last()->body_fields()['to'] );
		self::assertSame( 1, $gateway->state()['failures'] );
	}

	public function test_permanent_failure_stays_on_the_primary_in_temporary_mode(): void {
		$this->primary_http->queue( 200, self::BULK_EMPTY );
		$gateway = $this->gateway();

		$result = $gateway->send( $this->message() );

		self::assertFalse( $result->is_success() );
		self::assertSame( '1007', $result->error_code() );
		self::assertSame( 'bulksmsbd', $gateway->last_gateway_id() );
		self::assertSame( array(), $this->backup_http->requests );
		self::assertSame( 0, $gateway->state()['failures'] );
	}

	public function test_listed_codes_and_any_mode_fail_over_on_permanent_errors(): void {
		$this->primary_http->queue( 200, self::BULK_EMPTY )->queue( 200, self::BULK_EMPTY );
		$this->backup_http->queue( 200, self::ALPHA_OK )->queue( 200, self::ALPHA_OK );

		self::assertTrue( $this->gateway( new FailoverPolicy( FailoverPolicy::MODE_TEMPORARY, array( '1007' ) ) )->send( $this->message() )->is_success() );
		self::assertTrue( $this->gateway( new FailoverPolicy( FailoverPolicy::MODE_ANY ) )->send( $this->message() )->is_success() );
		self::assertCount( 2, $this->backup_http->requests );
	}

	public function test_both_failing_combines_the_errors(): void {
		$this->primary_http->queue( 503, 'Service Unavailable' );
		$this->backup_http->queue( 200, '{"error":405,"msg":"Authorization required"}' );
		$gateway = $this->gateway();

		$result = $gateway->send( $this->message() );

		self::assertFalse( $result->is_success() );
		self::assertSame( '405', $result->error_code() );
		self::assertTrue( $result->is_retryable(), 'The primary error was temporary, so a later retry may still work.' );
		self::assertStringStartsWith( 'bulksmsbd: ', $result->error_message() );
		self::assertStringContainsString( '; alpha_sms: 405 Authorization required', $result->error_message() );
		self::assertSame( 'alpha_sms', $gateway->last_gateway_id() );
	}

	public function test_breaker_skips_the_primary_after_the_threshold_and_probes_after_cooldown(): void {
		$gateway = $this->gateway( new FailoverPolicy( FailoverPolicy::MODE_TEMPORARY, array(), 2, 120 ) );
		$this->primary_http->queue_error( 'timeout' )->queue_error( 'timeout' );
		$this->backup_http->queue( 200, self::ALPHA_OK )->queue( 200, self::ALPHA_OK )->queue( 200, self::ALPHA_OK );

		$gateway->send( $this->message() );
		self::assertFalse( $gateway->state()['open'] );
		$gateway->send( $this->message() );
		$state = $gateway->state();
		self::assertTrue( $state['open'] );
		self::assertSame( 1700000120, $state['open_until'] );

		// Open: the primary is not called at all.
		$this->clock->advance( 60 );
		self::assertTrue( $gateway->send( $this->message() )->is_success() );
		self::assertCount( 2, $this->primary_http->requests );
		self::assertCount( 3, $this->backup_http->requests );

		// Cooldown over: a failing probe reopens the breaker immediately.
		$this->clock->advance( 61 );
		$this->primary_http->queue_error( 'timeout' );
		$this->backup_http->queue( 200, self::ALPHA_OK );
		$gateway->send( $this->message() );
		self::assertCount( 3, $this->primary_http->requests );
		self::assertTrue( $gateway->state()['open'] );

		// A successful probe closes it.
		$this->clock->advance( 121 );
		$this->primary_http->queue( 200, self::BULK_OK );
		$gateway->send( $this->message() );
		self::assertSame( 'bulksmsbd', $gateway->last_gateway_id() );
		self::assertSame(
			array(
				'primary'    => 'bulksmsbd',
				'backup'     => 'alpha_sms',
				'failures'   => 0,
				'open'       => false,
				'open_until' => 0,
			),
			$gateway->state()
		);
	}

	public function test_threshold_zero_disables_the_breaker_and_reset_clears_state(): void {
		$gateway = $this->gateway( new FailoverPolicy( FailoverPolicy::MODE_TEMPORARY, array(), 0 ) );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->primary_http->queue_error( 'timeout' );
			$this->backup_http->queue( 200, self::ALPHA_OK );
			$gateway->send( $this->message() );
		}
		self::assertCount( 5, $this->primary_http->requests );
		self::assertSame( 0, $gateway->state()['failures'] );

		$open = $this->gateway( new FailoverPolicy( FailoverPolicy::MODE_TEMPORARY, array(), 1 ) );
		$this->primary_http->queue_error( 'timeout' );
		$this->backup_http->queue( 200, self::ALPHA_OK );
		$open->send( $this->message() );
		self::assertTrue( $open->state()['open'] );
		$open->reset();
		self::assertFalse( $open->state()['open'] );
		self::assertSame( 0, $this->store->count() );
	}

	public function test_secrets_label_and_validation(): void {
		$gateway = $this->gateway();

		self::assertSame( array( 'bulk-test-key', 'alpha-test-key' ), $gateway->secrets() );
		self::assertSame( 'BulkSMSBD (backup: Alpha SMS (sms.net.bd))', $gateway->label() );
		self::assertSame( array(), $gateway->validate() );
		self::assertSame( array(), $gateway->validate_backup() );
	}

	public function test_same_gateway_twice_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new FailoverGateway(
			new AlphaSmsGateway( $this->primary_http, array( 'api_key' => 'a' ) ),
			new AlphaSmsGateway( $this->backup_http, array( 'api_key' => 'b' ) ),
			new FailoverPolicy(),
			$this->store,
			$this->clock
		);
	}

	public function test_policy_parsing(): void {
		$policy = FailoverPolicy::from_array(
			array(
				'mode'      => 'bogus',
				'codes'     => ' 1007, 1007 ,<b>x</b>, ',
				'threshold' => 500,
				'cooldown'  => 1,
			)
		);

		self::assertSame( FailoverPolicy::MODE_TEMPORARY, $policy->mode() );
		self::assertSame( array( '1007', 'bxb' ), $policy->codes() );
		self::assertSame( 100, $policy->threshold() );
		self::assertSame( 10, $policy->cooldown() );
		self::assertFalse( $policy->should_fail_over( SendResult::sent( '1' ) ) );
		self::assertTrue( $policy->should_fail_over( SendResult::failed( 'http', 'timeout', true ) ) );
		self::assertTrue( $policy->should_fail_over( SendResult::failed( '1007', 'Balance', false ) ) );
		self::assertFalse( $policy->should_fail_over( SendResult::failed( '1001', 'Invalid number', false ) ) );
	}

	public function test_sender_logs_the_gateway_that_was_used_and_retries_through_failover(): void {
		$log       = new InMemoryLogRepository();
		$scheduler = new InMemoryRetryScheduler();
		$sender    = new SmsSender(
			$this->gateway( new FailoverPolicy( FailoverPolicy::MODE_TEMPORARY, array(), 0 ) ),
			$log,
			new RateLimiter( new ArrayStore( $this->clock ), $this->clock ),
			new RetryPolicy( 3, 60, 4 ),
			$scheduler,
			$this->clock,
			new SenderOptions()
		);

		$this->primary_http->queue_error( 'timeout' );
		$this->backup_http->queue( 200, self::ALPHA_OK );
		$entry = $sender->send( new OutgoingSms( '01711234567', 'Hi', LogEntry::TYPE_CUSTOMER, 'processing', 7 ) );
		self::assertSame( LogEntry::STATUS_SENT, $entry->status );
		self::assertSame( 'alpha_sms', $entry->gateway );
		self::assertSame( '555', $entry->provider_id );

		// Both down: retried later, and the retry goes through the primary again.
		$this->primary_http->queue_error( 'timeout' );
		$this->backup_http->queue( 502, 'Bad Gateway' );
		$second = $sender->send( new OutgoingSms( '01711234567', 'Hi again' ) );
		self::assertSame( LogEntry::STATUS_RETRYING, $second->status );
		self::assertCount( 1, $scheduler->scheduled );

		$this->clock->advance( 60 );
		$this->primary_http->queue( 200, self::BULK_OK );
		$retried = $sender->retry( $second->id );
		self::assertSame( LogEntry::STATUS_SENT, $retried->status );
		self::assertSame( 'bulksmsbd', $retried->gateway );
		self::assertSame( 2, $retried->attempts );
	}
}
