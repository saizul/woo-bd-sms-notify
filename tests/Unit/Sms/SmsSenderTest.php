<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Sms;

use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Gateway\AlphaSmsGateway;
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

final class SmsSenderTest extends TestCase {

	private const OK = '{"error":0,"msg":"Request successfully submitted","data":{"request_id":555}}';

	/** @var FakeHttpClient */
	private $http;

	/** @var InMemoryLogRepository */
	private $log;

	/** @var InMemoryRetryScheduler */
	private $scheduler;

	/** @var FrozenClock */
	private $clock;

	protected function setUp(): void {
		$this->http      = new FakeHttpClient();
		$this->log       = new InMemoryLogRepository();
		$this->scheduler = new InMemoryRetryScheduler();
		$this->clock     = new FrozenClock( 1700000000 );
	}

	private function sender( ?SenderOptions $options = null, int $attempts = 3 ): SmsSender {
		return new SmsSender(
			new AlphaSmsGateway( $this->http, array( 'api_key' => 'alpha-test-key' ) ),
			$this->log,
			new RateLimiter( new ArrayStore( $this->clock ), $this->clock ),
			new RetryPolicy( $attempts, 60, 4 ),
			$this->scheduler,
			$this->clock,
			$options ?? new SenderOptions()
		);
	}

	public function test_sends_and_logs(): void {
		$this->http->queue( 200, self::OK );
		$entry = $this->sender()->send( new OutgoingSms( '+880 1711-234567', "Order’s ready  \n", LogEntry::TYPE_CUSTOMER, 'processing', 1024 ) );

		self::assertSame( LogEntry::STATUS_SENT, $entry->status );
		self::assertSame( '01711234567', $entry->phone );
		self::assertSame( "Order's ready", $entry->message );
		self::assertSame( 'GSM-7', $entry->encoding );
		self::assertSame( 1, $entry->segments );
		self::assertSame( 1, $entry->attempts );
		self::assertSame( '555', $entry->provider_id );
		self::assertSame( 'alpha_sms', $entry->gateway );
		self::assertSame( '8801711234567', $this->http->last()->body_fields()['to'] );
		self::assertSame( "Order's ready", $this->http->last()->body_fields()['msg'] );

		$stored = $this->log->find( $entry->id );
		self::assertSame( LogEntry::STATUS_SENT, $stored->status );
		self::assertSame( 1024, $stored->order_id );
	}

	public function test_invalid_phone_is_skipped_without_calling_the_gateway(): void {
		$entry = $this->sender()->send( new OutgoingSms( '02-9112233', 'Hi' ) );

		self::assertSame( LogEntry::STATUS_SKIPPED, $entry->status );
		self::assertSame( 'invalid_phone', $entry->error_code );
		self::assertSame( '02-9112233', $entry->phone );
		self::assertSame( array(), $this->http->requests );
		self::assertNotNull( $this->log->find( $entry->id ) );
	}

	public function test_empty_and_too_long_messages_are_skipped(): void {
		self::assertSame( 'empty_message', $this->sender()->send( new OutgoingSms( '01711234567', ' <p></p> ' ) )->error_code );

		$long = $this->sender( new SenderOptions( 10, 3600, 100, 60, 2 ) )->send( new OutgoingSms( '01711234567', str_repeat( 'ক', 135 ) ) );
		self::assertSame( 'too_long', $long->error_code );
		self::assertSame( 3, $long->segments );
		self::assertSame( array(), $this->http->requests );
	}

	public function test_temporary_failures_are_retried_with_backoff(): void {
		$this->http->queue_error( 'timeout' )->queue( 200, '{"error":409,"msg":""}' )->queue( 200, self::OK );
		$sender = $this->sender();

		$entry = $sender->send( new OutgoingSms( '01711234567', 'Hi' ) );
		self::assertSame( LogEntry::STATUS_RETRYING, $entry->status );
		self::assertSame( 'http', $entry->error_code );
		self::assertSame( 1700000060, $entry->next_attempt_at );
		self::assertSame( array( array( $entry->id, 60 ) ), $this->scheduler->scheduled );

		$this->clock->advance( 60 );
		$second = $sender->retry( $entry->id );
		self::assertSame( LogEntry::STATUS_RETRYING, $second->status );
		self::assertSame( 2, $second->attempts );
		self::assertSame( array( $entry->id, 240 ), $this->scheduler->scheduled[1] );

		$this->clock->advance( 240 );
		$third = $sender->retry( $entry->id );
		self::assertSame( LogEntry::STATUS_SENT, $third->status );
		self::assertSame( 3, $third->attempts );
		self::assertSame( '', $third->error_code );
		self::assertSame( 0, $third->next_attempt_at );

		self::assertNull( $sender->retry( $entry->id ), 'Sent messages are not retried again.' );
		self::assertCount( 3, $this->http->requests );
	}

	public function test_gives_up_after_the_last_attempt(): void {
		$this->http->queue_error( 'timeout' )->queue_error( 'timeout' );
		$sender = $this->sender( null, 2 );

		$entry = $sender->send( new OutgoingSms( '01711234567', 'Hi' ) );
		$final = $sender->retry( $entry->id );

		self::assertSame( LogEntry::STATUS_FAILED, $final->status );
		self::assertSame( 2, $final->attempts );
		self::assertCount( 1, $this->scheduler->scheduled );
	}

	public function test_permanent_failures_are_not_retried(): void {
		$this->http->queue( 200, '{"error":417,"msg":"Insufficient balance"}' );
		$entry = $this->sender()->send( new OutgoingSms( '01711234567', 'Hi' ) );

		self::assertSame( LogEntry::STATUS_FAILED, $entry->status );
		self::assertSame( '417', $entry->error_code );
		self::assertSame( 'Insufficient balance', $entry->error_message );
		self::assertSame( array(), $this->scheduler->scheduled );
	}

	public function test_rate_limited_messages_are_deferred(): void {
		$sender = $this->sender( new SenderOptions( 2, 3600, 100, 60 ) );
		$this->http->queue( 200, self::OK )->queue( 200, self::OK );

		$sender->send( new OutgoingSms( '01711234567', 'one' ) );
		$sender->send( new OutgoingSms( '01711234567', 'two' ) );
		$third = $sender->send( new OutgoingSms( '01711234567', 'three' ) );

		self::assertSame( LogEntry::STATUS_RATE_LIMITED, $third->status );
		self::assertSame( 0, $third->attempts, 'A deferral is not an attempt.' );
		self::assertSame( 'rate_limited', $third->error_code );
		$wait = 3600 - ( 1700000000 % 3600 );
		self::assertSame( array( array( $third->id, $wait ) ), $this->scheduler->scheduled );
		self::assertCount( 2, $this->http->requests );

		// Another number is not affected.
		$this->http->queue( 200, self::OK );
		self::assertSame( LogEntry::STATUS_SENT, $sender->send( new OutgoingSms( '01811234567', 'four' ) )->status );

		$this->clock->advance( $wait );
		$this->http->queue( 200, self::OK );
		self::assertSame( LogEntry::STATUS_SENT, $sender->retry( $third->id )->status );
	}

	public function test_global_limit(): void {
		$sender = $this->sender( new SenderOptions( 0, 3600, 1, 60 ) );
		$this->http->queue( 200, self::OK );

		$sender->send( new OutgoingSms( '01711234567', 'one' ) );
		$second = $sender->send( new OutgoingSms( '01811234567', 'two' ) );

		self::assertSame( LogEntry::STATUS_RATE_LIMITED, $second->status );
		self::assertStringContainsString( '"global"', $second->error_message );
	}

	public function test_otp_messages_fail_instead_of_waiting_and_are_masked(): void {
		$sender = $this->sender( new SenderOptions( 1, 3600, 100, 60 ) );
		$this->http->queue( 200, self::OK )->queue_error( 'timeout' );

		$sent = $sender->send( new OutgoingSms( '01711234567', 'Your code is 482913', LogEntry::TYPE_OTP, 'otp', 0, 'Your code is ******' ) );
		self::assertSame( LogEntry::STATUS_SENT, $sent->status );
		self::assertSame( 'Your code is ******', $sent->message );
		self::assertSame( 'Your code is 482913', $this->http->last()->body_fields()['msg'] );
		self::assertStringNotContainsString( '482913', serialize( $this->log->find( $sent->id ) ) );

		$limited = $sender->send( new OutgoingSms( '01711234567', 'Your code is 111111', LogEntry::TYPE_OTP, 'otp', 0, 'Your code is ******' ) );
		self::assertSame( LogEntry::STATUS_FAILED, $limited->status );
		self::assertSame( array(), $this->scheduler->scheduled );
		self::assertNull( $sender->retry( $limited->id ) );
	}

	public function test_otp_transport_errors_are_not_retried(): void {
		$this->http->queue_error( 'timeout' );
		$entry = $this->sender()->send( new OutgoingSms( '01711234567', 'Code 1', LogEntry::TYPE_OTP, 'otp', 0, 'Code *' ) );

		self::assertSame( LogEntry::STATUS_FAILED, $entry->status );
		self::assertSame( array(), $this->scheduler->scheduled );
	}

	public function test_bangla_message_metadata(): void {
		$this->http->queue( 200, self::OK );
		$entry = $this->sender()->send( new OutgoingSms( '01711234567', str_repeat( 'ক', 71 ) ) );

		self::assertSame( 'UCS-2', $entry->encoding );
		self::assertSame( 2, $entry->segments );
	}

	public function test_options_from_settings(): void {
		$options = SenderOptions::from_settings(
			array(
				'rate'              => array( 'per_phone_limit' => 3 ),
				'max_segments'      => 4,
				'plain_punctuation' => false,
			)
		);

		self::assertSame( 3, $options->per_phone_limit );
		self::assertSame( 3600, $options->per_phone_window );
		self::assertSame( 4, $options->max_segments );
		self::assertFalse( $options->plain_punctuation );
		self::assertTrue( SenderOptions::from_settings( array() )->plain_punctuation );
	}
}
