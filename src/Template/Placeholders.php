<?php
/**
 * Placeholder catalogue.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Template;

/**
 * The placeholders available in order templates, with descriptions for the
 * settings screen and the README.
 */
final class Placeholders {

	/**
	 * Name => description (English; translated by the admin screen).
	 *
	 * @var array<string,string>
	 */
	public const ORDER = array(
		'order_id'        => 'Order ID',
		'order_number'    => 'Order number as shown to the customer',
		'status'          => 'New order status label',
		'previous_status' => 'Previous order status label',
		'total'           => 'Order total, for example 1,250.00',
		'currency'        => 'Currency code, for example BDT',
		'currency_symbol' => 'Currency symbol, for example ৳',
		'name'            => 'Billing full name',
		'first_name'      => 'Billing first name',
		'last_name'       => 'Billing last name',
		'phone'           => 'Billing phone (normalised)',
		'email'           => 'Billing email',
		'city'            => 'Billing city',
		'payment_method'  => 'Payment method title',
		'shipping_method' => 'Shipping method title',
		'item_count'      => 'Number of items',
		'items'           => 'Item names, comma separated',
		'tracking'        => 'Tracking number',
		'tracking_url'    => 'Tracking link',
		'courier'         => 'Courier / shipping provider',
		'date'            => 'Order date',
		'site_name'       => 'Store name',
		'site_url'        => 'Store URL',
		'note'            => 'Customer note',
	);

	/**
	 * Placeholders available in the OTP template.
	 *
	 * @var array<string,string>
	 */
	public const OTP = array(
		'otp'       => 'One-time code',
		'minutes'   => 'Minutes until the code expires',
		'site_name' => 'Store name',
	);

	/**
	 * Names of the order placeholders.
	 *
	 * @return string[]
	 */
	public static function order_names(): array {
		return array_keys( self::ORDER );
	}
}
