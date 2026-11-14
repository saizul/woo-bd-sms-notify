<?php
/**
 * Rate limiter answer.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Rate;

/**
 * Whether an event may happen now, and if not, when.
 */
final class RateDecision {

	/**
	 * Allowed.
	 *
	 * @var bool
	 */
	private $allowed;

	/**
	 * Seconds until the blocking window resets (0 when allowed).
	 *
	 * @var int
	 */
	private $retry_after;

	/**
	 * Bucket that blocked the event.
	 *
	 * @var string
	 */
	private $bucket;

	/**
	 * Constructor.
	 *
	 * @param bool   $allowed Allowed.
	 * @param int    $retry_after Seconds to wait.
	 * @param string $bucket Blocking bucket.
	 */
	public function __construct( bool $allowed, int $retry_after = 0, string $bucket = '' ) {
		$this->allowed     = $allowed;
		$this->retry_after = $retry_after;
		$this->bucket      = $bucket;
	}

	/**
	 * Allowed.
	 */
	public function allowed(): bool {
		return $this->allowed;
	}

	/**
	 * Seconds until the blocking window resets.
	 */
	public function retry_after(): int {
		return $this->retry_after;
	}

	/**
	 * Bucket that blocked the event.
	 */
	public function bucket(): string {
		return $this->bucket;
	}
}
