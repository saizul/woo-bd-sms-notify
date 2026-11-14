<?php
/**
 * Fixed-window rate limiter.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Rate;

use Saizul\BdSms\Support\Clock;
use Saizul\BdSms\Support\KeyValueStore;

/**
 * Counts events per bucket in fixed windows aligned to the epoch.
 *
 * Several rules are checked together: the event is only counted when every
 * rule allows it, so a blocked SMS does not use up quota.
 */
final class RateLimiter {

	/**
	 * Counter storage.
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
	 * Key prefix.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Constructor.
	 *
	 * @param KeyValueStore $store Storage.
	 * @param Clock         $clock Clock.
	 * @param string        $prefix Key prefix.
	 */
	public function __construct( KeyValueStore $store, Clock $clock, string $prefix = 'bdsms_rl_' ) {
		$this->store  = $store;
		$this->clock  = $clock;
		$this->prefix = $prefix;
	}

	/**
	 * Check all rules and count the event if allowed.
	 *
	 * @param RateLimit[] $limits Rules.
	 */
	public function attempt( array $limits ): RateDecision {
		$decision = $this->check( $limits );
		if ( $decision->allowed() ) {
			foreach ( $limits as $limit ) {
				$this->hit( $limit );
			}
		}

		return $decision;
	}

	/**
	 * Check rules without counting.
	 *
	 * @param RateLimit[] $limits Rules.
	 */
	public function check( array $limits ): RateDecision {
		$wait   = 0;
		$bucket = '';
		foreach ( $limits as $limit ) {
			if ( 0 === $limit->limit ) {
				continue;
			}
			if ( $this->used( $limit ) >= $limit->limit ) {
				$reset = $this->reset_in( $limit );
				if ( $reset > $wait ) {
					$wait   = $reset;
					$bucket = $limit->bucket;
				}
			}
		}

		return '' === $bucket ? new RateDecision( true ) : new RateDecision( false, $wait, $bucket );
	}

	/**
	 * Events counted in the current window.
	 *
	 * @param RateLimit $limit Rule.
	 */
	public function used( RateLimit $limit ): int {
		return (int) $this->store->get( $this->key( $limit ) );
	}

	/**
	 * Seconds until the current window of a rule ends.
	 *
	 * @param RateLimit $limit Rule.
	 */
	public function reset_in( RateLimit $limit ): int {
		$now = $this->clock->now();

		return $limit->window - ( $now % $limit->window );
	}

	/**
	 * Count one event.
	 *
	 * @param RateLimit $limit Rule.
	 */
	private function hit( RateLimit $limit ): void {
		if ( 0 === $limit->limit ) {
			return;
		}
		$this->store->set( $this->key( $limit ), $this->used( $limit ) + 1, $this->reset_in( $limit ) );
	}

	/**
	 * Storage key for the current window of a rule.
	 *
	 * @param RateLimit $limit Rule.
	 */
	private function key( RateLimit $limit ): string {
		$window = intdiv( $this->clock->now(), $limit->window );

		return $this->prefix . substr( md5( $limit->bucket . '|' . $limit->window ), 0, 16 ) . '_' . $window;
	}
}
