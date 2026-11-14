<?php
/**
 * Phone verification for cash-on-delivery orders.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\WP\Checkout;

use Saizul\BdSms\Log\LogEntry;
use Saizul\BdSms\Otp\OtpException;
use Saizul\BdSms\Otp\OtpResult;
use Saizul\BdSms\Phone\PhoneNormalizer;
use Saizul\BdSms\Sms\OutgoingSms;
use Saizul\BdSms\Template\TemplateRenderer;
use Saizul\BdSms\WP\Plugin;
use Saizul\BdSms\WP\Services;
use WC_Order;
use WP_Error;

/**
 * Asks for a one-time code sent to the billing phone before a COD order
 * (or any configured payment method) can be placed on the classic
 * shortcode checkout.
 */
final class CodOtp {

	public const NONCE       = 'woo_bd_sms_otp';
	public const SESSION_KEY = 'woo_bd_sms_otp_context';
	public const ORDER_META  = '_woo_bd_sms_otp_verified';

	/**
	 * Services.
	 *
	 * @var Services
	 */
	private $services;

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( Services $services ) {
		$this->services = $services;
	}

	/**
	 * Attach hooks when OTP verification is enabled.
	 */
	public function register(): void {
		$otp = (array) $this->services->settings->get( 'otp' );
		if ( empty( $otp['enabled'] ) ) {
			return;
		}
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_field' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_woo_bd_sms_otp_send', array( $this, 'ajax_send' ) );
		add_action( 'wp_ajax_nopriv_woo_bd_sms_otp_send', array( $this, 'ajax_send' ) );
		add_action( 'wp_ajax_woo_bd_sms_otp_verify', array( $this, 'ajax_verify' ) );
		add_action( 'wp_ajax_nopriv_woo_bd_sms_otp_verify', array( $this, 'ajax_verify' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'mark_order' ), 10, 3 );
	}

	/**
	 * Payment methods that require verification.
	 *
	 * @return string[]
	 */
	public function methods(): array {
		$otp = (array) $this->services->settings->get( 'otp' );

		return array_map( 'strval', (array) ( $otp['methods'] ?? array( 'cod' ) ) );
	}

	/**
	 * Whether an order with this payment method and total needs a code.
	 *
	 * @param string $method Payment method id.
	 * @param float  $total Cart total.
	 */
	public function is_required( string $method, float $total ): bool {
		$otp      = (array) $this->services->settings->get( 'otp' );
		$required = in_array( $method, $this->methods(), true ) && $total >= (float) ( $otp['min_total'] ?? 0 );

		/**
		 * Filters whether the checkout needs phone verification.
		 *
		 * @param bool   $required Whether a code is required.
		 * @param string $method   Payment method id.
		 * @param float  $total    Cart total.
		 */
		return (bool) apply_filters( 'woo_bd_sms_otp_required', $required, $method, $total );
	}

	/**
	 * Output the verification box above the "Place order" button.
	 */
	public function render_field(): void {
		echo '<div id="woo-bd-sms-otp" class="woo-bd-sms-otp form-row" style="display:none">';
		echo '<p class="woo-bd-sms-otp__intro">' . esc_html__( 'Cash on delivery orders need a verified mobile number. We will send a code to your billing phone.', 'woo-bd-sms-notify' ) . '</p>';
		echo '<p><button type="button" class="button woo-bd-sms-otp__send">' . esc_html__( 'Send code', 'woo-bd-sms-notify' ) . '</button></p>';
		echo '<p class="woo-bd-sms-otp__entry" style="display:none">';
		echo '<label for="woo_bd_sms_otp_code">' . esc_html__( 'Verification code', 'woo-bd-sms-notify' ) . '</label> ';
		echo '<input type="text" inputmode="numeric" autocomplete="one-time-code" id="woo_bd_sms_otp_code" class="input-text" maxlength="12"> ';
		echo '<button type="button" class="button woo-bd-sms-otp__verify">' . esc_html__( 'Verify', 'woo-bd-sms-notify' ) . '</button>';
		echo '</p>';
		echo '<p class="woo-bd-sms-otp__status" role="status" aria-live="polite"></p>';
		echo '</div>';
	}

	/**
	 * Load the checkout script.
	 */
	public function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		wp_enqueue_script( 'woo-bd-sms-otp', plugins_url( 'assets/js/checkout-otp.js', WOO_BD_SMS_FILE ), array( 'jquery' ), Plugin::VERSION, true );
		wp_localize_script(
			'woo-bd-sms-otp',
			'wooBdSmsOtp',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'methods' => $this->methods(),
				'i18n'    => array(
					'sending'   => __( 'Sending...', 'woo-bd-sms-notify' ),
					'resend'    => __( 'Send a new code', 'woo-bd-sms-notify' ),
					/* translators: %d: seconds */
					'wait'      => __( 'You can request a new code in %d seconds.', 'woo-bd-sms-notify' ),
					'verified'  => __( 'Phone number verified.', 'woo-bd-sms-notify' ),
					'enterCode' => __( 'Enter the code you received.', 'woo-bd-sms-notify' ),
					'error'     => __( 'Something went wrong. Please try again.', 'woo-bd-sms-notify' ),
				),
			)
		);
	}

	/**
	 * AJAX: send a code to the billing phone.
	 */
	public function ajax_send(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		$phone = $this->posted_phone();
		if ( null === $phone ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid Bangladeshi mobile number (01XXXXXXXXX) in the billing phone field.', 'woo-bd-sms-notify' ) ), 400 );
		}

		$manager = $this->services->otp();
		try {
			$challenge = $manager->issue( $phone, $this->context() );
		} catch ( OtpException $e ) {
			$message = OtpException::COOLDOWN === $e->reason()
				/* translators: %d: seconds */
				? sprintf( __( 'Please wait %d seconds before requesting a new code.', 'woo-bd-sms-notify' ), $e->retry_after() )
				: __( 'Too many codes were requested for this number. Please try again later.', 'woo-bd-sms-notify' );
			wp_send_json_error(
				array(
					'message'     => $message,
					'retry_after' => $e->retry_after(),
				),
				429
			);
			return;
		}

		$otp      = (array) $this->services->settings->get( 'otp' );
		$renderer = new TemplateRenderer();
		$vars     = array(
			'otp'       => $challenge->code(),
			'minutes'   => (string) (int) ceil( $manager->config()->ttl / 60 ),
			'site_name' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);
		$text     = $renderer->render( (string) $otp['template'], $vars );
		$log_text = $renderer->render( (string) $otp['template'], array( 'otp' => str_repeat( '*', strlen( $challenge->code() ) ) ) + $vars );
		$entry    = $this->services->sender()->send( new OutgoingSms( $phone, $text, LogEntry::TYPE_OTP, 'otp', 0, $log_text ) );

		if ( LogEntry::STATUS_SENT !== $entry->status ) {
			wp_send_json_error( array( 'message' => __( 'The code could not be sent. Please try again in a moment.', 'woo-bd-sms-notify' ) ), 502 );
		}

		wp_send_json_success(
			array(
				/* translators: %s: masked phone number */
				'message'  => sprintf( __( 'We sent a code to %s.', 'woo-bd-sms-notify' ), \Saizul\BdSms\Support\Masker::phone( $phone ) ),
				'cooldown' => $challenge->cooldown(),
			)
		);
	}

	/**
	 * AJAX: check a code.
	 */
	public function ajax_verify(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		$phone = $this->posted_phone();
		$code  = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		if ( null === $phone ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid Bangladeshi mobile number.', 'woo-bd-sms-notify' ) ), 400 );
		}

		$result = $this->services->otp()->verify( $phone, $this->context(), $code );
		if ( $result->is_verified() ) {
			wp_send_json_success( array( 'message' => __( 'Phone number verified.', 'woo-bd-sms-notify' ) ) );
		}

		wp_send_json_error( array( 'message' => self::result_message( $result ) ), 400 );
	}

	/**
	 * Block the order when the phone is not verified.
	 *
	 * @param array<string,mixed> $data Posted checkout data.
	 * @param WP_Error            $errors Errors.
	 */
	public function validate( $data, $errors ): void {
		$data   = (array) $data;
		$method = (string) ( $data['payment_method'] ?? '' );
		$total  = ( function_exists( 'WC' ) && WC()->cart ) ? (float) WC()->cart->get_total( 'edit' ) : 0.0;
		if ( ! $this->is_required( $method, $total ) ) {
			return;
		}
		$phone = ( new PhoneNormalizer() )->to_local( (string) ( $data['billing_phone'] ?? '' ) );
		if ( null === $phone || ! $this->services->otp()->is_verified( $phone, $this->context() ) ) {
			$errors->add( 'woo_bd_sms_otp', __( 'Please verify your mobile number with the code we send by SMS before placing the order.', 'woo-bd-sms-notify' ) );
		}
	}

	/**
	 * Record the verification on the order and forget the code.
	 *
	 * @param int                 $order_id Order id.
	 * @param array<string,mixed> $posted Posted data.
	 * @param WC_Order|null       $order Order.
	 */
	public function mark_order( $order_id, $posted, $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$phone = ( new PhoneNormalizer() )->to_local( (string) $order->get_billing_phone() );
		if ( null === $phone ) {
			return;
		}
		$manager = $this->services->otp();
		if ( $manager->is_verified( $phone, $this->context() ) ) {
			$order->update_meta_data( self::ORDER_META, $phone . '|' . time() );
			$order->save();
			$manager->consume( $phone, $this->context() );
		}
	}

	/**
	 * Message for a failed verification.
	 *
	 * @param OtpResult $result Result.
	 */
	public static function result_message( OtpResult $result ): string {
		switch ( $result->status() ) {
			case OtpResult::INVALID:
				/* translators: %d: attempts left */
				return sprintf( _n( 'Wrong code. %d attempt left.', 'Wrong code. %d attempts left.', $result->attempts_left(), 'woo-bd-sms-notify' ), $result->attempts_left() );
			case OtpResult::EXPIRED:
				return __( 'The code has expired. Please request a new one.', 'woo-bd-sms-notify' );
			case OtpResult::LOCKED:
				return __( 'Too many wrong codes. Please request a new one.', 'woo-bd-sms-notify' );
			default:
				return __( 'Please request a code first.', 'woo-bd-sms-notify' );
		}
	}

	/**
	 * Normalised billing phone from the AJAX request.
	 */
	private function posted_phone(): ?string {
		$raw = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the AJAX callers.

		return ( new PhoneNormalizer() )->to_local( $raw );
	}

	/**
	 * Per-visitor context stored in the WooCommerce session.
	 */
	private function context(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return 'no-session';
		}
		$context = (string) WC()->session->get( self::SESSION_KEY );
		if ( '' === $context ) {
			$context = wp_generate_password( 32, false, false );
			WC()->session->set( self::SESSION_KEY, $context );
			if ( method_exists( WC()->session, 'has_session' ) && ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
			}
		}

		return $context;
	}
}
