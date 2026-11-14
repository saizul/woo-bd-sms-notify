<?php
/**
 * WordPress HTTP transport.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\WP\Http;

use Saizul\BdSms\Http\HttpClient;
use Saizul\BdSms\Http\HttpException;
use Saizul\BdSms\Http\Request;
use Saizul\BdSms\Http\Response;

/**
 * Sends gateway requests with wp_remote_request().
 */
final class WpHttpClient implements HttpClient {

	/**
	 * Send a request.
	 *
	 * @param Request $request Request.
	 * @throws HttpException When WordPress returns a WP_Error.
	 */
	public function send( Request $request ): Response {
		$args = array(
			'method'      => $request->method(),
			'headers'     => $request->headers(),
			'timeout'     => $request->timeout(),
			'redirection' => 2,
			'sslverify'   => true,
			'user-agent'  => 'woo-bd-sms-notify/' . \Saizul\BdSms\WP\Plugin::VERSION . '; ' . home_url( '/' ),
		);
		if ( 'GET' !== $request->method() ) {
			$args['body'] = $request->body();
		}

		/**
		 * Filters the arguments passed to wp_remote_request() for gateway calls.
		 *
		 * @param array   $args    Request arguments.
		 * @param Request $request The gateway request.
		 */
		$args = apply_filters( 'woo_bd_sms_http_args', $args, $request );

		$result = wp_remote_request( $request->url(), $args );
		if ( is_wp_error( $result ) ) {
			throw new HttpException( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught and logged, never printed.
		}

		$headers = wp_remote_retrieve_headers( $result );
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}
		$flat = array();
		foreach ( (array) $headers as $name => $value ) {
			$flat[ (string) $name ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return new Response( (int) wp_remote_retrieve_response_code( $result ), (string) wp_remote_retrieve_body( $result ), $flat );
	}
}
