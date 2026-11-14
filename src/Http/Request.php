<?php
/**
 * Outgoing HTTP request.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Http;

/**
 * Immutable description of a gateway call.
 */
final class Request {

	/**
	 * HTTP method.
	 *
	 * @var string
	 */
	private $method;

	/**
	 * Full URL.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Headers, name => value.
	 *
	 * @var array<string,string>
	 */
	private $headers;

	/**
	 * Raw body.
	 *
	 * @var string
	 */
	private $body;

	/**
	 * Timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Constructor.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $url URL.
	 * @param array<string,string> $headers Headers.
	 * @param string               $body Body.
	 * @param int                  $timeout Timeout in seconds.
	 */
	public function __construct( string $method, string $url, array $headers = array(), string $body = '', int $timeout = 15 ) {
		$this->method  = strtoupper( $method );
		$this->url     = $url;
		$this->headers = $headers;
		$this->body    = $body;
		$this->timeout = $timeout;
	}

	/**
	 * Form-encoded POST.
	 *
	 * @param string              $url URL.
	 * @param array<string,mixed> $fields Fields.
	 * @param int                 $timeout Timeout.
	 */
	public static function form( string $url, array $fields, int $timeout = 15 ): self {
		return new self(
			'POST',
			$url,
			array(
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept'       => 'application/json',
			),
			http_build_query( $fields, '', '&', PHP_QUERY_RFC3986 ),
			$timeout
		);
	}

	/**
	 * JSON POST.
	 *
	 * @param string              $url URL.
	 * @param array<string,mixed> $data Payload.
	 * @param int                 $timeout Timeout.
	 */
	public static function json( string $url, array $data, int $timeout = 15 ): self {
		return new self(
			'POST',
			$url,
			array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			(string) json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Library code that runs without WordPress.
			$timeout
		);
	}

	/**
	 * GET with a query string.
	 *
	 * @param string              $url URL.
	 * @param array<string,mixed> $query Query parameters.
	 * @param int                 $timeout Timeout.
	 */
	public static function get( string $url, array $query = array(), int $timeout = 15 ): self {
		if ( array() !== $query ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		return new self( 'GET', $url, array( 'Accept' => 'application/json' ), '', $timeout );
	}

	/**
	 * HTTP method.
	 */
	public function method(): string {
		return $this->method;
	}

	/**
	 * URL.
	 */
	public function url(): string {
		return $this->url;
	}

	/**
	 * Headers.
	 *
	 * @return array<string,string>
	 */
	public function headers(): array {
		return $this->headers;
	}

	/**
	 * Body.
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * Timeout in seconds.
	 */
	public function timeout(): int {
		return $this->timeout;
	}

	/**
	 * Decoded form or JSON body (for tests and logging).
	 *
	 * @return array<mixed>
	 */
	public function body_fields(): array {
		$type = '';
		foreach ( $this->headers as $name => $value ) {
			if ( 'content-type' === strtolower( $name ) ) {
				$type = strtolower( $value );
			}
		}
		if ( false !== strpos( $type, 'json' ) ) {
			$data = json_decode( $this->body, true );
			return is_array( $data ) ? $data : array();
		}
		parse_str( $this->body, $fields );

		return $fields;
	}

	/**
	 * Decoded query string.
	 *
	 * @return array<mixed>
	 */
	public function query_fields(): array {
		$query = (string) parse_url( $this->url, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Library code that runs without WordPress.
		parse_str( $query, $fields );

		return $fields;
	}
}
