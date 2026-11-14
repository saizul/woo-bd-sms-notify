<?php
/**
 * Outgoing SMS.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Gateway;

use InvalidArgumentException;

/**
 * One SMS to one recipient.
 */
final class Message {

	/**
	 * Recipient as MSISDN (8801XXXXXXXXX).
	 *
	 * @var string
	 */
	private $to;

	/**
	 * Message text.
	 *
	 * @var string
	 */
	private $text;

	/**
	 * Client reference (unique per message, letters and digits, at most 20).
	 *
	 * @var string
	 */
	private $reference;

	/**
	 * Constructor.
	 *
	 * @param string $to Recipient MSISDN (8801XXXXXXXXX).
	 * @param string $text Text.
	 * @param string $reference Client reference; generated when empty.
	 * @throws InvalidArgumentException When the recipient is not an MSISDN.
	 */
	public function __construct( string $to, string $text, string $reference = '' ) {
		if ( ! preg_match( '/^8801[3-9]\d{8}$/', $to ) ) {
			throw new InvalidArgumentException( 'The recipient must be a Bangladeshi MSISDN (8801XXXXXXXXX).' );
		}
		$reference = (string) preg_replace( '/[^A-Za-z0-9]/', '', $reference );
		if ( '' === $reference ) {
			$reference = self::new_reference();
		}
		$this->to        = $to;
		$this->text      = $text;
		$this->reference = substr( $reference, 0, 20 );
	}

	/**
	 * Generate a 20-character reference: time-ordered prefix plus random part.
	 */
	public static function new_reference(): string {
		return strtoupper( str_pad( substr( base_convert( (string) time(), 10, 36 ), -6 ), 6, '0', STR_PAD_LEFT ) . bin2hex( random_bytes( 7 ) ) );
	}

	/**
	 * Recipient as 8801XXXXXXXXX.
	 */
	public function to(): string {
		return $this->to;
	}

	/**
	 * Recipient as 01XXXXXXXXX.
	 */
	public function to_local(): string {
		return substr( $this->to, 2 );
	}

	/**
	 * Recipient as +8801XXXXXXXXX.
	 */
	public function to_e164(): string {
		return '+' . $this->to;
	}

	/**
	 * Text.
	 */
	public function text(): string {
		return $this->text;
	}

	/**
	 * Client reference.
	 */
	public function reference(): string {
		return $this->reference;
	}
}
