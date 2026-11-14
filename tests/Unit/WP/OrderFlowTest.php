<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\WP;

use Saizul\BdSms\Log\LogEntry;
use Saizul\BdSms\Tests\WpTestCase;
use Saizul\BdSms\WP\Order\OrderMapper;
use Saizul\BdSms\WP\Order\StatusNotifier;
use WpStubs;

final class OrderFlowTest extends WpTestCase {

	private const OK = '{"error":0,"msg":"Request successfully submitted","data":{"request_id":9}}';

	protected function setUp(): void {
		parent::setUp();
		$this->settings_option(
			array(
				'gateway'      => 'alpha_sms',
				'gateways'     => array( 'alpha_sms' => array( 'api_key' => 'alpha-test-key' ) ),
				'admin_phones' => '01811111112',
				'templates'    => array(
					'processing' => array(
						'customer_enabled' => true,
						'customer'         => 'Hi {first_name}, order #{order_number} of {total} {currency} ({item_count} items) is {status}. {site_name}',
						'admin_enabled'    => true,
						'admin'            => 'New #{order_id} {phone} {payment_method}',
					),
					'completed'  => array(
						'customer_enabled' => false,
						'customer'         => 'Done',
						'admin_enabled'    => false,
						'admin'            => '',
					),
				),
			)
		);
	}

	public function test_order_mapper(): void {
		$order = $this->order(
			array(
				'meta' => array(
					'_tracking_number'   => 'SF555',
					'_tracking_provider' => 'Steadfast',
				),
			)
		);
		$data  = ( new OrderMapper( $this->settings() ) )->map( $order, 'pending' );
		$vars  = $data->to_vars();

		self::assertSame( '+880 1711-234567', $data->phone() );
		self::assertSame( '01711234567', $vars['phone'] );
		self::assertSame( '1,250', $vars['total'] );
		self::assertSame( 'BDT', $vars['currency'] );
		self::assertSame( '৳', $vars['currency_symbol'] );
		self::assertSame( 'Processing', $vars['status'] );
		self::assertSame( 'Pending', $vars['previous_status'] );
		self::assertSame( 'Karim Ahmed', $vars['name'] );
		self::assertSame( '3', $vars['item_count'] );
		self::assertSame( 'Saree, Gift wrap', $vars['items'] );
		self::assertSame( 'SF555', $vars['tracking'] );
		self::assertSame( 'Steadfast', $vars['courier'] );
		self::assertSame( 'Dhaka Shop & Co', $vars['site_name'] );
		self::assertSame( 'Call first', $vars['note'] );
		self::assertSame( 'cod', $data->payment_method_id() );
	}

	public function test_mapper_falls_back_to_shipping_phone_and_is_filterable(): void {
		add_filter(
			'woo_bd_sms_order_vars',
			static function ( array $vars ): array {
				$vars['site_name'] = 'Filtered';
				return $vars;
			}
		);
		$data = ( new OrderMapper( $this->settings() ) )->map(
			$this->order(
				array(
					'billing_phone'  => '',
					'shipping_phone' => '01911234567',
				)
			)
		);

		self::assertSame( '01911234567', $data->phone() );
		self::assertSame( 'Filtered', $data->get( 'site_name' ) );
	}

	public function test_status_change_is_queued_in_the_background(): void {
		$this->order();
		$notifier = new StatusNotifier( $this->services() );
		$notifier->on_status_changed( 1024, 'pending', 'processing' );

		self::assertSame( array( StatusNotifier::ASYNC_HOOK, array( 1024, 'pending' ), 'woo-bd-sms' ), WpStubs::$async[0] );
		self::assertSame( array(), $this->http->requests );

		// The same transition again in the same request is ignored.
		$notifier->on_status_changed( 1024, 'pending', 'processing' );
		self::assertCount( 1, WpStubs::$async );
	}

	public function test_disabled_statuses_and_plugin_are_ignored(): void {
		$this->order( array( 'status' => 'completed' ) );
		$notifier = new StatusNotifier( $this->services() );
		$notifier->on_status_changed( 1024, 'processing', 'completed' );

		self::assertSame( array(), WpStubs::$async );
		self::assertSame( array(), $notifier->run( 1024 ) );
	}

	public function test_synchronous_sending_when_async_is_disabled(): void {
		add_filter( 'woo_bd_sms_send_async', '__return_false' );
		$order = $this->order();
		$this->http->queue( 200, self::OK )->queue( 200, self::OK );

		( new StatusNotifier( $this->services() ) )->on_status_changed( 1024, 'pending', 'processing' );

		self::assertCount( 2, $this->http->requests );
		self::assertSame( 'Hi Karim, order #1024 of 1,250 BDT (3 items) is Processing. Dhaka Shop & Co', $this->http->requests[0]->body_fields()['msg'] );
		self::assertSame( '8801711234567', $this->http->requests[0]->body_fields()['to'] );
		self::assertSame( '8801811111112', $this->http->requests[1]->body_fields()['to'] );
		self::assertSame( 'New #1024 01711234567 Cash on delivery', $this->http->requests[1]->body_fields()['msg'] );
		self::assertSame( array( 'SMS sent to customer 0171****567.', 'SMS sent to admin 0181****112.' ), $order->notes );
		self::assertNotSame( '', $order->meta['_woo_bd_sms_sent_processing'] );
		self::assertSame( 1, $order->saves );
	}

	public function test_run_sends_once_per_status_unless_forced(): void {
		$order    = $this->order();
		$notifier = new StatusNotifier( $this->services() );
		$this->http->queue( 200, self::OK )->queue( 200, self::OK )->queue( 200, self::OK )->queue( 200, '{"error":417,"msg":"Insufficient balance"}' );

		self::assertCount( 2, $notifier->run( 1024, 'pending' ) );
		self::assertSame( array(), $notifier->run( 1024, 'pending' ) );

		$entries = $notifier->run( 1024, '', true );
		self::assertSame( LogEntry::STATUS_SENT, $entries[0]->status );
		self::assertSame( LogEntry::STATUS_FAILED, $entries[1]->status );
		self::assertSame( 'SMS to admin 0181****112 was not sent: Insufficient balance', end( $order->notes ) );
	}

	public function test_messages_filter_and_processed_action(): void {
		$this->order();
		add_filter(
			'woo_bd_sms_order_messages',
			static function ( array $messages ): array {
				return array_slice( $messages, 0, 1 );
			}
		);
		$this->http->queue( 200, self::OK );
		( new StatusNotifier( $this->services() ) )->run( 1024 );

		$fired = array_values(
			array_filter(
				WpStubs::$actions,
				static function ( array $action ): bool {
					return 'woo_bd_sms_order_sms_processed' === $action[0];
				}
			)
		);
		self::assertCount( 1, $fired );
		self::assertSame( LogEntry::TYPE_CUSTOMER, $fired[0][1][0]->type );
	}

	public function test_invalid_customer_phone_is_logged_and_noted(): void {
		$order = $this->order( array( 'billing_phone' => '029112233' ) );
		$this->http->queue( 200, self::OK );
		$entries = ( new StatusNotifier( $this->services() ) )->run( 1024 );

		self::assertSame( LogEntry::STATUS_SKIPPED, $entries[0]->status );
		self::assertStringContainsString( 'was not sent: Not a valid Bangladeshi mobile number', $order->notes[0] );
		self::assertSame( LogEntry::STATUS_SENT, $entries[1]->status );
	}

	public function test_missing_order(): void {
		self::assertSame( array(), ( new StatusNotifier( $this->services() ) )->run( 999 ) );
	}
}
