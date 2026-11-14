<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Gateway;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Gateway\AlphaSmsGateway;
use Saizul\BdSms\Gateway\GatewayRegistry;
use Saizul\BdSms\Gateway\Message;
use Saizul\BdSms\Gateway\SendResult;
use Saizul\BdSms\Http\FakeHttpClient;

final class RegistryAndMessageTest extends TestCase {

	public function test_registry_defaults(): void {
		$registry = new GatewayRegistry();

		self::assertSame( array( 'bulksmsbd', 'ssl_wireless', 'alpha_sms', 'generic' ), $registry->ids() );
		self::assertTrue( $registry->has( 'alpha_sms' ) );
		self::assertInstanceOf( AlphaSmsGateway::class, $registry->create( 'alpha_sms', new FakeHttpClient(), array() ) );
		self::assertSame( 'api_key', $registry->fields( 'alpha_sms' )[0]->key );
		self::assertTrue( $registry->fields( 'alpha_sms' )[0]->is_secret() );
	}

	public function test_registry_rejects_unknown_ids(): void {
		$this->expectException( InvalidArgumentException::class );
		( new GatewayRegistry() )->create( 'nope', new FakeHttpClient(), array() );
	}

	public function test_message_formats(): void {
		$message = new Message( '8801711234567', 'Hi', 'ref-#1_2' );

		self::assertSame( '8801711234567', $message->to() );
		self::assertSame( '01711234567', $message->to_local() );
		self::assertSame( '+8801711234567', $message->to_e164() );
		self::assertSame( 'ref12', $message->reference() );
	}

	public function test_message_reference_is_generated(): void {
		$a = new Message( '8801711234567', 'Hi' );
		$b = new Message( '8801711234567', 'Hi' );

		self::assertMatchesRegularExpression( '/^[A-Z0-9]{20}$/', $a->reference() );
		self::assertNotSame( $a->reference(), $b->reference() );
	}

	public function test_message_requires_msisdn(): void {
		$this->expectException( InvalidArgumentException::class );
		new Message( '01711234567', 'Hi' );
	}

	public function test_send_result_truncates_raw_body_safely(): void {
		$result = SendResult::failed( 'x', 'y', false, 200, str_repeat( 'ক', 1000 ) );

		self::assertLessThanOrEqual( 2003, strlen( $result->raw() ) );
		self::assertSame( 1, preg_match( '//u', $result->raw() ), 'Still valid UTF-8.' );
		self::assertStringEndsWith( '...', $result->raw() );
	}
}
