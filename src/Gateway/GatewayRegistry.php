<?php
/**
 * Known gateways.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Gateway;

use InvalidArgumentException;
use Saizul\BdSms\Http\HttpClient;

/**
 * Maps gateway ids to adapter classes.
 */
final class GatewayRegistry {

	/**
	 * Id => class name.
	 *
	 * @var array<string,class-string<AbstractGateway>>
	 */
	private $classes;

	/**
	 * Constructor.
	 *
	 * @param array<string,class-string<AbstractGateway>>|null $classes Id => class; null for the built-in set.
	 */
	public function __construct( ?array $classes = null ) {
		$this->classes = $classes ?? self::defaults();
	}

	/**
	 * Built-in adapters.
	 *
	 * @return array<string,class-string<AbstractGateway>>
	 */
	public static function defaults(): array {
		return array(
			BulkSmsBdGateway::ID   => BulkSmsBdGateway::class,
			SslWirelessGateway::ID => SslWirelessGateway::class,
			AlphaSmsGateway::ID    => AlphaSmsGateway::class,
			GenericHttpGateway::ID => GenericHttpGateway::class,
		);
	}

	/**
	 * Registered ids.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_keys( $this->classes );
	}

	/**
	 * Whether an id is registered.
	 *
	 * @param string $id Gateway id.
	 */
	public function has( string $id ): bool {
		return isset( $this->classes[ $id ] );
	}

	/**
	 * Fields of a gateway.
	 *
	 * @param string $id Gateway id.
	 * @return FieldDefinition[]
	 * @throws InvalidArgumentException For an unknown id.
	 */
	public function fields( string $id ): array {
		return $this->class_of( $id )::fields();
	}

	/**
	 * Create an adapter.
	 *
	 * @param string              $id Gateway id.
	 * @param HttpClient          $http HTTP client.
	 * @param array<string,mixed> $config Configuration.
	 * @throws InvalidArgumentException For an unknown id.
	 */
	public function create( string $id, HttpClient $http, array $config ): Gateway {
		$class = $this->class_of( $id );

		return new $class( $http, $config );
	}

	/**
	 * Class registered for an id.
	 *
	 * @param string $id Gateway id.
	 * @return class-string<AbstractGateway>
	 * @throws InvalidArgumentException For an unknown id.
	 */
	private function class_of( string $id ): string {
		if ( ! isset( $this->classes[ $id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown SMS gateway "%s".', $id ) );
		}

		return $this->classes[ $id ];
	}
}
