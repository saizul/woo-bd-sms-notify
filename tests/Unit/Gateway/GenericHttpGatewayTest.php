<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Gateway;

use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Gateway\GenericHttpGateway;
use Saizul\BdSms\Gateway\GenericPresets;
use Saizul\BdSms\Gateway\Message;
use Saizul\BdSms\Http\FakeHttpClient;

final class GenericHttpGatewayTest extends TestCase {

	/** @var FakeHttpClient */
	private $http;

	protected function setUp(): void {
		$this->http = new FakeHttpClient();
	}

	private function gateway( array $config ): GenericHttpGateway {
		return new GenericHttpGateway(
			$this->http,
			$config + array(
				'url'       => 'https://sms.example/api/send',
				'api_key'   => 'generic-key-9999',
				'sender_id' => 'SHOP',
			)
		);
	}

	public function test_get_with_query_string(): void {
		$gateway = $this->gateway(
			array(
				'method' => 'GET',
				'params' => "key={api_key}\nto={to_local}\nfrom={sender_id}\ntext={message}\n# comment\nbroken line",
			)
		);
		$this->http->queue( 200, 'OK' );
		$result = $gateway->send( new Message( '8801711234567', 'Hi & bye' ) );

		$request = $this->http->last();
		self::assertSame( 'GET', $request->method() );
		self::assertSame( 'https://sms.example/api/send?key=generic-key-9999&to=01711234567&from=SHOP&text=Hi%20%26%20bye', $request->url() );
		self::assertSame( '', $request->body() );
		self::assertTrue( $result->is_success() );
	}

	public function test_json_post_with_headers_and_json_rule(): void {
		$gateway = $this->gateway(
			array(
				'method'          => 'POST',
				'body_format'     => 'json',
				'params'          => "to={to_e164}\ntext={message}\nref={reference}\ntype={type}\nunicode={unicode}",
				'headers'         => "Authorization: Bearer {api_key}\nX-Bad Header: ignored\nX-Tenant: shop",
				'success_type'    => 'json',
				'success_value'   => 'status=success && data.id',
				'message_id_path' => 'data.id',
				'error_path'      => 'error.message',
			)
		);
		$this->http->queue( 200, '{"status":"SUCCESS","data":{"id":"m-1"}}' );
		$result = $gateway->send( new Message( '8801711234567', 'আপনার অর্ডার', 'ABC' ) );

		$request = $this->http->last();
		self::assertSame( 'Bearer generic-key-9999', $request->headers()['Authorization'] );
		self::assertSame( 'shop', $request->headers()['X-Tenant'] );
		self::assertArrayNotHasKey( 'X-Bad Header', $request->headers() );
		self::assertSame(
			array(
				'to'      => '+8801711234567',
				'text'    => 'আপনার অর্ডার',
				'ref'     => 'ABC',
				'type'    => 'unicode',
				'unicode' => '1',
			),
			$request->body_fields()
		);
		self::assertTrue( $result->is_success() );
		self::assertSame( 'm-1', $result->message_id() );

		$this->http->queue( 200, '{"status":"error","error":{"message":"Invalid number"}}' );
		$failed = $gateway->send( new Message( '8801711234567', 'Hi' ) );
		self::assertFalse( $failed->is_success() );
		self::assertSame( 'Invalid number', $failed->error_message() );
		self::assertSame( 'http_200', $failed->error_code() );
		self::assertFalse( $failed->is_retryable() );
	}

	public function test_form_post_and_placeholders_in_url(): void {
		$gateway = $this->gateway(
			array(
				'url'          => 'https://sms.example/{api_key}/send',
				'params'       => "number={to}\nmessage={message}",
				'success_type' => 'contains',
				'success_value' => 'accepted',
			)
		);
		$this->http->queue( 200, 'Message ACCEPTED' );

		self::assertTrue( $gateway->send( new Message( '8801711234567', 'Hi' ) )->is_success() );
		self::assertSame( 'https://sms.example/generic-key-9999/send', $this->http->last()->url() );
		self::assertSame( array( 'number' => '8801711234567', 'message' => 'Hi' ), $this->http->last()->body_fields() );
	}

	public function test_temporary_http_errors_are_retryable(): void {
		$gateway = $this->gateway( array( 'params' => 'to={to}' ) );
		$this->http->queue( 429, 'slow down' );

		self::assertTrue( $gateway->send( new Message( '8801711234567', 'Hi' ) )->is_retryable() );
	}

	public function test_validation(): void {
		$gateway = new GenericHttpGateway(
			$this->http,
			array(
				'url'           => 'not a url',
				'params'        => '# only a comment',
				'success_type'  => 'regex',
				'success_value' => '(unclosed',
			)
		);

		self::assertSame(
			array(
				'Endpoint URL must be an http(s) URL.',
				'The success pattern is not a valid regular expression.',
				'At least one parameter is required.',
			),
			$gateway->validate()
		);
	}

	public function test_parse_lines(): void {
		self::assertSame(
			array(
				'a'   => 'b=c',
				'msg' => '',
			),
			GenericHttpGateway::parse_lines( "a=b=c\r\n=x\nmsg=\n#x=y", '=' )
		);
	}

	public function test_presets(): void {
		$config = GenericPresets::apply(
			'json_bearer',
			array(
				'api_key' => 'keep-me',
				'method'  => 'GET',
			)
		);

		self::assertSame( 'keep-me', $config['api_key'] );
		self::assertSame( 'POST', $config['method'] );
		self::assertSame( 'json', $config['body_format'] );
		self::assertSame( array( 'x' => 'y' ), GenericPresets::apply( 'missing', array( 'x' => 'y' ) ) );

		foreach ( GenericPresets::all() as $id => $preset ) {
			$gateway = $this->gateway( $preset['config'] );
			self::assertSame( array(), $gateway->validate(), $id );
		}
	}
}
