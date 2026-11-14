<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\WP;

use Saizul\BdSms\Http\HttpException;
use Saizul\BdSms\Http\Request;
use Saizul\BdSms\Tests\WpTestCase;
use Saizul\BdSms\WP\Http\WpHttpClient;
use Saizul\BdSms\WP\Queue\WpRetryScheduler;
use Saizul\BdSms\WP\Store\TransientStore;
use WP_Error;
use WpStubs;

final class InfrastructureTest extends WpTestCase {

	public function test_http_client_maps_request_and_response(): void {
		WpStubs::$http_responses[] = array(
			'response' => array( 'code' => 201 ),
			'headers'  => array(
				'Content-Type' => 'application/json',
				'Set-Cookie'   => array( 'a=1', 'b=2' ),
			),
			'body'     => '{"ok":true}',
		);
		$response = ( new WpHttpClient() )->send( Request::form( 'https://api.example/send', array( 'to' => '8801711234567' ) ) );

		list( $url, $args ) = WpStubs::$http_requests[0];
		self::assertSame( 'https://api.example/send', $url );
		self::assertSame( 'POST', $args['method'] );
		self::assertSame( 'to=8801711234567', $args['body'] );
		self::assertTrue( $args['sslverify'] );
		self::assertStringStartsWith( 'woo-bd-sms-notify/0.1.0', $args['user-agent'] );
		self::assertSame( 201, $response->status() );
		self::assertSame( '{"ok":true}', $response->body() );
		self::assertSame( 'a=1, b=2', $response->header( 'set-cookie' ) );
	}

	public function test_http_client_get_has_no_body_and_args_are_filterable(): void {
		add_filter(
			'woo_bd_sms_http_args',
			static function ( array $args ): array {
				$args['timeout'] = 99;
				return $args;
			}
		);
		( new WpHttpClient() )->send( Request::get( 'https://api.example/balance', array( 'k' => 'v' ) ) );

		$args = WpStubs::$http_requests[0][1];
		self::assertArrayNotHasKey( 'body', $args );
		self::assertSame( 99, $args['timeout'] );
	}

	public function test_http_client_turns_wp_error_into_exception(): void {
		WpStubs::$http_responses[] = new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );

		$this->expectException( HttpException::class );
		$this->expectExceptionMessage( 'Could not resolve host' );
		( new WpHttpClient() )->send( Request::get( 'https://nowhere.invalid/' ) );
	}

	public function test_transient_store(): void {
		$store = new TransientStore();
		$store->set( 'otp_abc', array( 'hash' => 'x' ), 300 );

		self::assertSame( array( 'hash' => 'x' ), WpStubs::$transients['woo_bd_sms_otp_abc'] );
		self::assertSame( array( 'hash' => 'x' ), $store->get( 'otp_abc' ) );
		self::assertNull( $store->get( 'missing' ) );
		$store->delete( 'otp_abc' );
		self::assertNull( $store->get( 'otp_abc' ) );

		$long = str_repeat( 'k', 200 );
		$store->set( $long, 1, 10 );
		self::assertArrayHasKey( 'woo_bd_sms_' . md5( $long ), WpStubs::$transients );
	}

	public function test_retry_scheduler_uses_action_scheduler(): void {
		( new WpRetryScheduler() )->schedule( 42, 120 );
		$job = WpStubs::$async[0];

		self::assertSame( 'woo_bd_sms_retry', $job[0] );
		self::assertSame( array( 42 ), $job[1] );
		self::assertSame( 'woo-bd-sms', $job[2] );
		self::assertGreaterThanOrEqual( time() + 119, $job[3] );

		self::assertTrue( WpRetryScheduler::enqueue( 'woo_bd_sms_order_status', array( 1, 'pending' ) ) );
	}
}
