<?php
/**
 * Sending pipeline.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Sms;

use InvalidArgumentException;
use Saizul\BdSms\Gateway\Gateway;
use Saizul\BdSms\Gateway\Message;
use Saizul\BdSms\Log\LogEntry;
use Saizul\BdSms\Log\LogRepository;
use Saizul\BdSms\Phone\PhoneNormalizer;
use Saizul\BdSms\Rate\RateLimit;
use Saizul\BdSms\Rate\RateLimiter;
use Saizul\BdSms\Retry\RetryPolicy;
use Saizul\BdSms\Retry\RetryScheduler;
use Saizul\BdSms\Support\Clock;

/**
 * Validates, rate-limits, sends, logs and schedules retries.
 *
 * 1. The phone number is normalised; invalid numbers are logged as skipped.
 * 2. The text is cleaned and measured; empty or too long messages are skipped.
 * 3. Per-number and site-wide rate limits are checked. A limited message is
 *    deferred until the window resets (OTP codes fail instead).
 * 4. The gateway is called. Temporary failures are retried with back-off.
 */
final class SmsSender {

	/**
	 * Gateway.
	 *
	 * @var Gateway
	 */
	private $gateway;

	/**
	 * Log.
	 *
	 * @var LogRepository
	 */
	private $log;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private $limiter;

	/**
	 * Retry policy.
	 *
	 * @var RetryPolicy
	 */
	private $policy;

	/**
	 * Retry scheduler.
	 *
	 * @var RetryScheduler
	 */
	private $scheduler;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private $clock;

	/**
	 * Options.
	 *
	 * @var SenderOptions
	 */
	private $options;

	/**
	 * Phone normaliser.
	 *
	 * @var PhoneNormalizer
	 */
	private $phones;

	/**
	 * Segment counter.
	 *
	 * @var SegmentCounter
	 */
	private $counter;

	/**
	 * Text normaliser.
	 *
	 * @var TextNormalizer
	 */
	private $text;

	/**
	 * Constructor.
	 *
	 * @param Gateway        $gateway Gateway.
	 * @param LogRepository  $log Log.
	 * @param RateLimiter    $limiter Rate limiter.
	 * @param RetryPolicy    $policy Retry policy.
	 * @param RetryScheduler $scheduler Retry scheduler.
	 * @param Clock          $clock Clock.
	 * @param SenderOptions  $options Options.
	 */
	public function __construct( Gateway $gateway, LogRepository $log, RateLimiter $limiter, RetryPolicy $policy, RetryScheduler $scheduler, Clock $clock, SenderOptions $options ) {
		$this->gateway   = $gateway;
		$this->log       = $log;
		$this->limiter   = $limiter;
		$this->policy    = $policy;
		$this->scheduler = $scheduler;
		$this->clock     = $clock;
		$this->options   = $options;
		$this->phones    = new PhoneNormalizer();
		$this->counter   = new SegmentCounter();
		$this->text      = new TextNormalizer();
	}

	/**
	 * Send a message (first attempt) and log it.
	 *
	 * @param OutgoingSms $sms Message.
	 */
	public function send( OutgoingSms $sms ): LogEntry {
		$now               = $this->clock->now();
		$entry             = new LogEntry();
		$entry->created_at = $now;
		$entry->updated_at = $now;
		$entry->order_id   = $sms->order_id;
		$entry->type       = $sms->type;
		$entry->event      = $sms->event;
		$entry->gateway    = $this->gateway->id();

		$text = $this->text->normalize( $sms->text, $this->options->plain_punctuation );
		$info = $this->counter->count( $text );

		$entry->message  = null === $sms->log_text ? $text : $this->text->normalize( $sms->log_text, $this->options->plain_punctuation );
		$entry->segments = $info->segments();
		$entry->encoding = $info->encoding();

		$phone        = $this->phones->normalize( $sms->phone );
		$entry->phone = $phone->is_valid() ? (string) $phone->local() : substr( trim( $sms->phone ), 0, 32 );

		if ( ! $phone->is_valid() ) {
			return $this->skip( $entry, 'invalid_phone', sprintf( 'Not a valid Bangladeshi mobile number (%s).', (string) $phone->reason() ) );
		}
		if ( 0 === $info->segments() ) {
			return $this->skip( $entry, 'empty_message', 'The message is empty.' );
		}
		if ( $info->segments() > $this->options->max_segments ) {
			return $this->skip(
				$entry,
				'too_long',
				sprintf( 'The message needs %1$d SMS; the limit is %2$d.', $info->segments(), $this->options->max_segments )
			);
		}

		$this->log->insert( $entry );

		return $this->attempt( $entry, $text, $sms->is_retryable() );
	}

	/**
	 * Run a scheduled retry.
	 *
	 * @param int $log_id Log entry id.
	 * @return LogEntry|null The updated entry, or null when there is nothing to retry.
	 */
	public function retry( int $log_id ): ?LogEntry {
		$entry = $this->log->find( $log_id );
		if ( null === $entry || $entry->is_final() || LogEntry::TYPE_OTP === $entry->type ) {
			return null;
		}
		$entry->gateway = $this->gateway->id();

		return $this->attempt( $entry, $entry->message, true );
	}

	/**
	 * One attempt.
	 *
	 * @param LogEntry $entry Entry (stored).
	 * @param string   $text Text to send.
	 * @param bool     $may_defer Whether failures may be retried later.
	 */
	private function attempt( LogEntry $entry, string $text, bool $may_defer ): LogEntry {
		$now               = $this->clock->now();
		$entry->updated_at = $now;

		$decision = $this->limiter->attempt( $this->limits( $entry->phone ) );
		if ( ! $decision->allowed() ) {
			$entry->error_code    = 'rate_limited';
			$entry->error_message = sprintf( 'Rate limit "%1$s" reached; next window in %2$d seconds.', $decision->bucket(), $decision->retry_after() );
			if ( $may_defer ) {
				$entry->status          = LogEntry::STATUS_RATE_LIMITED;
				$entry->next_attempt_at = $now + $decision->retry_after();
				$this->log->update( $entry );
				$this->scheduler->schedule( $entry->id, $decision->retry_after() );
			} else {
				$entry->status          = LogEntry::STATUS_FAILED;
				$entry->next_attempt_at = 0;
				$this->log->update( $entry );
			}

			return $entry;
		}

		++$entry->attempts;
		try {
			$message = new Message( '88' . $entry->phone, $text, sprintf( 'L%dA%d', $entry->id, $entry->attempts ) . substr( bin2hex( random_bytes( 4 ) ), 0, 6 ) );
		} catch ( InvalidArgumentException $e ) {
			return $this->finish_failed( $entry, 'invalid_phone', $e->getMessage() );
		}

		$result = $this->gateway->send( $message );

		$entry->response    = $result->raw();
		$entry->provider_id = $result->message_id();
		if ( $result->is_success() ) {
			$entry->status          = LogEntry::STATUS_SENT;
			$entry->error_code      = '';
			$entry->error_message   = '';
			$entry->next_attempt_at = 0;
			$this->log->update( $entry );

			return $entry;
		}

		$entry->error_code    = $result->error_code();
		$entry->error_message = $result->error_message();
		if ( $may_defer && $this->policy->should_retry( $result, $entry->attempts ) ) {
			$delay                  = $this->policy->delay( $entry->attempts );
			$entry->status          = LogEntry::STATUS_RETRYING;
			$entry->next_attempt_at = $now + $delay;
			$this->log->update( $entry );
			$this->scheduler->schedule( $entry->id, $delay );

			return $entry;
		}

		$entry->status          = LogEntry::STATUS_FAILED;
		$entry->next_attempt_at = 0;
		$this->log->update( $entry );

		return $entry;
	}

	/**
	 * Rate rules for a number.
	 *
	 * @param string $phone Local number.
	 * @return RateLimit[]
	 */
	private function limits( string $phone ): array {
		return array(
			new RateLimit( 'phone:' . $phone, $this->options->per_phone_limit, $this->options->per_phone_window ),
			new RateLimit( 'global', $this->options->global_limit, $this->options->global_window ),
		);
	}

	/**
	 * Log a message that was not sent.
	 *
	 * @param LogEntry $entry Entry (not stored yet).
	 * @param string   $code Reason code.
	 * @param string   $message Reason.
	 */
	private function skip( LogEntry $entry, string $code, string $message ): LogEntry {
		$entry->status        = LogEntry::STATUS_SKIPPED;
		$entry->error_code    = $code;
		$entry->error_message = $message;
		$this->log->insert( $entry );

		return $entry;
	}

	/**
	 * Mark a stored entry as failed.
	 *
	 * @param LogEntry $entry Entry.
	 * @param string   $code Code.
	 * @param string   $message Message.
	 */
	private function finish_failed( LogEntry $entry, string $code, string $message ): LogEntry {
		$entry->status          = LogEntry::STATUS_FAILED;
		$entry->error_code      = $code;
		$entry->error_message   = $message;
		$entry->next_attempt_at = 0;
		$this->log->update( $entry );

		return $entry;
	}
}
