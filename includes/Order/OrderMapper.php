<?php
/**
 * WooCommerce order to template values.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\WP\Order;

use Saizul\BdSms\Order\OrderData;
use Saizul\BdSms\Order\TrackingResolver;
use Saizul\BdSms\WP\Settings;
use WC_Order;

/**
 * Reads an order through the WooCommerce CRUD API only, so it works with
 * HPOS and legacy post storage alike.
 */
final class OrderMapper {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Map an order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $previous_status Previous status slug, if known.
	 */
	public function map( WC_Order $order, string $previous_status = '' ): OrderData {
		$phone = (string) $order->get_billing_phone();
		if ( '' === trim( $phone ) && method_exists( $order, 'get_shipping_phone' ) ) {
			$phone = (string) $order->get_shipping_phone();
		}

		$names = array();
		$count = 0;
		foreach ( $order->get_items() as $item ) {
			$names[] = wp_strip_all_tags( (string) $item->get_name() );
			$count  += (int) $item->get_quantity();
		}

		$keys     = array_filter( array_map( 'trim', explode( ',', (string) $this->settings->get( 'tracking_meta_keys' ) ) ) );
		$tracking = ( new TrackingResolver() )->resolve(
			static function ( string $key ) use ( $order ) {
				return $order->get_meta( $key, true );
			},
			$keys,
			(string) $this->settings->get( 'tracking_url' )
		);

		$currency = (string) $order->get_currency();
		$date     = $order->get_date_created();
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

		$values = array(
			'order_number'    => (string) $order->get_order_number(),
			'status'          => self::status_label( (string) $order->get_status() ),
			'previous_status' => '' === $previous_status ? '' : self::status_label( $previous_status ),
			'total'           => OrderData::format_amount( (float) $order->get_total(), $decimals ),
			'currency'        => $currency,
			'currency_symbol' => function_exists( 'get_woocommerce_currency_symbol' ) ? (string) preg_replace( '/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', html_entity_decode( (string) get_woocommerce_currency_symbol( $currency ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : $currency,
			'first_name'      => (string) $order->get_billing_first_name(),
			'last_name'       => (string) $order->get_billing_last_name(),
			'phone'           => $phone,
			'email'           => (string) $order->get_billing_email(),
			'city'            => (string) $order->get_billing_city(),
			'payment_method'  => wp_strip_all_tags( (string) $order->get_payment_method_title() ),
			'shipping_method' => wp_strip_all_tags( (string) $order->get_shipping_method() ),
			'item_count'      => (string) $count,
			'items'           => implode( ', ', $names ),
			'tracking'        => $tracking->number,
			'tracking_url'    => $tracking->url,
			'courier'         => $tracking->courier,
			'date'            => $date ? $date->date_i18n( (string) get_option( 'date_format', 'Y-m-d' ) ) : '',
			'site_name'       => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'        => home_url( '/' ),
			'note'            => wp_strip_all_tags( (string) $order->get_customer_note() ),
		);

		$normalized = ( new \Saizul\BdSms\Phone\PhoneNormalizer() )->to_local( $phone );
		if ( null !== $normalized ) {
			$values['phone'] = $normalized;
		}

		/**
		 * Filters the placeholder values of an order.
		 *
		 * @param array<string,string> $values Placeholder values.
		 * @param WC_Order             $order  The order.
		 */
		$values = (array) apply_filters( 'woo_bd_sms_order_vars', $values, $order );

		return new OrderData( (int) $order->get_id(), $phone, array_map( 'strval', $values ), (string) $order->get_payment_method() );
	}

	/**
	 * Translated status label.
	 *
	 * @param string $status Status slug.
	 */
	private static function status_label( string $status ): string {
		return function_exists( 'wc_get_order_status_name' ) ? (string) wc_get_order_status_name( $status ) : $status;
	}
}
