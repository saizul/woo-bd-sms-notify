<?php
/**
 * Send log record.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Log;

/**
 * One SMS and what happened to it. Mutable: the sender updates the entry as
 * attempts are made.
 */
final class LogEntry {

	public const STATUS_QUEUED       = 'queued';
	public const STATUS_SENT         = 'sent';
	public const STATUS_FAILED       = 'failed';
	public const STATUS_RETRYING     = 'retrying';
	public const STATUS_RATE_LIMITED = 'rate_limited';
	public const STATUS_SKIPPED      = 'skipped';

	public const STATUSES = array(
		self::STATUS_QUEUED,
		self::STATUS_SENT,
		self::STATUS_FAILED,
		self::STATUS_RETRYING,
		self::STATUS_RATE_LIMITED,
		self::STATUS_SKIPPED,
	);

	public const TYPE_CUSTOMER = 'customer';
	public const TYPE_ADMIN    = 'admin';
	public const TYPE_OTP      = 'otp';
	public const TYPE_TEST     = 'test';
	public const TYPE_MANUAL   = 'manual';

	/**
	 * Row id (0 until stored).
	 *
	 * @var int
	 */
	public $id = 0;

	/**
	 * Created timestamp.
	 *
	 * @var int
	 */
	public $created_at = 0;

	/**
	 * Updated timestamp.
	 *
	 * @var int
	 */
	public $updated_at = 0;

	/**
	 * Order id, 0 when not related to an order.
	 *
	 * @var int
	 */
	public $order_id = 0;

	/**
	 * Recipient type (customer, admin, otp, test, manual).
	 *
	 * @var string
	 */
	public $type = self::TYPE_CUSTOMER;

	/**
	 * Event that caused the SMS, such as the order status slug.
	 *
	 * @var string
	 */
	public $event = '';

	/**
	 * Recipient (01XXXXXXXXX), or the raw input when invalid.
	 *
	 * @var string
	 */
	public $phone = '';

	/**
	 * Message text. OTP codes are masked before storage.
	 *
	 * @var string
	 */
	public $message = '';

	/**
	 * Gateway id.
	 *
	 * @var string
	 */
	public $gateway = '';

	/**
	 * Status constant.
	 *
	 * @var string
	 */
	public $status = self::STATUS_QUEUED;

	/**
	 * Attempts made.
	 *
	 * @var int
	 */
	public $attempts = 0;

	/**
	 * Segments billed.
	 *
	 * @var int
	 */
	public $segments = 0;

	/**
	 * GSM-7 or UCS-2.
	 *
	 * @var string
	 */
	public $encoding = '';

	/**
	 * Gateway message id.
	 *
	 * @var string
	 */
	public $provider_id = '';

	/**
	 * Last error code.
	 *
	 * @var string
	 */
	public $error_code = '';

	/**
	 * Last error message.
	 *
	 * @var string
	 */
	public $error_message = '';

	/**
	 * Last response body (truncated, secrets removed).
	 *
	 * @var string
	 */
	public $response = '';

	/**
	 * Timestamp of the next scheduled attempt, 0 when none.
	 *
	 * @var int
	 */
	public $next_attempt_at = 0;

	/**
	 * Create from a database row or array.
	 *
	 * @param array<string,mixed> $row Row.
	 */
	public static function from_array( array $row ): self {
		$entry = new self();
		foreach ( get_object_vars( $entry ) as $key => $default ) {
			if ( ! array_key_exists( $key, $row ) ) {
				continue;
			}
			$entry->$key = is_int( $default ) ? (int) $row[ $key ] : (string) $row[ $key ];
		}

		return $entry;
	}

	/**
	 * Array form (database row, JSON, CLI).
	 *
	 * @return array<string,int|string>
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}

	/**
	 * Whether the entry reached a final state.
	 */
	public function is_final(): bool {
		return in_array( $this->status, array( self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_SKIPPED ), true );
	}
}
