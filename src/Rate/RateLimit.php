<?php
/**
 * Rate limit rule.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Rate;

/**
 * "At most $limit events per $window seconds" for one bucket.
 */
final class RateLimit {

	/**
	 * Bucket name, such as "phone:01711234567" or "global".
	 *
	 * @var string
	 */
	public $bucket;

	/**
	 * Allowed events per window; 0 disables the rule.
	 *
	 * @var int
	 */
	public $limit;

	/**
	 * Window length in seconds.
	 *
	 * @var int
	 */
	public $window;

	/**
	 * Constructor.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $limit Events per window, 0 for unlimited.
	 * @param int    $window Window in seconds.
	 */
	public function __construct( string $bucket, int $limit, int $window ) {
		$this->bucket = $bucket;
		$this->limit  = max( 0, $limit );
		$this->window = max( 1, $window );
	}
}
