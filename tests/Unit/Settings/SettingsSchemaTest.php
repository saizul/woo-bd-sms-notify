<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Settings\SettingsSchema;

final class SettingsSchemaTest extends TestCase {

	/** @var SettingsSchema */
	private $schema;

	protected function setUp(): void {
		$this->schema = new SettingsSchema();
	}

	public function test_defaults(): void {
		$defaults = $this->schema->defaults();

		self::assertSame( 'bulksmsbd', $defaults['gateway'] );
		self::assertSame( 'https://bulksmsbd.net/api/smsapi', $defaults['gateways']['bulksmsbd']['endpoint'] );
		self::assertSame( 'https://smsplus.sslwireless.com/api/v3/send-sms', $defaults['gateways']['ssl_wireless']['endpoint'] );
		self::assertTrue( $defaults['templates']['processing']['customer_enabled'] );
		self::assertFalse( $defaults['otp']['enabled'] );
		self::assertStringContainsString( '{otp}', $defaults['otp']['template'] );
	}

	public function test_sanitize_cleans_values(): void {
		$result = $this->schema->sanitize(
			array(
				'enabled'      => '1',
				'gateway'      => 'nope',
				'admin_phones' => '+880 1711-234567, 12345, 01811234567; 01711234567',
				'rate'         => array(
					'per_phone_limit' => '-5',
					'global_window'   => '999999',
				),
				'max_segments' => '50',
				'tracking_url' => 'javascript:alert(1)',
				'otp'          => array(
					'enabled'  => '1',
					'methods'  => array( 'cod', 'bad method!', 'bkash' ),
					'length'   => '12',
					'template' => 'No code here',
				),
			),
			array(),
			array(),
			array( 'cod', 'bacs' )
		);
		$settings = $result['settings'];

		self::assertTrue( $settings['enabled'] );
		self::assertSame( 'bulksmsbd', $settings['gateway'] );
		self::assertSame( '01711234567, 01811234567', $settings['admin_phones'] );
		self::assertSame( 0, $settings['rate']['per_phone_limit'] );
		self::assertSame( 86400, $settings['rate']['global_window'] );
		self::assertSame( 20, $settings['max_segments'] );
		self::assertSame( '', $settings['tracking_url'] );
		self::assertSame( array( 'cod' ), $settings['otp']['methods'] );
		self::assertSame( 8, $settings['otp']['length'] );
		self::assertStringContainsString( '{otp}', $settings['otp']['template'] );
		self::assertContains( 'The OTP message must contain {otp}; the default message is used.', $result['errors'] );
		self::assertContains( 'The tracking link must start with http:// or https://.', $result['errors'] );
	}

	public function test_secrets_are_kept_or_cleared(): void {
		$previous = array(
			'gateways' => array(
				'bulksmsbd'    => array( 'api_key' => 'stored-key' ),
				'ssl_wireless' => array( 'api_token' => 'stored-token' ),
			),
		);
		$settings = $this->schema->sanitize(
			array(
				'gateways' => array(
					'bulksmsbd'    => array(
						'api_key'   => '',
						'sender_id' => ' <b>SHOP</b> ',
						'endpoint'  => 'not-a-url',
					),
					'ssl_wireless' => array( 'api_token__clear' => '1' ),
					'alpha_sms'    => array( 'api_key' => ' new-key ' ),
				),
			),
			$previous
		)['settings'];

		self::assertSame( 'stored-key', $settings['gateways']['bulksmsbd']['api_key'] );
		self::assertSame( 'SHOP', $settings['gateways']['bulksmsbd']['sender_id'] );
		self::assertSame( 'https://bulksmsbd.net/api/smsapi', $settings['gateways']['bulksmsbd']['endpoint'] );
		self::assertSame( '', $settings['gateways']['ssl_wireless']['api_token'] );
		self::assertSame( 'new-key', $settings['gateways']['alpha_sms']['api_key'] );
	}

	public function test_select_fields_only_accept_options(): void {
		$settings = $this->schema->sanitize(
			array(
				'gateway'  => 'generic',
				'gateways' => array(
					'generic' => array(
						'method'       => 'DELETE',
						'success_type' => 'json',
						'params'       => "to={to}\nmsg={message}",
					),
				),
			),
			array()
		)['settings'];

		self::assertSame( 'generic', $settings['gateway'] );
		self::assertSame( 'POST', $settings['gateways']['generic']['method'] );
		self::assertSame( 'json', $settings['gateways']['generic']['success_type'] );
		self::assertSame( "to={to}\nmsg={message}", $settings['gateways']['generic']['params'] );
	}

	public function test_templates_for_custom_statuses_and_unknown_placeholders(): void {
		$result = $this->schema->sanitize(
			array(
				'templates' => array(
					'shipped'    => array(
						'customer_enabled' => '1',
						'customer'         => "Order {order_number} shipped via {courier}.\nTrack: {tracking_url} {shiping}",
					),
					'processing' => array( 'customer' => '<script>x</script>Hi {name}' ),
					'Bad Slug!'  => array( 'customer' => 'x' ),
					'unknown'    => array( 'customer' => 'x' ),
				),
			),
			array(),
			array( 'pending', 'processing', 'shipped' )
		);
		$templates = $result['settings']['templates'];

		self::assertTrue( $templates['shipped']['customer_enabled'] );
		self::assertSame( "Order {order_number} shipped via {courier}.\nTrack: {tracking_url} {shiping}", $templates['shipped']['customer'] );
		self::assertSame( 'xHi {name}', $templates['processing']['customer'] );
		self::assertFalse( $templates['processing']['customer_enabled'], 'Unchecked boxes are off.' );
		self::assertArrayNotHasKey( 'Bad Slug!', $templates );
		self::assertArrayNotHasKey( 'unknown', $templates );
		self::assertContains( 'Unknown placeholder(s) in the "shipped" template: {shiping}. They will be left empty.', $result['errors'] );
		self::assertCount( 1, preg_grep( '/^Unknown placeholder/', $result['errors'] ) );
	}

	public function test_merge_defaults_keeps_stored_lists(): void {
		$merged = $this->schema->merge_defaults(
			array(
				'otp'  => array( 'methods' => array( 'bkash' ) ),
				'rate' => array( 'global_limit' => 5 ),
			)
		);

		self::assertSame( array( 'bkash' ), $merged['otp']['methods'] );
		self::assertSame( 5, $merged['rate']['global_limit'] );
		self::assertSame( 3600, $merged['rate']['per_phone_window'] );
	}

	public function test_text_helper(): void {
		self::assertSame( "a\nb", SettingsSchema::text( "a\r\nb\x07", 10, true ) );
		self::assertSame( 'ab', SettingsSchema::text( "a\nb", 10 ) );
		self::assertSame( 'আপনা', SettingsSchema::text( 'আপনার', 4 ) );
	}
}
