<?php
/**
 * Primary gateway with a backup.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Gateway;

use InvalidArgumentException;
use Saizul\BdSms\Support\Clock;
use Saizul\BdSms\Support\KeyValueStore;

/**
 * Sends through the primary gateway and, when it fails in a way the
 * {@see FailoverPolicy} accepts, through the backup gateway right away.
 *
 * Consecutive primary failures are counted in the key-value store. Once the
 * policy threshold is reached the primary is skipped until the cooldown ends;
 * the next message after that tries the primary again and either closes the
 * breaker (success) or opens it for another cooldown (failure).
 */
final class FailoverGateway implements RoutedGateway {

	private const KEY_PREFIX = 'failover:';

	/**
	 * Primary gateway.
	 *
	 * @var Gateway
	 */
	private $primary;

	/**
	 * Backup gateway.
	 *
	 * @var Gateway
	 */
	private $backup;

	/**
	 * Policy.
	 *
	 * @var FailoverPolicy
	 */
	private $policy;

	/**
	 * Breaker state.
	 *
	 * @var KeyValueStore
	 */
	private $store;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private $clock;

	/**
	 * Gateway used for the last message.
	 *
	 * @var string
	 */
	private $last = '';

	/**
	 * Constructor.
	 *
	 * @param Gateway        $primary Primary gateway.
	 * @param Gateway        $backup Backup gateway.
	 * @param FailoverPolicy $policy Policy.
	 * @param KeyValueStore  $store Store for the breaker state.
	 * @param Clock          $clock Clock.
	 * @throws InvalidArgumentException When both gateways are the same adapter.
	 */
	public function __construct( Gateway $primary, Gateway $backup, FailoverPolicy $policy, KeyValueStore $store, Clock $clock ) {
		if ( $primary->id() === $backup->id() ) {
			throw new InvalidArgumentException( 'The backup gateway must differ from the primary gateway.' );
		}
		$this->primary = $primary;
		$this->backup  = $backup;
		$this->policy  = $policy;
		$this->store   = $store;
		$this->clock   = $clock;
	}

	/**
	 * Id of the primary gateway.
	 */
	public function id(): string {
		return $this->primary->id();
	}

	/**
	 * Both names.
	 */
	public function label(): string {
		return sprintf( '%1$s (backup: %2$s)', $this->primary->label(), $this->backup->label() );
	}

	/**
	 * Problems of the primary gateway; the backup only matters when both fail.
	 *
	 * @return string[]
	 */
	public function validate(): array {
		return $this->primary->validate();
	}

	/**
	 * Problems of the backup gateway.
	 *
	 * @return string[]
	 */
	public function validate_backup(): array {
		return $this->backup->validate();
	}

	/**
	 * Primary gateway.
	 */
	public function primary(): Gateway {
		return $this->primary;
	}

	/**
	 * Backup gateway.
	 */
	public function backup(): Gateway {
		return $this->backup;
	}

	/**
	 * Policy.
	 */
	public function policy(): FailoverPolicy {
		return $this->policy;
	}

	/**
	 * Secrets of both gateways.
	 *
	 * @return string[]
	 */
	public function secrets(): array {
		return array_values( array_unique( array_merge( $this->primary->secrets(), $this->backup->secrets() ) ) );
	}

	/**
	 * Gateway used for the last message.
	 */
	public function last_gateway_id(): string {
		return $this->last;
	}

	/**
	 * Send through the primary, or the backup when needed.
	 *
	 * @param Message $message Message.
	 */
	public function send( Message $message ): SendResult {
		if ( $this->is_open() ) {
			$this->last = $this->backup->id();

			return $this->backup->send( $message );
		}

		$this->last = $this->primary->id();
		$result     = $this->primary->send( $message );
		if ( $result->is_success() ) {
			if ( null !== $this->store->get( $this->key( 'failures' ) ) ) {
				$this->close();
			}

			return $result;
		}
		if ( ! $this->policy->should_fail_over( $result ) ) {
			return $result;
		}

		$this->record_failure();
		$this->last = $this->backup->id();
		$backup     = $this->backup->send( $message );
		if ( $backup->is_success() ) {
			return $backup;
		}

		return SendResult::failed(
			$backup->error_code(),
			sprintf(
				'%1$s: %2$s %3$s; %4$s: %5$s %6$s',
				$this->primary->id(),
				$result->error_code(),
				$result->error_message(),
				$this->backup->id(),
				$backup->error_code(),
				$backup->error_message()
			),
			$result->is_retryable() || $backup->is_retryable(),
			$backup->http_status(),
			$backup->raw()
		);
	}

	/**
	 * Breaker state for diagnostics.
	 *
	 * @return array{primary:string,backup:string,failures:int,open:bool,open_until:int}
	 */
	public function state(): array {
		$until = (int) $this->store->get( $this->key( 'open' ) );

		return array(
			'primary'    => $this->primary->id(),
			'backup'     => $this->backup->id(),
			'failures'   => (int) $this->store->get( $this->key( 'failures' ) ),
			'open'       => $until > $this->clock->now(),
			'open_until' => $until > $this->clock->now() ? $until : 0,
		);
	}

	/**
	 * Close the breaker and forget the failure count.
	 */
	public function reset(): void {
		$this->close();
	}

	/**
	 * Whether the primary is being skipped.
	 */
	private function is_open(): bool {
		if ( 0 === $this->policy->threshold() ) {
			return false;
		}

		return (int) $this->store->get( $this->key( 'open' ) ) > $this->clock->now();
	}

	/**
	 * Count a primary failure and open the breaker at the threshold.
	 */
	private function record_failure(): void {
		if ( 0 === $this->policy->threshold() ) {
			return;
		}
		$cooldown = $this->policy->cooldown();
		$failures = (int) $this->store->get( $this->key( 'failures' ) ) + 1;
		// The count survives the cooldown so that a failed probe reopens the breaker at once.
		$this->store->set( $this->key( 'failures' ), $failures, $cooldown * 4 );
		if ( $failures >= $this->policy->threshold() ) {
			$this->store->set( $this->key( 'open' ), $this->clock->now() + $cooldown, $cooldown );
		}
	}

	/**
	 * Close the breaker.
	 */
	private function close(): void {
		$this->store->delete( $this->key( 'failures' ) );
		$this->store->delete( $this->key( 'open' ) );
	}

	/**
	 * Store key.
	 *
	 * @param string $name Name.
	 */
	private function key( string $name ): string {
		return self::KEY_PREFIX . $this->primary->id() . ':' . $name;
	}
}
