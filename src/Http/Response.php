<?php
/**
 * HTTP response.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Http;

/**
 * Status code, headers and body of a gateway response.
 */
final class Response {

	/**
	 * Status code.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Body.
	 *
	 * @var string
	 */
	private $body;

	/**
	 * Lower-case header names => values.
	 *
	 * @var array<string,string>
	 */
	private $headers;

	/**
	 * Constructor.
	 *
	 * @param int                  $status Status code.
	 * @param string               $body Body.
	 * @param array<string,string> $headers Headers.
	 */
	public function __construct( int $status, string $body, array $headers = array() ) {
		$this->status  = $status;
		$this->body    = $body;
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
	}

	/**
	 * Status code.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Body.
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * A header value, or null.
	 *
	 * @param string $name Header name.
	 */
	public function header( string $name ): ?string {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}

	/**
	 * Whether the status code is 2xx.
	 */
	public function is_success(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Whether the status code means the server or a proxy had a temporary problem.
	 */
	public function is_temporary_failure(): bool {
		return 429 === $this->status || 408 === $this->status || $this->status >= 500;
	}
}
