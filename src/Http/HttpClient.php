<?php
/**
 * HTTP transport.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Http;

/**
 * Sends requests. The plugin uses the WordPress HTTP API; tests use a fake.
 */
interface HttpClient {

	/**
	 * Send a request.
	 *
	 * @param Request $request Request.
	 * @throws HttpException When the request could not be completed (DNS, TLS, timeout).
	 */
	public function send( Request $request ): Response;
}
