<?php
/**
 * SMS waiting to be sent.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Sms;

use Saizul\BdSms\Log\LogEntry;

/**
 * What to send, to whom and why.
 */
final class OutgoingSms {

	/**
	 * Raw recipient phone.
	 *
	 * @var string
	 */
	public $phone;

	/**
	 * Text.
	 *
	 * @var string
	 */
	public $text;

	/**
	 * Recipient type (LogEntry::TYPE_*).
	 *
	 * @var string
	 */
	public $type;

	/**
	 * Event, such as the order status.
	 *
	 * @var string
	 */
	public $event;

	/**
	 * Order id or 0.
	 *
	 * @var int
	 */
	public $order_id;

	/**
	 * Text stored in the log instead of the real text (OTP masking), or null.
	 *
	 * @var string|null
	 */
	public $log_text;

	/**
	 * Constructor.
	 *
	 * @param string      $phone Raw phone.
	 * @param string      $text Text.
	 * @param string      $type Recipient type.
	 * @param string      $event Event.
	 * @param int         $order_id Order id.
	 * @param string|null $log_text Text for the log.
	 */
	public function __construct( string $phone, string $text, string $type = LogEntry::TYPE_CUSTOMER, string $event = '', int $order_id = 0, ?string $log_text = null ) {
		$this->phone    = $phone;
		$this->text     = $text;
		$this->type     = $type;
		$this->event    = $event;
		$this->order_id = $order_id;
		$this->log_text = $log_text;
	}

	/**
	 * Whether a failed send may be retried later (never for OTP codes).
	 */
	public function is_retryable(): bool {
		return LogEntry::TYPE_OTP !== $this->type;
	}
}
