<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\WP;

use Saizul\BdSms\Gateway\AlphaSmsGateway;
use Saizul\BdSms\Gateway\FailoverGateway;
use Saizul\BdSms\Log\LogEntry;
use Saizul\BdSms\Settings\SettingsSchema;
use Saizul\BdSms\Sms\OutgoingSms;
use Saizul\BdSms\Tests\WpTestCase;
use Saizul\BdSms\WP\Cli\SmsCommand;
use Saizul\BdSms\WP\Order\StatusNotifier;
use WP_CLI;

final class FailoverTest extends WpTestCase {

	private const ALPHA_OK = '{"error":0,"msg":"Request successfully submitted","data":{"request_id":9}}';

	protected function setUp(): void {
		parent::setUp();
		$this->settings_option(
			array(
				'gateway'  => 'bulksmsbd',
				'gateways' => array(
					'bulksmsbd' => array(
						'api_key'   => 'bulk-test-key',
						'sender_id' => '8809617000000',
					),
					'alpha_sms' => array( 'api_key' => 'alpha-test-key' ),
				),
				'failover' => array(
					'gateway'   => 'alpha_sms',
					'threshold' => 2,
					'cooldown'  => 600,
				),
			)
		);
	}

	private function command(): SmsCommand {
		$services = $this->services();

		return new SmsCommand( $services, new StatusNotifier( $services ) );
	}

	public function test_backup_id_from_settings_and_constant(): void {
		self::assertSame( 'alpha_sms', $this->settings()->failover_gateway_id() );

		$this->constants['WOO_BD_SMS_FAILOVER_GATEWAY'] = 'ssl_wireless';
		self::assertSame( 'ssl_wireless', $this->settings()->failover_gateway_id() );

		$this->constants['WOO_BD_SMS_FAILOVER_GATEWAY'] = '';
		self::assertSame( '', $this->settings()->failover_gateway_id(), 'An empty constant turns failover off.' );

		$this->constants['WOO_BD_SMS_FAILOVER_GATEWAY'] = 'nope';
		self::assertSame( '', $this->settings()->failover_gateway_id() );

		$this->constants = array( 'WOO_BD_SMS_GATEWAY' => 'alpha_sms' );
		self::assertSame( '', $this->settings()->failover_gateway_id(), 'The backup cannot be the active gateway.' );
	}

	public function test_sender_uses_the_backup_when_the_primary_is_down(): void {
		$services = $this->services();
		self::assertInstanceOf( FailoverGateway::class, $services->sending_gateway() );

		$this->http->queue_error( 'Connection timed out' )->queue( 200, self::ALPHA_OK );
		$entry = $services->sender()->send( new OutgoingSms( '01711234567', 'Hello', LogEntry::TYPE_CUSTOMER, 'processing', 1024 ) );

		self::assertSame( LogEntry::STATUS_SENT, $entry->status );
		self::assertSame( AlphaSmsGateway::ID, $entry->gateway );
		self::assertSame( 'https://bulksmsbd.net/api/smsapi', $this->http->requests[0]->url() );
		self::assertSame( 'https://api.sms.net.bd/sendsms', $this->http->requests[1]->url() );
	}

	public function test_explicit_gateway_override_skips_failover(): void {
		$services = $this->services();
		$this->http->queue_error( 'Connection timed out' );

		$entry = $services->sender( $services->gateway() )->send( new OutgoingSms( '01711234567', 'Hello' ) );

		self::assertSame( LogEntry::STATUS_RETRYING, $entry->status );
		self::assertSame( 'bulksmsbd', $entry->gateway );
		self::assertCount( 1, $this->http->requests );
	}

	public function test_cli_shows_and_resets_the_breaker(): void {
		$services = $this->services();
		$this->http->queue_error( 'down' )->queue( 200, self::ALPHA_OK )->queue_error( 'down' )->queue( 200, self::ALPHA_OK );
		$services->sender()->send( new OutgoingSms( '01711234567', 'One' ) );
		$services->sender()->send( new OutgoingSms( '01711234567', 'Two' ) );

		$command = new SmsCommand( $services, new StatusNotifier( $services ) );
		$command->failover( array(), array() );
		$row = end( WP_CLI::$items )[1][0];
		self::assertSame( 'bulksmsbd', $row['primary'] );
		self::assertSame( 'alpha_sms', $row['backup'] );
		self::assertSame( 2, $row['failures'] );
		self::assertSame( 'open', $row['state'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', 1762956600 ) . ' UTC', $row['open_until'] );

		$command->failover( array(), array( 'reset' => true ) );
		self::assertSame( array( 'success', 'Breaker closed; the next SMS tries bulksmsbd first.' ), end( WP_CLI::$log ) );
		$command->failover( array(), array() );
		self::assertSame( 'closed', end( WP_CLI::$items )[1][0]['state'] );
	}

	public function test_cli_reports_when_failover_is_off(): void {
		$this->constants['WOO_BD_SMS_FAILOVER_GATEWAY'] = '';
		$this->command()->failover( array(), array() );

		self::assertStringStartsWith( 'Failover is off: every SMS goes through bulksmsbd.', end( WP_CLI::$log )[1] );
	}

	public function test_schema_sanitizes_the_failover_section(): void {
		$schema = new SettingsSchema();
		self::assertSame( '', $schema->defaults()['failover']['gateway'] );

		$clean = $schema->sanitize(
			array(
				'gateway'  => 'bulksmsbd',
				'failover' => array(
					'gateway'   => 'alpha_sms',
					'mode'      => 'any',
					'codes'     => '1007, 1002;drop, 1007',
					'threshold' => '-3',
					'cooldown'  => '5',
				),
			),
			array()
		);
		self::assertStringNotContainsString( 'backup', implode( ' ', $clean['errors'] ) );
		self::assertSame(
			array(
				'gateway'   => 'alpha_sms',
				'mode'      => 'any',
				'codes'     => '1007, 1002drop',
				'threshold' => 0,
				'cooldown'  => 10,
			),
			$clean['settings']['failover']
		);

		$same = $schema->sanitize( array( 'gateway' => 'alpha_sms', 'failover' => array( 'gateway' => 'alpha_sms', 'mode' => 'x' ) ), array() );
		self::assertSame( '', $same['settings']['failover']['gateway'] );
		self::assertSame( 'temporary', $same['settings']['failover']['mode'] );
		self::assertStringContainsString( 'must differ', implode( ' ', $same['errors'] ) );

		$unknown = $schema->sanitize( array( 'failover' => array( 'gateway' => 'nope' ) ), array() );
		self::assertSame( '', $unknown['settings']['failover']['gateway'] );
		self::assertStringContainsString( 'Unknown backup gateway "nope"', implode( ' ', $unknown['errors'] ) );
	}
}
