<?php
/**
 * Minimal WooCommerce class and function stubs for unit tests.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

// phpcs:ignoreFile

final class WcStubs {
	/** @var array<int,WC_Order> */
	public static $orders = array();
	/** @var WcMainStub|null */
	public static $main = null;

	public static function reset(): void {
		self::$orders = array();
		self::$main   = null;
	}
}

class WC_DateTime extends DateTime {
	public function date_i18n( $format ) {
		return $this->format( $format );
	}
}

class WC_Order_Item {
	/** @var array<string,mixed> */
	private $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function get_name() {
		return $this->data['name'] ?? '';
	}

	public function get_quantity() {
		return $this->data['quantity'] ?? 1;
	}
}

/**
 * Fake order with CRUD-style getters. Unknown get_* calls read $data.
 */
class WC_Order {
	/** @var array<string,mixed> */
	public $data;
	/** @var array<string,mixed> */
	public $meta = array();
	/** @var string[] */
	public $notes = array();
	/** @var int */
	public $saves = 0;

	public function __construct( array $data ) {
		$this->data = $data;
		$this->meta = $data['meta'] ?? array();
	}

	public function __call( $name, $args ) {
		if ( 0 === strpos( $name, 'get_' ) ) {
			return $this->data[ substr( $name, 4 ) ] ?? '';
		}
		throw new BadMethodCallException( $name );
	}

	public function get_id() {
		return $this->data['id'];
	}

	public function get_order_number() {
		return (string) ( $this->data['number'] ?? $this->data['id'] );
	}

	public function get_status() {
		return $this->data['status'] ?? 'processing';
	}

	public function get_date_created() {
		return isset( $this->data['date_created'] ) ? new WC_DateTime( $this->data['date_created'] ) : null;
	}

	public function get_shipping_phone() {
		return $this->data['shipping_phone'] ?? '';
	}

	public function get_items( $type = 'line_item' ) {
		return array_map(
			static function ( $item ) {
				return new WC_Order_Item( $item );
			},
			$this->data['items'] ?? array()
		);
	}

	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function add_order_note( $note ) {
		$this->notes[] = $note;
		return 1;
	}

	public function save() {
		++$this->saves;
		return $this->get_id();
	}
}

function wc_get_order( $id ) {
	return WcStubs::$orders[ (int) $id ] ?? false;
}

function wc_get_order_status_name( $status ) {
	return ucwords( str_replace( '-', ' ', (string) $status ) );
}

function wc_get_price_decimals() {
	return 2;
}

function get_woocommerce_currency_symbol( $currency = '' ) {
	return 'BDT' === $currency ? '&#2547;&nbsp;' : '$';
}

class WcSessionStub {
	/** @var array<string,mixed> */
	public $data = array();
	/** @var bool */
	public $cookie = false;

	public function get( $key, $default = null ) {
		return $this->data[ $key ] ?? $default;
	}

	public function set( $key, $value ) {
		$this->data[ $key ] = $value;
	}

	public function has_session() {
		return $this->cookie;
	}

	public function set_customer_session_cookie( $set ) {
		$this->cookie = (bool) $set;
	}
}

class WcCartStub {
	/** @var float */
	public $total = 0.0;

	public function get_total( $context = 'view' ) {
		return $this->total;
	}
}

class WcMainStub {
	/** @var WcSessionStub */
	public $session;
	/** @var WcCartStub */
	public $cart;

	public function __construct() {
		$this->session = new WcSessionStub();
		$this->cart    = new WcCartStub();
	}

	public function payment_gateways() {
		return null;
	}
}

function WC() {
	if ( null === WcStubs::$main ) {
		WcStubs::$main = new WcMainStub();
	}
	return WcStubs::$main;
}
