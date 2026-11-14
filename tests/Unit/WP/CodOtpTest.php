<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests\Unit\WP;

use Saizul\BdSms\Log\LogEntry;
use Saizul\BdSms\Log\LogQuery;
use Saizul\BdSms\Otp\OtpResult;
use Saizul\BdSms\Tests\WpTestCase;
use Saizul\BdSms\WP\Checkout\CodOtp;
use Saizul\BdSms\WP\Services;
use WP_Error;
use WpDieException;
use WpJsonResponse;

final class CodOtpTest extends WpTestCase {

	private const OK = '{"error":0,"msg":"Request successfully submitted","data":{"request_id":9}}';

	/** @var Services */
	private $services;

	/** @var CodOtp */
	private $otp;

	protected function setUp(): void {
		parent::setUp();
		$_POST    = array();
		$_REQUEST = array();
		$this->settings_option(
			array(
				'gateway'  => 'alpha_sms',
				'gateways' => array( 'alpha_sms' => array( 'api_key' => 'alpha-test-key' ) ),
				'otp'      => array(
					'enabled'   => true,
					'methods'   => array( 'cod' ),
					'min_total' => 500,
					'template'  => 'Your {site_name} code is {otp}. Valid {minutes} min.',
				),
			)
		);
		$this->services = $this->services();
		$this->otp      = new CodOtp( $this->services );
	}

	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * @param array<string,string> $post Posted fields.
	 */
	private function ajax( string $method, array $post ): WpJsonResponse {
		$_POST    = $post + array( 'nonce' => 'valid-nonce' );
		$_REQUEST = $_POST;
		try {
			$this->otp->$method();
		} catch ( WpJsonResponse $response ) {
			return $response;
		}
		self::fail( 'No JSON response.' );
	}

	public function test_register_only_when_enabled(): void {
		$this->otp->register();
		self::assertArrayHasKey( 'woocommerce_after_checkout_validation', \WpStubs::$filters );

		\WpStubs::reset();
		$this->settings_option( array( 'otp' => array( 'enabled' => false ) ) );
		( new CodOtp( $this->services() ) )->register();
		self::assertSame( array(), \WpStubs::$filters );
	}

	public function test_is_required(): void {
		self::assertTrue( $this->otp->is_required( 'cod', 500 ) );
		self::assertFalse( $this->otp->is_required( 'cod', 499.99 ) );
		self::assertFalse( $this->otp->is_required( 'bkash', 5000 ) );

		add_filter(
			'woo_bd_sms_otp_required',
			static function ( bool $required, string $method ): bool {
				return $required || 'bkash' === $method;
			},
			10,
			2
		);
		self::assertTrue( $this->otp->is_required( 'bkash', 5000 ) );
	}

	public function test_full_flow(): void {
		$this->http->queue( 200, self::OK );
		$sent = $this->ajax( 'ajax_send', array( 'phone' => '+880 1711 234567' ) );

		self::assertTrue( $sent->success );
		self::assertSame( 'We sent a code to 0171****567.', $sent->data['message'] );
		self::assertSame( 60, $sent->data['cooldown'] );

		$text = $this->http->last()->body_fields()['msg'];
		self::assertMatchesRegularExpression( '/^Your Dhaka Shop & Co code is (\d{6})\. Valid 5 min\.$/', $text );
		preg_match( '/(\d{6})/', $text, $m );

		$logged = $this->log->search( LogQuery::from_array( array( 'type' => 'otp' ) ) )[0];
		self::assertSame( 'Your Dhaka Shop & Co code is ******. Valid 5 min.', $logged->message );

		$errors = new WP_Error();
		WC()->cart->total = 800;
		$this->otp->validate( array( 'payment_method' => 'cod', 'billing_phone' => '01711234567' ), $errors );
		self::assertArrayHasKey( 'woo_bd_sms_otp', $errors->errors );

		$wrong = $this->ajax( 'ajax_verify', array( 'phone' => '01711234567', 'code' => '000000' === $m[1] ? '111111' : '000000' ) );
		self::assertFalse( $wrong->success );
		self::assertSame( 'Wrong code. 4 attempts left.', $wrong->data['message'] );

		$ok = $this->ajax( 'ajax_verify', array( 'phone' => '01711234567', 'code' => $m[1] ) );
		self::assertTrue( $ok->success );

		$errors = new WP_Error();
		$this->otp->validate( array( 'payment_method' => 'cod', 'billing_phone' => '017-1123-4567' ), $errors );
		self::assertSame( array(), $errors->errors );

		// Another phone number is not verified.
		$errors = new WP_Error();
		$this->otp->validate( array( 'payment_method' => 'cod', 'billing_phone' => '01811234567' ), $errors );
		self::assertArrayHasKey( 'woo_bd_sms_otp', $errors->errors );

		$order = $this->order( array( 'billing_phone' => '01711234567' ) );
		$this->otp->mark_order( 1024, array(), $order );
		self::assertStringStartsWith( '01711234567|', $order->meta[ CodOtp::ORDER_META ] );
		self::assertFalse( $this->services->otp()->is_verified( '01711234567', (string) WC()->session->get( CodOtp::SESSION_KEY ) ) );
	}

	public function test_send_rejects_invalid_phone_and_cooldown(): void {
		$invalid = $this->ajax( 'ajax_send', array( 'phone' => '12345' ) );
		self::assertFalse( $invalid->success );
		self::assertSame( 400, $invalid->status );

		$this->http->queue( 200, self::OK );
		$this->ajax( 'ajax_send', array( 'phone' => '01711234567' ) );
		$this->clock->advance( 10 );
		$again = $this->ajax( 'ajax_send', array( 'phone' => '01711234567' ) );

		self::assertSame( 429, $again->status );
		self::assertSame( 50, $again->data['retry_after'] );
		self::assertSame( 'Please wait 50 seconds before requesting a new code.', $again->data['message'] );
	}

	public function test_gateway_failure_is_reported(): void {
		$this->http->queue( 200, '{"error":417,"msg":"Insufficient balance"}' );
		$response = $this->ajax( 'ajax_send', array( 'phone' => '01711234567' ) );

		self::assertFalse( $response->success );
		self::assertSame( 502, $response->status );
		self::assertSame( LogEntry::STATUS_FAILED, $this->log->search( new LogQuery() )[0]->status );
	}

	public function test_nonce_is_required(): void {
		$_POST    = array( 'phone' => '01711234567', 'nonce' => 'forged' );
		$_REQUEST = $_POST;

		$this->expectException( WpDieException::class );
		$this->otp->ajax_send();
	}

	public function test_other_methods_and_small_orders_pass(): void {
		$errors = new WP_Error();
		WC()->cart->total = 100;
		$this->otp->validate( array( 'payment_method' => 'cod', 'billing_phone' => '01711234567' ), $errors );
		WC()->cart->total = 5000;
		$this->otp->validate( array( 'payment_method' => 'bacs', 'billing_phone' => '01711234567' ), $errors );

		self::assertSame( array(), $errors->errors );
	}

	public function test_result_messages(): void {
		self::assertSame( 'Wrong code. 1 attempt left.', CodOtp::result_message( new OtpResult( OtpResult::INVALID, 1 ) ) );
		self::assertSame( 'The code has expired. Please request a new one.', CodOtp::result_message( new OtpResult( OtpResult::EXPIRED ) ) );
		self::assertSame( 'Too many wrong codes. Please request a new one.', CodOtp::result_message( new OtpResult( OtpResult::LOCKED ) ) );
		self::assertSame( 'Please request a code first.', CodOtp::result_message( new OtpResult( OtpResult::MISSING ) ) );
	}

	public function test_session_context_is_created_once(): void {
		$this->http->queue( 200, self::OK );
		$this->ajax( 'ajax_send', array( 'phone' => '01711234567' ) );
		$context = WC()->session->get( CodOtp::SESSION_KEY );

		self::assertSame( 32, strlen( (string) $context ) );
		self::assertTrue( WC()->session->cookie );
		$this->ajax( 'ajax_verify', array( 'phone' => '01711234567', 'code' => '1' ) );
		self::assertSame( $context, WC()->session->get( CodOtp::SESSION_KEY ) );
	}
}
