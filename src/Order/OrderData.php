<?php
/**
 * Order values for templates.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Order;

/**
 * WordPress-free snapshot of the order fields that SMS templates can use.
 */
final class OrderData {

	/**
	 * Values by placeholder name.
	 *
	 * @var array<string,string>
	 */
	private $values;

	/**
	 * Order id.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Raw billing phone.
	 *
	 * @var string
	 */
	private $phone;

	/**
	 * Payment method id (cod, bkash...).
	 *
	 * @var string
	 */
	private $payment_method_id;

	/**
	 * Constructor.
	 *
	 * @param int                  $id Order id.
	 * @param string               $phone Raw billing phone.
	 * @param array<string,string> $values Placeholder values.
	 * @param string               $payment_method_id Payment method id.
	 */
	public function __construct( int $id, string $phone, array $values, string $payment_method_id = '' ) {
		$this->id                = $id;
		$this->phone             = $phone;
		$this->payment_method_id = $payment_method_id;
		$this->values            = array_map( 'strval', $values );

		$this->values['order_id'] = (string) $id;
		if ( ! isset( $this->values['order_number'] ) || '' === $this->values['order_number'] ) {
			$this->values['order_number'] = (string) $id;
		}
		$name = trim( ( $this->values['first_name'] ?? '' ) . ' ' . ( $this->values['last_name'] ?? '' ) );
		if ( ! isset( $this->values['name'] ) || '' === $this->values['name'] ) {
			$this->values['name'] = $name;
		}
	}

	/**
	 * Order id.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Raw billing phone.
	 */
	public function phone(): string {
		return $this->phone;
	}

	/**
	 * Payment method id.
	 */
	public function payment_method_id(): string {
		return $this->payment_method_id;
	}

	/**
	 * A value.
	 *
	 * @param string $name Placeholder name.
	 */
	public function get( string $name ): string {
		return $this->values[ $name ] ?? '';
	}

	/**
	 * Copy with extra or replaced values.
	 *
	 * @param array<string,string> $values Values.
	 */
	public function with( array $values ): self {
		$copy         = clone $this;
		$copy->values = array_merge( $this->values, array_map( 'strval', $values ) );

		return $copy;
	}

	/**
	 * All placeholder values.
	 *
	 * @return array<string,string>
	 */
	public function to_vars(): array {
		return $this->values;
	}

	/**
	 * Format an amount: 1250.5 with 2 decimals becomes "1,250.50"; whole
	 * amounts drop the decimals when $trim_zeros is set ("1,250").
	 *
	 * @param float $amount Amount.
	 * @param int   $decimals Decimals.
	 * @param bool  $trim_zeros Drop ".00".
	 */
	public static function format_amount( float $amount, int $decimals = 2, bool $trim_zeros = true ): string {
		$text = number_format( $amount, max( 0, $decimals ), '.', ',' );
		if ( $trim_zeros && $decimals > 0 && preg_match( '/\.0+$/', $text ) ) {
			$text = (string) preg_replace( '/\.0+$/', '', $text );
		}

		return $text;
	}
}
