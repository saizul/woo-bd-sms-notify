<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Gateway;

use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Gateway\Message;
use Saizul\BdSms\Gateway\SslWirelessGateway;
use Saizul\BdSms\Http\FakeHttpClient;

final class SslWirelessGatewayTest extends TestCase {

	/** @var FakeHttpClient */
	private $http;

	/** @var SslWirelessGateway */
	private $gateway;

	protected function setUp(): void {
		$this->http    = new FakeHttpClient();
		$this->gateway = new SslWirelessGateway(
			$this->http,
			array(
				'api_token' => 'test-token-0000',
				'sid'       => 'DHAKASHOP',
			)
		);
	}

	public function test_builds_the_documented_json_request(): void {
		$this->http->queue(
			200,
			'{"status":"SUCCESS","status_code":200,"error_message":"","smsinfo":[{"sms_status":"SUCCESS","status_message":"Success","msisdn":"8801711234567","sms_type":"EN","sms_body":"Hi","csms_id":"REF123","reference_id":"5f1e2d3c"}]}'
		);
		$result  = $this->gateway->send( new Message( '8801711234567', 'Hi', 'REF123' ) );
		$request = $this->http->last();

		self::assertSame( 'POST', $request->method() );
		self::assertSame( 'https://smsplus.sslwireless.com/api/v3/send-sms', $request->url() );
		self::assertSame( 'application/json', $request->headers()['Content-Type'] );
		self::assertSame( 'application/json', $request->headers()['Accept'] );
		self::assertSame(
			array(
				'api_token' => 'test-token-0000',
				'sid'       => 'DHAKASHOP',
				'msisdn'    => '8801711234567',
				'sms'       => 'Hi',
				'csms_id'   => 'REF123',
			),
			$request->body_fields()
		);
		self::assertTrue( $result->is_success() );
		self::assertSame( '5f1e2d3c', $result->message_id() );
	}

	public function test_unicode_is_not_escaped_in_json(): void {
		$this->http->queue( 200, '{"status":"SUCCESS","status_code":200,"smsinfo":[{"sms_status":"SUCCESS"}]}' );
		$this->gateway->send( new Message( '8801711234567', 'ধন্যবাদ' ) );

		self::assertStringContainsString( '"sms":"ধন্যবাদ"', $this->http->last()->body() );
	}

	public function test_documented_failure(): void {
		$this->http->queue( 200, '{"status":"FAILED","status_code":4025,"error_message":"Invalid MSISDN","smsinfo":[{"sms_status":"INVALID","status_message":"Invalid MSISDN","msisdn":"8801711234567"}]}' );
		$result = $this->gateway->send( new Message( '8801711234567', 'Hi' ) );

		self::assertFalse( $result->is_success() );
		self::assertSame( '4025', $result->error_code() );
		self::assertSame( 'Invalid MSISDN', $result->error_message() );
		self::assertFalse( $result->is_retryable() );
	}

	/**
	 * @return array<string,array{0:int,1:bool,2:string}>
	 */
	public function provide_codes(): array {
		return array(
			'unauthorized'      => array( 4001, false, 'Unauthorized' ),
			'ip not whitelisted' => array( 4003, false, 'IP Blacklisted (not whitelisted)' ),
			'too many requests' => array( 4029, true, 'Too Many Requests' ),
			'tps exceeded'      => array( 4031, true, 'TPS Exceeded' ),
			'internal error'    => array( 5000, true, 'Internal Error' ),
		);
	}

	/**
	 * @dataProvider provide_codes
	 */
	public function test_codes_without_message( int $code, bool $retryable, string $message ): void {
		$this->http->queue( 200, '{"status":"FAILED","status_code":' . $code . ',"error_message":""}' );
		$result = $this->gateway->send( new Message( '8801711234567', 'Hi' ) );

		self::assertSame( (string) $code, $result->error_code() );
		self::assertSame( $message, $result->error_message() );
		self::assertSame( $retryable, $result->is_retryable() );
	}

	public function test_per_number_status_must_be_success(): void {
		$this->http->queue( 200, '{"status":"SUCCESS","status_code":200,"error_message":"","smsinfo":[{"sms_status":"DUPLICATE","status_message":"Duplicated CSMS ID"}]}' );
		$result = $this->gateway->send( new Message( '8801711234567', 'Hi' ) );

		self::assertFalse( $result->is_success() );
		self::assertSame( 'Duplicated CSMS ID', $result->error_message() );
	}

	public function test_non_json_response(): void {
		$this->http->queue( 502, 'Bad gateway' );
		$result = $this->gateway->send( new Message( '8801711234567', 'Hi' ) );

		self::assertSame( 'http_502', $result->error_code() );
		self::assertTrue( $result->is_retryable() );
	}
}
