<?php
/**
 * Shared gateway behaviour.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Gateway;

use Saizul\BdSms\Http\HttpClient;
use Saizul\BdSms\Http\HttpException;
use Saizul\BdSms\Http\Request;
use Saizul\BdSms\Http\Response;

/**
 * Configuration handling, validation and transport errors for adapters.
 */
abstract class AbstractGateway implements Gateway {

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	protected $http;

	/**
	 * Configuration values.
	 *
	 * @var array<string,string>
	 */
	protected $config;

	/**
	 * Constructor.
	 *
	 * @param HttpClient          $http HTTP client.
	 * @param array<string,mixed> $config Configuration.
	 */
	public function __construct( HttpClient $http, array $config ) {
		$this->http   = $http;
		$this->config = array();
		foreach ( static::fields() as $field ) {
			$value                       = isset( $config[ $field->key ] ) ? trim( (string) $config[ $field->key ] ) : '';
			$this->config[ $field->key ] = '' === $value ? $field->default : $value;
		}
	}

	/**
	 * Configuration fields of the gateway.
	 *
	 * @return FieldDefinition[]
	 */
	abstract public static function fields(): array;

	/**
	 * Configuration problems.
	 *
	 * @return string[]
	 */
	public function validate(): array {
		$errors = array();
		foreach ( static::fields() as $field ) {
			$value = $this->config[ $field->key ];
			if ( $field->required && '' === $value ) {
				$errors[] = sprintf( '%s is required.', $field->label );
				continue;
			}
			if ( FieldDefinition::URL === $field->type && '' !== $value && ! preg_match( '#^https?://[^\s/$.?\#].[^\s]*$#i', $value ) ) {
				$errors[] = sprintf( '%s must be an http(s) URL.', $field->label );
			}
		}

		return $errors;
	}

	/**
	 * Configured secret values.
	 *
	 * @return string[]
	 */
	public function secrets(): array {
		$secrets = array();
		foreach ( static::fields() as $field ) {
			if ( $field->is_secret() && '' !== $this->config[ $field->key ] ) {
				$secrets[] = $this->config[ $field->key ];
			}
		}

		return $secrets;
	}

	/**
	 * A configuration value.
	 *
	 * @param string $key Key.
	 */
	public function config( string $key ): string {
		return $this->config[ $key ] ?? '';
	}

	/**
	 * Send one message: validate, call the provider, interpret the response.
	 *
	 * @param Message $message Message.
	 */
	public function send( Message $message ): SendResult {
		$errors = $this->validate();
		if ( array() !== $errors ) {
			return SendResult::failed( 'config', implode( ' ', $errors ), false );
		}

		try {
			$response = $this->http->send( $this->build_request( $message ) );
		} catch ( HttpException $e ) {
			return SendResult::failed( 'http', $e->getMessage(), true );
		}

		return $this->interpret( $response )->redacted( $this->secrets() );
	}

	/**
	 * Build the provider request.
	 *
	 * @param Message $message Message.
	 */
	abstract protected function build_request( Message $message ): Request;

	/**
	 * Turn the provider response into a result.
	 *
	 * @param Response $response Response.
	 */
	abstract protected function interpret( Response $response ): SendResult;

	/**
	 * Generic result for a response that could not be parsed.
	 *
	 * @param Response $response Response.
	 */
	protected function unexpected( Response $response ): SendResult {
		return SendResult::failed(
			'http_' . $response->status(),
			sprintf( 'Unexpected response from %1$s (HTTP %2$d).', $this->label(), $response->status() ),
			$response->is_temporary_failure(),
			$response->status(),
			$response->body()
		);
	}
}
