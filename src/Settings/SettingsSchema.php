<?php
/**
 * Settings defaults and validation.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Settings;

use Saizul\BdSms\Gateway\FailoverPolicy;
use Saizul\BdSms\Gateway\FieldDefinition;
use Saizul\BdSms\Gateway\GatewayRegistry;
use Saizul\BdSms\Phone\PhoneNormalizer;
use Saizul\BdSms\Template\Placeholders;
use Saizul\BdSms\Template\TemplateRenderer;

/**
 * Describes the settings array and turns submitted values into a clean copy.
 *
 * Secrets left empty in a submission keep their stored value; a
 * "<field>__clear" flag removes them.
 */
final class SettingsSchema {

	public const MAX_TEMPLATE_LENGTH = 1000;

	/**
	 * Gateway registry.
	 *
	 * @var GatewayRegistry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param GatewayRegistry|null $registry Registry.
	 */
	public function __construct( ?GatewayRegistry $registry = null ) {
		$this->registry = $registry ?? new GatewayRegistry();
	}

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		$gateways = array();
		foreach ( $this->registry->ids() as $id ) {
			foreach ( $this->registry->fields( $id ) as $field ) {
				$gateways[ $id ][ $field->key ] = $field->default;
			}
		}

		return array(
			'enabled'             => true,
			'gateway'             => 'bulksmsbd',
			'gateways'            => $gateways,
			'failover'            => array(
				'gateway'   => '',
				'mode'      => FailoverPolicy::MODE_TEMPORARY,
				'codes'     => '',
				'threshold' => 3,
				'cooldown'  => 300,
			),
			'admin_phones'        => '',
			'templates'           => self::default_templates(),
			'tracking_meta_keys'  => '',
			'tracking_url'        => '',
			'rate'                => array(
				'per_phone_limit'  => 10,
				'per_phone_window' => 3600,
				'global_limit'     => 120,
				'global_window'    => 60,
			),
			'retry'               => array(
				'max_attempts' => 3,
				'base_delay'   => 60,
			),
			'max_segments'        => 6,
			'plain_punctuation'   => true,
			'otp'                 => array(
				'enabled'         => false,
				'methods'         => array( 'cod' ),
				'min_total'       => 0,
				'length'          => 6,
				'ttl'             => 300,
				'max_attempts'    => 5,
				'resend_cooldown' => 60,
				'max_sends'       => 3,
				'send_window'     => 3600,
				'template'        => 'Your {site_name} verification code is {otp}. It expires in {minutes} minutes. Do not share it.',
			),
			'log_retention_days'  => 30,
			'delete_on_uninstall' => false,
		);
	}

	/**
	 * Default templates for the core WooCommerce statuses.
	 *
	 * @return array<string,array{customer_enabled:bool,customer:string,admin_enabled:bool,admin:string}>
	 */
	public static function default_templates(): array {
		$admin = 'Order #{order_number} is now {status}: {total} {currency}, {name}, {phone}.';

		return array(
			'pending'    => self::row( false, 'Hi {first_name}, we received order #{order_number} ({total} {currency}). Please complete the payment to confirm it. {site_name}', false, $admin ),
			'processing' => self::row( true, 'Hi {first_name}, thank you for your order #{order_number} of {total} {currency}. We are preparing it now. {site_name}', true, 'New order #{order_number}: {total} {currency}, {item_count} item(s), {name}, {phone}, {payment_method}.' ),
			'on-hold'    => self::row( false, 'Hi {first_name}, order #{order_number} is on hold. We will contact you shortly. {site_name}', false, $admin ),
			'completed'  => self::row( true, 'Hi {first_name}, your order #{order_number} is complete. Tracking: {tracking|-}. Thank you for shopping with {site_name}.', false, $admin ),
			'cancelled'  => self::row( true, 'Hi {first_name}, order #{order_number} has been cancelled. Contact us if this is a mistake. {site_name}', true, $admin ),
			'refunded'   => self::row( false, 'Hi {first_name}, order #{order_number} has been refunded. {site_name}', false, $admin ),
			'failed'     => self::row( false, 'Hi {first_name}, the payment for order #{order_number} failed. Please try again. {site_name}', false, $admin ),
		);
	}

	/**
	 * Clean a submission.
	 *
	 * @param array<string,mixed> $input Submitted values (already unslashed).
	 * @param array<string,mixed> $previous Stored settings (for secrets).
	 * @param string[]            $statuses Known order status slugs (without "wc-").
	 * @param string[]            $payment_methods Known payment method ids.
	 * @return array{settings:array<string,mixed>,errors:string[]}
	 */
	public function sanitize( array $input, array $previous, array $statuses = array(), array $payment_methods = array() ): array {
		$defaults = $this->defaults();
		$previous = $this->merge_defaults( $previous );
		$errors   = array();
		$out      = $defaults;

		$out['enabled']             = ! empty( $input['enabled'] );
		$out['plain_punctuation']   = ! empty( $input['plain_punctuation'] );
		$out['delete_on_uninstall'] = ! empty( $input['delete_on_uninstall'] );

		$gateway        = (string) ( $input['gateway'] ?? $previous['gateway'] );
		$out['gateway'] = $this->registry->has( $gateway ) ? $gateway : $defaults['gateway'];

		$out['failover'] = $this->sanitize_failover( $input['failover'] ?? array(), $out['gateway'], $errors );

		$submitted = isset( $input['gateways'] ) && is_array( $input['gateways'] ) ? $input['gateways'] : array();
		foreach ( $this->registry->ids() as $id ) {
			$values = isset( $submitted[ $id ] ) && is_array( $submitted[ $id ] ) ? $submitted[ $id ] : array();
			foreach ( $this->registry->fields( $id ) as $field ) {
				$out['gateways'][ $id ][ $field->key ] = $this->field_value( $field, $values, (string) ( $previous['gateways'][ $id ][ $field->key ] ?? $field->default ) );
			}
		}

		// Admin numbers: keep only valid ones and report the rest.
		$raw_admin = self::text( (string) ( $input['admin_phones'] ?? '' ), 500 );
		$phones    = ( new PhoneNormalizer() )->parse_list( $raw_admin );
		if ( '' !== trim( $raw_admin ) && array() === $phones ) {
			$errors[] = 'None of the admin phone numbers is a valid Bangladeshi mobile number.';
		}
		$out['admin_phones'] = implode( ', ', $phones );

		$out['templates'] = $this->sanitize_templates( $input['templates'] ?? array(), $statuses, $errors );

		$out['tracking_meta_keys'] = implode(
			', ',
			array_filter(
				array_map(
					static function ( string $key ): string {
						return (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $key );
					},
					explode( ',', (string) ( $input['tracking_meta_keys'] ?? '' ) )
				)
			)
		);
		$url                       = trim( (string) ( $input['tracking_url'] ?? '' ) );
		$out['tracking_url']       = ( '' === $url || preg_match( '#^https?://\S+$#i', $url ) ) ? $url : '';
		if ( '' !== $url && '' === $out['tracking_url'] ) {
			$errors[] = 'The tracking link must start with http:// or https://.';
		}

		$rate        = isset( $input['rate'] ) && is_array( $input['rate'] ) ? $input['rate'] : array();
		$out['rate'] = array(
			'per_phone_limit'  => self::int( $rate['per_phone_limit'] ?? 10, 0, 1000 ),
			'per_phone_window' => self::int( $rate['per_phone_window'] ?? 3600, 60, 86400 ),
			'global_limit'     => self::int( $rate['global_limit'] ?? 120, 0, 100000 ),
			'global_window'    => self::int( $rate['global_window'] ?? 60, 1, 86400 ),
		);

		$retry        = isset( $input['retry'] ) && is_array( $input['retry'] ) ? $input['retry'] : array();
		$out['retry'] = array(
			'max_attempts' => self::int( $retry['max_attempts'] ?? 3, 1, 10 ),
			'base_delay'   => self::int( $retry['base_delay'] ?? 60, 10, 86400 ),
		);

		$out['max_segments']       = self::int( $input['max_segments'] ?? 6, 1, 20 );
		$out['log_retention_days'] = self::int( $input['log_retention_days'] ?? 30, 0, 3650 );

		$otp      = isset( $input['otp'] ) && is_array( $input['otp'] ) ? $input['otp'] : array();
		$methods  = isset( $otp['methods'] ) && is_array( $otp['methods'] ) ? array_map( 'strval', $otp['methods'] ) : array();
		$methods  = array_values(
			array_filter(
				$methods,
				static function ( string $method ) use ( $payment_methods ): bool {
					return 1 === preg_match( '/^[a-z0-9_\-]{1,60}$/i', $method ) && ( array() === $payment_methods || in_array( $method, $payment_methods, true ) );
				}
			)
		);
		$template = self::text( (string) ( $otp['template'] ?? '' ), self::MAX_TEMPLATE_LENGTH, true );
		if ( false === strpos( $template, '{otp}' ) ) {
			$errors[] = 'The OTP message must contain {otp}; the default message is used.';
			$template = $defaults['otp']['template'];
		}
		$out['otp'] = array(
			'enabled'         => ! empty( $otp['enabled'] ),
			'methods'         => array() === $methods ? array( 'cod' ) : $methods,
			'min_total'       => max( 0.0, round( (float) ( $otp['min_total'] ?? 0 ), 2 ) ),
			'length'          => self::int( $otp['length'] ?? 6, 4, 8 ),
			'ttl'             => self::int( $otp['ttl'] ?? 300, 60, 3600 ),
			'max_attempts'    => self::int( $otp['max_attempts'] ?? 5, 1, 10 ),
			'resend_cooldown' => self::int( $otp['resend_cooldown'] ?? 60, 0, 3600 ),
			'max_sends'       => self::int( $otp['max_sends'] ?? 3, 1, 20 ),
			'send_window'     => self::int( $otp['send_window'] ?? 3600, 60, 86400 ),
			'template'        => $template,
		);

		return array(
			'settings' => $out,
			'errors'   => $errors,
		);
	}

	/**
	 * Stored settings completed with defaults.
	 *
	 * @param array<string,mixed> $stored Stored settings.
	 * @return array<string,mixed>
	 */
	public function merge_defaults( array $stored ): array {
		return self::merge( $this->defaults(), $stored );
	}

	/**
	 * Clean the failover section.
	 *
	 * @param mixed    $input Submitted section.
	 * @param string   $primary Primary gateway id.
	 * @param string[] $errors Collected errors.
	 * @return array{gateway:string,mode:string,codes:string,threshold:int,cooldown:int}
	 */
	private function sanitize_failover( $input, string $primary, array &$errors ): array {
		$input   = is_array( $input ) ? $input : array();
		$backup  = (string) ( $input['gateway'] ?? '' );
		$mode    = (string) ( $input['mode'] ?? FailoverPolicy::MODE_TEMPORARY );
		$unknown = '' !== $backup && ! $this->registry->has( $backup );
		if ( $unknown ) {
			$errors[] = sprintf( 'Unknown backup gateway "%s"; failover is off.', self::text( $backup, 40 ) );
			$backup   = '';
		} elseif ( $backup === $primary && '' !== $backup ) {
			$errors[] = 'The backup gateway must differ from the main gateway; failover is off.';
			$backup   = '';
		}

		return array(
			'gateway'   => $backup,
			'mode'      => in_array( $mode, array( FailoverPolicy::MODE_TEMPORARY, FailoverPolicy::MODE_ANY ), true ) ? $mode : FailoverPolicy::MODE_TEMPORARY,
			'codes'     => implode( ', ', FailoverPolicy::parse_codes( explode( ',', self::text( (string) ( $input['codes'] ?? '' ), 500 ) ) ) ),
			'threshold' => self::int( $input['threshold'] ?? 3, 0, 100 ),
			'cooldown'  => self::int( $input['cooldown'] ?? 300, 10, 86400 ),
		);
	}

	/**
	 * Clean the templates section.
	 *
	 * @param mixed    $input Submitted templates.
	 * @param string[] $statuses Known statuses.
	 * @param string[] $errors Collected errors.
	 * @return array<string,array{customer_enabled:bool,customer:string,admin_enabled:bool,admin:string}>
	 */
	private function sanitize_templates( $input, array $statuses, array &$errors ): array {
		$input     = is_array( $input ) ? $input : array();
		$renderer  = new TemplateRenderer();
		$known     = Placeholders::order_names();
		$templates = array();
		$slugs     = array_unique( array_merge( array_keys( self::default_templates() ), $statuses, array_keys( $input ) ) );

		foreach ( $slugs as $slug ) {
			$slug = (string) $slug;
			if ( ! preg_match( '/^[a-z0-9_\-]{1,40}$/', $slug ) || ( array() !== $statuses && ! in_array( $slug, $statuses, true ) && ! isset( self::default_templates()[ $slug ] ) ) ) {
				continue;
			}
			$row = isset( $input[ $slug ] ) && is_array( $input[ $slug ] ) ? $input[ $slug ] : array();
			$out = self::row(
				! empty( $row['customer_enabled'] ),
				self::text( (string) ( $row['customer'] ?? ( self::default_templates()[ $slug ]['customer'] ?? '' ) ), self::MAX_TEMPLATE_LENGTH, true ),
				! empty( $row['admin_enabled'] ),
				self::text( (string) ( $row['admin'] ?? ( self::default_templates()[ $slug ]['admin'] ?? '' ) ), self::MAX_TEMPLATE_LENGTH, true )
			);
			foreach ( array( 'customer', 'admin' ) as $who ) {
				$unknown = $renderer->unknown_placeholders( $out[ $who ], $known );
				if ( array() !== $unknown ) {
					$errors[] = sprintf( 'Unknown placeholder(s) in the "%1$s" template: {%2$s}. They will be left empty.', $slug, implode( '}, {', $unknown ) );
				}
			}
			$templates[ $slug ] = $out;
		}

		return $templates;
	}

	/**
	 * Clean one gateway field.
	 *
	 * @param FieldDefinition     $field Field.
	 * @param array<string,mixed> $values Submitted gateway values.
	 * @param string              $previous Stored value.
	 */
	private function field_value( FieldDefinition $field, array $values, string $previous ): string {
		if ( $field->is_secret() ) {
			if ( ! empty( $values[ $field->key . '__clear' ] ) ) {
				return '';
			}
			$value = isset( $values[ $field->key ] ) ? trim( self::text( (string) $values[ $field->key ], 500 ) ) : '';

			return '' === $value ? $previous : $value;
		}
		if ( ! array_key_exists( $field->key, $values ) ) {
			return $previous;
		}
		$value = (string) $values[ $field->key ];
		switch ( $field->type ) {
			case FieldDefinition::URL:
				$value = trim( $value );
				return preg_match( '#^https?://\S+$#i', $value ) ? $value : $field->default;
			case FieldDefinition::SELECT:
				return isset( $field->options[ $value ] ) ? $value : $field->default;
			case FieldDefinition::TEXTAREA:
				return self::text( $value, 4000, true );
			default:
				return self::text( $value, 200 );
		}
	}

	/**
	 * Template row.
	 *
	 * @param bool   $customer_enabled Customer enabled.
	 * @param string $customer Customer template.
	 * @param bool   $admin_enabled Admin enabled.
	 * @param string $admin Admin template.
	 * @return array{customer_enabled:bool,customer:string,admin_enabled:bool,admin:string}
	 */
	private static function row( bool $customer_enabled, string $customer, bool $admin_enabled, string $admin ): array {
		return array(
			'customer_enabled' => $customer_enabled,
			'customer'         => $customer,
			'admin_enabled'    => $admin_enabled,
			'admin'            => $admin,
		);
	}

	/**
	 * Plain text without tags or control characters.
	 *
	 * @param string $value Value.
	 * @param int    $max Maximum characters.
	 * @param bool   $multiline Keep line breaks.
	 */
	public static function text( string $value, int $max, bool $multiline = false ): string {
		$value = strip_tags( $value );
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$value = (string) preg_replace( $multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', '', $value );
		$value = trim( $value );

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max, 'UTF-8' ) : substr( $value, 0, $max );
	}

	/**
	 * Clamp an integer.
	 *
	 * @param mixed $value Value.
	 * @param int   $min Minimum.
	 * @param int   $max Maximum.
	 */
	private static function int( $value, int $min, int $max ): int {
		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Recursive merge where stored values replace defaults and lists are taken as a whole.
	 *
	 * @param array<string,mixed> $defaults Defaults.
	 * @param array<string,mixed> $stored Stored values.
	 * @return array<string,mixed>
	 */
	private static function merge( array $defaults, array $stored ): array {
		foreach ( $stored as $key => $value ) {
			if ( is_array( $value ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && array_keys( $defaults[ $key ] ) !== range( 0, count( $defaults[ $key ] ) - 1 ) ) {
				$defaults[ $key ] = self::merge( $defaults[ $key ], $value );
			} else {
				$defaults[ $key ] = $value;
			}
		}

		return $defaults;
	}
}
