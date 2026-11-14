<?php
/**
 * Shipment tracking details.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Order;

/**
 * Tracking number, courier and link found on an order.
 */
final class TrackingInfo {

	/**
	 * Tracking number.
	 *
	 * @var string
	 */
	public $number;

	/**
	 * Courier name.
	 *
	 * @var string
	 */
	public $courier;

	/**
	 * Tracking link.
	 *
	 * @var string
	 */
	public $url;

	/**
	 * Constructor.
	 *
	 * @param string $number Tracking number.
	 * @param string $courier Courier.
	 * @param string $url Link.
	 */
	public function __construct( string $number = '', string $courier = '', string $url = '' ) {
		$this->number  = $number;
		$this->courier = $courier;
		$this->url     = $url;
	}

	/**
	 * Whether a tracking number is known.
	 */
	public function has_number(): bool {
		return '' !== $this->number;
	}
}
