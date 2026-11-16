<?php
/**
 * Stored settings.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\WP;

use Saizul\BdSms\Gateway\GatewayRegistry;
use Saizul\BdSms\Settings\SettingsSchema;

/**
 * Reads and writes the `woo_bd_sms_settings` option.
 *
 * Gateway credentials can be defined as constants in wp-config.php instead,
 * for example WOO_BD_SMS_BULKSMSBD_API_KEY or WOO_BD_SMS_SSL_WIRELESS_API_TOKEN.
 * A defined constant always wins and its value is never written to the
 * database. WOO_BD_SMS_GATEWAY selects the gateway and
 * WOO_BD_SMS_FAILOVER_GATEWAY the backup gateway ('' turns failover off).
 */
final class Settings {

	public const OPTION = 'woo_bd_sms_settings';

	/**
	 * Schema.
	 *
	 * @var SettingsSchema
	 */
	private $schema;

	/**
	 * Gateway registry.
	 *
	 * @var GatewayRegistry
	 */
	private $registry;

	/**
	 * Returns a constant value or null.
	 *
	 * @var callable(string):(string|null)
	 */
	private $constants;

	/**
	 * Cached settings.
	 *
	 * @var array<string,mixed>|null
	 */
	private $cache = null;

	/**
	 * Constructor.
	 *
	 * @param GatewayRegistry                     $registry Registry.
	 * @param callable(string):(string|null)|null $constants Constant lookup, for tests.
	 */
	public function __construct( GatewayRegistry $registry, ?callable $constants = null ) {
		$this->registry  = $registry;
		$this->schema    = new SettingsSchema( $registry );
		$this->constants = $constants ?? static function ( string $name ): ?string {
			return defined( $name ) ? (string) constant( $name ) : null;
		};
	}

	/**
	 * Schema.
	 */
	public function schema(): SettingsSchema {
		return $this->schema;
	}

	/**
	 * Registry.
	 */
	public function registry(): GatewayRegistry {
		return $this->registry;
	}

	/**
	 * All settings with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = $this->schema->merge_defaults( is_array( $stored ) ? $stored : array() );
		}

		return $this->cache;
	}

	/**
	 * One top-level value.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public function get( string $key ) {
		$all = $this->all();

		return $all[ $key ] ?? null;
	}

	/**
	 * Store settings. Values defined by constants are removed first.
	 *
	 * @param array<string,mixed> $settings Clean settings.
	 */
	public function save( array $settings ): void {
		foreach ( $this->registry->ids() as $id ) {
			foreach ( $this->registry->fields( $id ) as $field ) {
				if ( null !== $this->constant_value( $id, $field->key ) ) {
					$settings['gateways'][ $id ][ $field->key ] = '';
				}
			}
		}
		update_option( self::OPTION, $settings, false );
		$this->cache = null;
	}

	/**
	 * Active gateway id.
	 */
	public function gateway_id(): string {
		$forced = ( $this->constants )( 'WOO_BD_SMS_GATEWAY' );
		if ( null !== $forced && $this->registry->has( $forced ) ) {
			return $forced;
		}
		$id = (string) $this->get( 'gateway' );

		return $this->registry->has( $id ) ? $id : 'bulksmsbd';
	}

	/**
	 * Backup gateway id, or '' when failover is off.
	 *
	 * The backup must be a registered gateway other than the active one.
	 */
	public function failover_gateway_id(): string {
		$forced  = ( $this->constants )( 'WOO_BD_SMS_FAILOVER_GATEWAY' );
		$section = (array) $this->get( 'failover' );
		$id      = null !== $forced ? $forced : (string) ( $section['gateway'] ?? '' );
		if ( '' === $id || ! $this->registry->has( $id ) || $id === $this->gateway_id() ) {
			return '';
		}

		return $id;
	}

	/**
	 * Gateway configuration with constants applied.
	 *
	 * @param string $id Gateway id.
	 * @return array<string,string>
	 */
	public function gateway_config( string $id ): array {
		$gateways = (array) $this->get( 'gateways' );
		$config   = isset( $gateways[ $id ] ) && is_array( $gateways[ $id ] ) ? array_map( 'strval', $gateways[ $id ] ) : array();
		if ( ! $this->registry->has( $id ) ) {
			return $config;
		}
		foreach ( $this->registry->fields( $id ) as $field ) {
			$constant = $this->constant_value( $id, $field->key );
			if ( null !== $constant ) {
				$config[ $field->key ] = $constant;
			}
		}

		return $config;
	}

	/**
	 * Name of the constant that can hold a gateway field.
	 *
	 * @param string $id Gateway id.
	 * @param string $field Field key.
	 */
	public static function constant_name( string $id, string $field ): string {
		return 'WOO_BD_SMS_' . strtoupper( (string) preg_replace( '/[^a-z0-9]+/i', '_', $id . '_' . $field ) );
	}

	/**
	 * Value of the constant for a gateway field, or null when not defined.
	 *
	 * @param string $id Gateway id.
	 * @param string $field Field key.
	 */
	public function constant_value( string $id, string $field ): ?string {
		return ( $this->constants )( self::constant_name( $id, $field ) );
	}

	/**
	 * Secret used to hash OTP codes; derived from the site salts.
	 */
	public function otp_secret(): string {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '';

		return hash_hmac( 'sha256', 'woo-bd-sms-notify|otp', $salt . ( $this->constants )( 'WOO_BD_SMS_OTP_SECRET' ) );
	}

	/**
	 * Forget cached values.
	 */
	public function flush(): void {
		$this->cache = null;
	}
}
