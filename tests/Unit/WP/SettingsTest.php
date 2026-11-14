<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\WP;

use Saizul\BdSms\Tests\WpTestCase;
use Saizul\BdSms\WP\Settings;
use WpStubs;

final class SettingsTest extends WpTestCase {

	public function test_defaults_when_nothing_is_stored(): void {
		$settings = $this->settings();

		self::assertTrue( $settings->get( 'enabled' ) );
		self::assertSame( 'bulksmsbd', $settings->gateway_id() );
		self::assertSame( 'https://bulksmsbd.net/api/smsapi', $settings->gateway_config( 'bulksmsbd' )['endpoint'] );
		self::assertNull( $settings->get( 'missing' ) );
	}

	public function test_constant_names(): void {
		self::assertSame( 'WOO_BD_SMS_BULKSMSBD_API_KEY', Settings::constant_name( 'bulksmsbd', 'api_key' ) );
		self::assertSame( 'WOO_BD_SMS_SSL_WIRELESS_API_TOKEN', Settings::constant_name( 'ssl_wireless', 'api_token' ) );
	}

	public function test_constants_override_and_are_never_stored(): void {
		$this->constants['WOO_BD_SMS_ALPHA_SMS_API_KEY'] = 'from-config';
		$this->constants['WOO_BD_SMS_GATEWAY']           = 'alpha_sms';
		$this->settings_option(
			array(
				'gateway'  => 'bulksmsbd',
				'gateways' => array(
					'alpha_sms' => array(
						'api_key'   => 'old-db-value',
						'sender_id' => 'SHOP',
					),
				),
			)
		);
		$settings = $this->settings();

		self::assertSame( 'alpha_sms', $settings->gateway_id() );
		self::assertSame( 'from-config', $settings->gateway_config( 'alpha_sms' )['api_key'] );
		self::assertSame( 'SHOP', $settings->gateway_config( 'alpha_sms' )['sender_id'] );

		$all = $settings->all();
		$settings->save( $all );
		$stored = WpStubs::$options[ Settings::OPTION ];
		self::assertSame( '', $stored['gateways']['alpha_sms']['api_key'] );
		self::assertSame( 'SHOP', $stored['gateways']['alpha_sms']['sender_id'] );
	}

	public function test_unknown_forced_gateway_is_ignored(): void {
		$this->constants['WOO_BD_SMS_GATEWAY'] = 'nope';
		$this->settings_option( array( 'gateway' => 'ssl_wireless' ) );

		self::assertSame( 'ssl_wireless', $this->settings()->gateway_id() );
	}

	public function test_otp_secret_depends_on_salt_and_optional_constant(): void {
		$a = $this->settings()->otp_secret();
		$this->constants['WOO_BD_SMS_OTP_SECRET'] = 'extra';
		$b = $this->settings()->otp_secret();

		self::assertSame( 64, strlen( $a ) );
		self::assertNotSame( $a, $b );
	}

	public function test_gateways_filter_adds_adapters(): void {
		add_filter(
			'woo_bd_sms_gateways',
			static function ( array $classes ): array {
				unset( $classes['generic'] );
				return $classes;
			}
		);

		self::assertFalse( $this->settings()->registry()->has( 'generic' ) );
	}
}
