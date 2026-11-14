<?php
/**
 * Scripted HTTP client.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Http;

/**
 * Returns queued responses and records requests. Used by the test suite and
 * by `wp bd-sms test --dry-run`.
 */
final class FakeHttpClient implements HttpClient {

	/**
	 * Queued responses or exceptions.
	 *
	 * @var array<int,Response|HttpException>
	 */
	private $queue = array();

	/**
	 * Requests received.
	 *
	 * @var Request[]
	 */
	public $requests = array();

	/**
	 * Response used when the queue is empty.
	 *
	 * @var Response|null
	 */
	private $default;

	/**
	 * Constructor.
	 *
	 * @param Response|null $fallback Response used when nothing is queued.
	 */
	public function __construct( ?Response $fallback = null ) {
		$this->default = $fallback;
	}

	/**
	 * Queue a response.
	 *
	 * @param int    $status Status code.
	 * @param string $body Body.
	 */
	public function queue( int $status, string $body ): self {
		$this->queue[] = new Response( $status, $body );
		return $this;
	}

	/**
	 * Queue a transport error.
	 *
	 * @param string $message Error message.
	 */
	public function queue_error( string $message ): self {
		$this->queue[] = new HttpException( $message );
		return $this;
	}

	/**
	 * Send a request.
	 *
	 * @param Request $request Request.
	 * @throws HttpException When a transport error was queued.
	 */
	public function send( Request $request ): Response {
		$this->requests[] = $request;
		$next             = array_shift( $this->queue );
		if ( $next instanceof HttpException ) {
			throw $next;
		}
		if ( $next instanceof Response ) {
			return $next;
		}

		return $this->default ?? new Response( 200, '' );
	}

	/**
	 * Last request, if any.
	 */
	public function last(): ?Request {
		$last = end( $this->requests );

		return false === $last ? null : $last;
	}
}
