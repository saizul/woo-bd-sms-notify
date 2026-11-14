<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Gateway;

use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Gateway\AlphaSmsGateway;
use Saizul\BdSms\Gateway\Message;
use Saizul\BdSms\Http\FakeHttpClient;

final class AlphaSmsGatewayTest extends TestCase {

	/** @var FakeHttpClient */
	private $http;

	protected function setUp(): void {
		$this->http = new FakeHttpClient();
	}

	private function gateway( string $sender = '' ): AlphaSmsGateway {
		return new AlphaSmsGateway(
			$this->http,
			array(
				'api_key'   => 'alpha-test-key',
				'sender_id' => $sender,
			)
		);
	}

	public function test_builds_the_documented_request(): void {
		$this->http->queue( 200, '{"error":0,"msg":"Request successfully submitted","data":{"request_id":12345}}' );
		$result  = $this->gateway()->send( new Message( '8801911234567', 'Hello' ) );
		$request = $this->http->last();

		self::assertSame( 'POST', $request->method() );
		self::assertSame( 'https://api.sms.net.bd/sendsms', $request->url() );
		self::assertSame(
			array(
				'api_key' => 'alpha-test-key',
				'msg'     => 'Hello',
				'to'      => '8801911234567',
			),
			$request->body_fields()
		);
		self::assertTrue( $result->is_success() );
		self::assertSame( '12345', $result->message_id() );
	}

	public function test_sender_id_is_optional(): void {
		$this->http->queue( 200, '{"error":0,"msg":"ok","data":{"request_id":1}}' );
		$this->gateway( 'DHAKASHOP' )->send( new Message( '8801911234567', 'Hello' ) );

		self::assertSame( 'DHAKASHOP', $this->http->last()->body_fields()['sender_id'] );
		self::assertSame( array(), $this->gateway()->validate() );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string,3:bool}>
	 */
	public function provide_errors(): array {
		return array(
			'with message'    => array( '{"error":417,"msg":"Insufficient balance"}', '417', 'Insufficient balance', false ),
			'without message' => array( '{"error":413}', '413', 'Invalid Sender ID', false ),
			'server error'    => array( '{"error":409,"msg":""}', '409', 'Unknown error occurred on Server end.', true ),
			'not documented'  => array( '{"error":499}', '499', 'Alpha SMS error 499.', false ),
		);
	}

	/**
	 * @dataProvider provide_errors
	 */
	public function test_errors( string $body, string $code, string $message, bool $retryable ): void {
		$this->http->queue( 200, $body );
		$result = $this->gateway()->send( new Message( '8801911234567', 'Hello' ) );

		self::assertFalse( $result->is_success() );
		self::assertSame( $code, $result->error_code() );
		self::assertSame( $message, $result->error_message() );
		self::assertSame( $retryable, $result->is_retryable() );
	}

	public function test_balance_uses_get_with_api_key(): void {
		$this->http->queue( 200, '{"error":0,"msg":"Success","data":{"balance":"120.5000"}}' );

		self::assertSame( '120.5000', $this->gateway()->balance() );
		$request = $this->http->last();
		self::assertSame( 'GET', $request->method() );
		self::assertSame( 'https://api.sms.net.bd/user/balance/?api_key=alpha-test-key', $request->url() );

		$this->http->queue( 200, '{"error":405,"msg":"Authorization required"}' );
		self::assertNull( $this->gateway()->balance() );
		$this->http->queue_error( 'timeout' );
		self::assertNull( $this->gateway()->balance() );
	}
}
