<?php
/**
 * Templates of one order status.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Notification;

/**
 * Customer and admin templates for an order status.
 */
final class StatusTemplates {

	/**
	 * Send to the customer.
	 *
	 * @var bool
	 */
	public $customer_enabled;

	/**
	 * Customer template.
	 *
	 * @var string
	 */
	public $customer;

	/**
	 * Send a copy to the admin numbers.
	 *
	 * @var bool
	 */
	public $admin_enabled;

	/**
	 * Admin template.
	 *
	 * @var string
	 */
	public $admin;

	/**
	 * Constructor.
	 *
	 * @param bool   $customer_enabled Customer enabled.
	 * @param string $customer Customer template.
	 * @param bool   $admin_enabled Admin enabled.
	 * @param string $admin Admin template.
	 */
	public function __construct( bool $customer_enabled, string $customer, bool $admin_enabled = false, string $admin = '' ) {
		$this->customer_enabled = $customer_enabled;
		$this->customer         = $customer;
		$this->admin_enabled    = $admin_enabled;
		$this->admin            = $admin;
	}

	/**
	 * Build from settings.
	 *
	 * @param array<string,mixed> $row Settings row.
	 */
	public static function from_array( array $row ): self {
		return new self(
			! empty( $row['customer_enabled'] ),
			(string) ( $row['customer'] ?? '' ),
			! empty( $row['admin_enabled'] ),
			(string) ( $row['admin'] ?? '' )
		);
	}
}
