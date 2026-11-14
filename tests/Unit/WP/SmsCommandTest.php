<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\WP;

use Saizul\BdSms\Tests\WpTestCase;
use Saizul\BdSms\WP\Cli\SmsCommand;
use Saizul\BdSms\WP\Order\StatusNotifier;
use WP_CLI;
use WpCliExit;

final class SmsCommandTest extends WpTestCase {

	private const OK = '{"error":0,"msg":"Request successfully submitted","data":{"request_id":9}}';

	protected function setUp(): void {
		parent::setUp();
		$this->settings_option(
			array(
				'gateway'  => 'alpha_sms',
				'gateways' => array( 'alpha_sms' => array( 'api_key' => 'alpha-test-key' ) ),
			)
		);
	}

	private function command(): SmsCommand {
		$services = $this->services();

		return new SmsCommand( $services, new StatusNotifier( $services ) );
	}

	public function test_test_sends_and_reports(): void {
		$this->http->queue( 200, self::OK );
		$this->command()->test( array( '01711234567' ), array( 'message' => 'Hello' ) );

		self::assertSame( array( 'log', 'Message: 1 SMS, GSM-7, 5 characters.' ), WP_CLI::$log[0] );
		self::assertSame( 'success', WP_CLI::$log[1][0] );
		self::assertStringContainsString( 'test 0171****567: sent', WP_CLI::$log[1][1] );
	}

	public function test_test_dry_run_redacts_secrets(): void {
		$this->command()->test( array( '01711234567' ), array( 'message' => 'আপনার অর্ডার', 'dry-run' => true ) );

		$lines = array_column( WP_CLI::$log, 1 );
		self::assertSame( 'Message: 1 SMS, UCS-2, 12 characters.', $lines[0] );
		self::assertSame( 'POST https://api.sms.net.bd/sendsms', $lines[1] );
		self::assertStringContainsString( 'api_key=[redacted]', implode( "\n", $lines ) );
		self::assertStringNotContainsString( 'alpha-test-key', implode( "\n", $lines ) );
		self::assertSame( array( 'success', 'Dry run: nothing was sent.' ), end( WP_CLI::$log ) );
		self::assertSame( array(), $this->http->requests );
	}

	public function test_test_rejects_unknown_gateway_and_failed_sends(): void {
		try {
			$this->command()->test( array( '01711234567' ), array( 'gateway' => 'nope' ) );
			self::fail( 'Expected an error.' );
		} catch ( WpCliExit $e ) {
			self::assertStringContainsString( 'Unknown gateway "nope"', $e->getMessage() );
		}

		$this->expectException( WpCliExit::class );
		$this->command()->test( array( '0171' ), array() );
	}

	public function test_send_uses_templates_or_a_custom_message(): void {
		$this->order( array( 'meta' => array( '_tracking_number' => 'SF1' ) ) );
		$this->http->queue( 200, self::OK )->queue( 200, self::OK );

		$this->command()->send( array( '1024' ), array() );
		self::assertSame( array( 'success', '1 SMS processed.' ), end( WP_CLI::$log ) );

		$this->command()->send( array( '1024' ), array( 'message' => 'Parcel {tracking} for {first_name}' ) );
		self::assertSame( 'Parcel SF1 for Karim', $this->http->last()->body_fields()['msg'] );

		$this->expectException( WpCliExit::class );
		$this->command()->send( array( '5' ), array() );
	}

	public function test_log_and_count(): void {
		$this->http->queue( 200, self::OK );
		$this->command()->test( array( '01711234567' ), array( 'message' => 'Hello' ) );
		$this->command()->test( array( '01811234567' ), array( 'message' => 'Hello', 'dry-run' => true ) );

		$this->command()->log( array(), array( 'status' => 'sent' ) );
		list( $format, $rows, $fields ) = end( WP_CLI::$items );
		self::assertSame( 'table', $format );
		self::assertCount( 1, $rows );
		self::assertSame( '0171****567', $rows[0]['phone'] );
		self::assertContains( 'message', $fields );

		$this->command()->log( array(), array( 'unmask' => true, 'phone' => '+8801711234567' ) );
		self::assertSame( '01711234567', end( WP_CLI::$items )[1][0]['phone'] );

		$this->command()->log( array(), array( 'format' => 'count' ) );
		self::assertSame( array( 'line', '1' ), end( WP_CLI::$log ) );

		$this->command()->count( array( str_repeat( 'ক', 71 ) ), array( 'format' => 'json' ) );
		self::assertSame( 2, end( WP_CLI::$items )[1][0]['segments'] );
	}

	public function test_retry(): void {
		$this->http->queue( 200, '{"error":417,"msg":"Insufficient balance"}' )->queue( 200, self::OK );
		try {
			$this->command()->test( array( '01711234567' ), array() );
		} catch ( WpCliExit $e ) {
			self::assertStringContainsString( 'failed 417 Insufficient balance', $e->getMessage() );
		}

		$this->command()->retry( array( '1' ), array() );
		self::assertSame( 'success', end( WP_CLI::$log )[0] );

		$this->expectException( WpCliExit::class );
		$this->command()->retry( array( '1' ), array() );
	}
}
