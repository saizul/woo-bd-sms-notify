<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Gateway;

use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Gateway\BulkSmsBdGateway;
use Saizul\BdSms\Gateway\Message;
use Saizul\BdSms\Http\FakeHttpClient;

final class BulkSmsBdGatewayTest extends TestCase {

	/** @var FakeHttpClient */
	private $http;

	protected function setUp(): void {
		$this->http = new FakeHttpClient();
	}

	private function gateway( array $config = array() ): BulkSmsBdGateway {
		return new BulkSmsBdGateway(
			$this->http,
			$config + array(
				'api_key'   => 'TEST_API_KEY_123',
				'sender_id' => '8809617000000',
			)
		);
	}

	public function test_builds_the_documented_request(): void {
		$this->http->queue( 200, '{"response_code":202,"message_id":7788,"success_message":"SMS Submitted Successfully","error_message":""}' );
		$result  = $this->gateway()->send( new Message( '8801711234567', 'Order #1024 & more', 'REF1' ) );
		$request = $this->http->last();

		self::assertNotNull( $request );
		self::assertSame( 'POST', $request->method() );
		self::assertSame( 'https://bulksmsbd.net/api/smsapi', $request->url() );
		self::assertSame( 'application/x-www-form-urlencoded', $request->headers()['Content-Type'] );
		self::assertSame(
			array(
				'api_key'  => 'TEST_API_KEY_123',
				'type'     => 'text',
				'number'   => '8801711234567',
				'senderid' => '8809617000000',
				'message'  => 'Order #1024 & more',
			),
			$request->body_fields()
		);
		self::assertTrue( $result->is_success() );
		self::assertSame( '7788', $result->message_id() );
	}

	public function test_bangla_text_is_sent_as_utf8(): void {
		$this->http->queue( 200, '{"response_code":202}' );
		$this->gateway()->send( new Message( '8801811234567', 'আপনার অর্ডার পাঠানো হয়েছে' ) );

		self::assertSame( 'আপনার অর্ডার পাঠানো হয়েছে', $this->http->last()->body_fields()['message'] );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string,3:bool}>
	 */
	public function provide_errors(): array {
		return array(
			'balance'         => array( '{"response_code":1007,"error_message":""}', '1007', 'Balance Insufficient', false ),
			'sender id'       => array( '{"response_code":1002,"error_message":"sender id not correct/sender id is disabled"}', '1002', 'sender id not correct/sender id is disabled', false ),
			'ip whitelist'    => array( '{"response_code":1032}', '1032', 'IP Not whitelisted', false ),
			'internal error'  => array( '{"response_code":1005}', '1005', 'Internal Error', true ),
			'plain text code' => array( '1003', '1003', 'Please Required all fields/Contact Your System Administrator', false ),
			'unknown code'    => array( '{"response_code":1999}', '1999', 'BulkSMSBD error 1999.', false ),
		);
	}

	/**
	 * @dataProvider provide_errors
	 */
	public function test_error_codes( string $body, string $code, string $message, bool $retryable ): void {
		$this->http->queue( 200, $body );
		$result = $this->gateway()->send( new Message( '8801711234567', 'Hi' ) );

		self::assertFalse( $result->is_success() );
		self::assertSame( $code, $result->error_code() );
		self::assertSame( $message, $result->error_message() );
		self::assertSame( $retryable, $result->is_retryable() );
	}

	public function test_plain_text_success_code(): void {
		$this->http->queue( 200, '202' );

		self::assertTrue( $this->gateway()->send( new Message( '8801711234567', 'Hi' ) )->is_success() );
	}

	public function test_unexpected_responses(): void {
		$this->http->queue( 503, '<html>Service Unavailable</html>' );
		$result = $this->gateway()->send( new Message( '8801711234567', 'Hi' ) );

		self::assertSame( 'http_503', $result->error_code() );
		self::assertTrue( $result->is_retryable() );

		$this->http->queue( 200, '<html>login</html>' );
		self::assertFalse( $this->gateway()->send( new Message( '8801711234567', 'Hi' ) )->is_retryable() );
	}

	public function test_transport_errors_are_retryable(): void {
		$this->http->queue_error( 'cURL error 28: Operation timed out' );
		$result = $this->gateway()->send( new Message( '8801711234567', 'Hi' ) );

		self::assertSame( 'http', $result->error_code() );
		self::assertTrue( $result->is_retryable() );
	}

	public function test_missing_credentials_do_not_call_the_api(): void {
		$gateway = new BulkSmsBdGateway( $this->http, array() );
		$result  = $gateway->send( new Message( '8801711234567', 'Hi' ) );

		self::assertSame( 'config', $result->error_code() );
		self::assertFalse( $result->is_retryable() );
		self::assertSame( array( 'API key is required.', 'Sender ID is required.' ), $gateway->validate() );
		self::assertSame( array(), $this->http->requests );
	}

	public function test_api_key_is_redacted_from_the_raw_response(): void {
		$this->http->queue( 200, '{"response_code":1013,"error_message":"key TEST_API_KEY_123 has no gateway"}' );
		$result = $this->gateway()->send( new Message( '8801711234567', 'Hi' ) );

		self::assertStringNotContainsString( 'TEST_API_KEY_123', $result->raw() );
		self::assertStringContainsString( '[redacted]', $result->raw() );
		self::assertSame( array( 'TEST_API_KEY_123' ), $this->gateway()->secrets() );
	}

	public function test_balance(): void {
		$this->http->queue( 200, '{"response_code":202,"balance":"512.25"}' );

		self::assertSame( '512.25', $this->gateway()->balance() );
		self::assertSame( 'https://bulksmsbd.net/api/getBalanceApi', $this->http->last()->url() );
		self::assertSame( array( 'api_key' => 'TEST_API_KEY_123' ), $this->http->last()->body_fields() );

		$this->http->queue( 200, 'oops' );
		self::assertNull( $this->gateway()->balance() );
		self::assertNull( ( new BulkSmsBdGateway( $this->http, array() ) )->balance() );
	}

	public function test_custom_endpoint(): void {
		$this->http->queue( 200, '{"response_code":202}' );
		$this->gateway( array( 'endpoint' => 'https://proxy.example/smsapi' ) )->send( new Message( '8801711234567', 'Hi' ) );

		self::assertSame( 'https://proxy.example/smsapi', $this->http->last()->url() );
		self::assertContains( 'API URL must be an http(s) URL.', $this->gateway( array( 'endpoint' => 'ftp://x' ) )->validate() );
	}
}
