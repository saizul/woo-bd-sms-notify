<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Order\OrderData;
use Saizul\BdSms\Order\TrackingResolver;

final class OrderDataTest extends TestCase {

	public function test_derived_values(): void {
		$order = new OrderData(
			77,
			'01711234567',
			array(
				'first_name' => 'Karim',
				'last_name'  => 'Ahmed',
			),
			'cod'
		);

		self::assertSame( 77, $order->id() );
		self::assertSame( 'cod', $order->payment_method_id() );
		self::assertSame( '77', $order->get( 'order_id' ) );
		self::assertSame( '77', $order->get( 'order_number' ) );
		self::assertSame( 'Karim Ahmed', $order->get( 'name' ) );
		self::assertSame( 'SF1', $order->with( array( 'tracking' => 'SF1' ) )->get( 'tracking' ) );
		self::assertSame( '', $order->get( 'tracking' ), 'with() returns a copy.' );
	}

	public function test_format_amount(): void {
		self::assertSame( '1,250', OrderData::format_amount( 1250.0 ) );
		self::assertSame( '1,250.50', OrderData::format_amount( 1250.5 ) );
		self::assertSame( '1,250.00', OrderData::format_amount( 1250.0, 2, false ) );
		self::assertSame( '999', OrderData::format_amount( 999.4, 0 ) );
	}

	/**
	 * @param array<string,mixed> $meta Meta.
	 */
	private function meta( array $meta ): callable {
		return static function ( string $key ) use ( $meta ) {
			return $meta[ $key ] ?? '';
		};
	}

	public function test_tracking_from_plain_meta(): void {
		$info = ( new TrackingResolver() )->resolve(
			$this->meta(
				array(
					'_tracking_number'   => ' SF123 ',
					'_tracking_provider' => 'Steadfast',
				)
			),
			array(),
			'https://track.example/?id={tracking}'
		);

		self::assertSame( 'SF123', $info->number );
		self::assertSame( 'Steadfast', $info->courier );
		self::assertSame( 'https://track.example/?id=SF123', $info->url );
	}

	public function test_custom_keys_win(): void {
		$info = ( new TrackingResolver() )->resolve(
			$this->meta(
				array(
					'_tracking_number' => 'OLD',
					'pathao_consignment' => 'DL123 456',
				)
			),
			array( 'pathao_consignment' ),
			'https://track.example/{tracking}'
		);

		self::assertSame( 'DL123 456', $info->number );
		self::assertSame( 'https://track.example/DL123%20456', $info->url );
	}

	public function test_shipment_tracking_items(): void {
		$items = array(
			array(
				'tracking_provider' => 'old',
				'tracking_number'   => '1',
			),
			array(
				'tracking_provider'        => 'redx',
				'custom_tracking_provider' => 'RedX',
				'tracking_number'          => 'RX99',
				'custom_tracking_link'     => 'https://redx.example/RX99',
			),
		);
		$info  = ( new TrackingResolver() )->resolve( $this->meta( array( '_wc_shipment_tracking_items' => $items ) ) );

		self::assertSame( 'RX99', $info->number );
		self::assertSame( 'RedX', $info->courier );
		self::assertSame( 'https://redx.example/RX99', $info->url );

		$json = ( new TrackingResolver() )->resolve( $this->meta( array( '_wc_shipment_tracking_items' => '[{"tracking_number":"J1","tracking_provider":"pathao"}]' ) ) );
		self::assertSame( 'J1', $json->number );
		self::assertSame( 'pathao', $json->courier );
	}

	public function test_no_tracking(): void {
		$info = ( new TrackingResolver() )->resolve( $this->meta( array( '_tracking_number' => array( 'not scalar' ) ) ), array(), 'https://x/{tracking}' );

		self::assertFalse( $info->has_number() );
		self::assertSame( '', $info->url );
	}
}
