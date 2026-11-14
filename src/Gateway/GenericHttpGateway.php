<?php
/**
 * Configurable HTTP gateway.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Gateway;

use InvalidArgumentException;
use Saizul\BdSms\Http\Request;
use Saizul\BdSms\Http\Response;
use Saizul\BdSms\Support\JsonPath;

/**
 * Any HTTP SMS API described by URL, parameters, headers and a success rule.
 *
 * Parameters and headers are "name=value" / "Name: value" lines. These
 * placeholders are replaced in the URL, parameters and headers:
 * {to} (8801XXXXXXXXX), {to_local} (01XXXXXXXXX), {to_e164} (+8801XXXXXXXXX),
 * {message}, {sender_id}, {api_key}, {api_secret}, {reference}, {unicode}
 * (1 when the text needs UCS-2, otherwise 0) and {type} ("unicode" or "text").
 */
final class GenericHttpGateway extends AbstractGateway {

	public const ID = 'generic';

	/**
	 * Machine id.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Display name.
	 */
	public function label(): string {
		return 'Generic HTTP gateway';
	}

	/**
	 * Configuration fields.
	 *
	 * @return FieldDefinition[]
	 */
	public static function fields(): array {
		return array(
			new FieldDefinition( 'url', 'Endpoint URL', FieldDefinition::URL, true ),
			new FieldDefinition(
				'method',
				'HTTP method',
				FieldDefinition::SELECT,
				true,
				'POST',
				array(
					'POST' => 'POST',
					'GET'  => 'GET',
				)
			),
			new FieldDefinition(
				'body_format',
				'POST body',
				FieldDefinition::SELECT,
				true,
				'form',
				array(
					'form' => 'Form (application/x-www-form-urlencoded)',
					'json' => 'JSON (application/json)',
				)
			),
			new FieldDefinition( 'params', 'Parameters', FieldDefinition::TEXTAREA, true, "api_key={api_key}\nto={to}\nmessage={message}", array(), 'One name=value per line.' ),
			new FieldDefinition( 'headers', 'Extra headers', FieldDefinition::TEXTAREA, false, '', array(), 'One "Name: value" per line, for example Authorization: Bearer {api_key}' ),
			new FieldDefinition( 'api_key', 'API key', FieldDefinition::SECRET ),
			new FieldDefinition( 'api_secret', 'API secret', FieldDefinition::SECRET ),
			new FieldDefinition( 'sender_id', 'Sender ID' ),
			new FieldDefinition(
				'success_type',
				'Success rule',
				FieldDefinition::SELECT,
				true,
				SuccessMatcher::HTTP_2XX,
				array(
					SuccessMatcher::HTTP_2XX     => 'HTTP status 2xx',
					SuccessMatcher::CONTAINS     => 'Body contains',
					SuccessMatcher::NOT_CONTAINS => 'Body does not contain',
					SuccessMatcher::REGEX        => 'Body matches pattern',
					SuccessMatcher::JSON         => 'JSON conditions',
				)
			),
			new FieldDefinition( 'success_value', 'Success value', FieldDefinition::TEXT, false, '', array(), 'For JSON: status=SUCCESS && code=200' ),
			new FieldDefinition( 'message_id_path', 'Message ID path (JSON)', FieldDefinition::TEXT, false, '', array(), 'For example data.request_id' ),
			new FieldDefinition( 'error_path', 'Error message path (JSON)', FieldDefinition::TEXT, false, '', array(), 'For example error_message' ),
		);
	}

	/**
	 * Configuration problems, including an invalid success rule.
	 *
	 * @return string[]
	 */
	public function validate(): array {
		$errors = parent::validate();
		try {
			$this->matcher();
		} catch ( InvalidArgumentException $e ) {
			$errors[] = $e->getMessage();
		}
		if ( ! in_array( strtoupper( $this->config( 'method' ) ), array( 'GET', 'POST' ), true ) ) {
			$errors[] = 'HTTP method must be GET or POST.';
		}
		if ( array() === self::parse_lines( $this->config( 'params' ), '=' ) ) {
			$errors[] = 'At least one parameter is required.';
		}

		return $errors;
	}

	/**
	 * Placeholder values for a message.
	 *
	 * @param Message $message Message.
	 * @return array<string,string>
	 */
	public function placeholders( Message $message ): array {
		$unicode = ( new \Saizul\BdSms\Sms\SegmentCounter() )->encoding_of( $message->text() ) === \Saizul\BdSms\Sms\Encoding::UCS2;

		return array(
			'{to}'         => $message->to(),
			'{to_local}'   => $message->to_local(),
			'{to_e164}'    => $message->to_e164(),
			'{message}'    => $message->text(),
			'{sender_id}'  => $this->config( 'sender_id' ),
			'{api_key}'    => $this->config( 'api_key' ),
			'{api_secret}' => $this->config( 'api_secret' ),
			'{reference}'  => $message->reference(),
			'{unicode}'    => $unicode ? '1' : '0',
			'{type}'       => $unicode ? 'unicode' : 'text',
		);
	}

	/**
	 * Build the request.
	 *
	 * @param Message $message Message.
	 */
	protected function build_request( Message $message ): Request {
		$values = $this->placeholders( $message );
		$url    = strtr( $this->config( 'url' ), array_map( 'rawurlencode', $values ) );
		$params = array();
		foreach ( self::parse_lines( $this->config( 'params' ), '=' ) as $name => $value ) {
			$params[ $name ] = strtr( $value, $values );
		}

		if ( 'GET' === strtoupper( $this->config( 'method' ) ) ) {
			$request = Request::get( $url, $params );
		} elseif ( 'json' === $this->config( 'body_format' ) ) {
			$request = Request::json( $url, $params );
		} else {
			$request = Request::form( $url, $params );
		}

		$headers = $request->headers();
		foreach ( self::parse_lines( $this->config( 'headers' ), ':' ) as $name => $value ) {
			$headers[ $name ] = str_replace( array( "\r", "\n" ), '', strtr( $value, $values ) );
		}

		return new Request( $request->method(), $request->url(), $headers, $request->body(), $request->timeout() );
	}

	/**
	 * Interpret the response.
	 *
	 * @param Response $response Response.
	 */
	protected function interpret( Response $response ): SendResult {
		$json = JsonPath::decode( $response->body() );
		if ( $this->matcher()->matches( $response ) ) {
			$id = '';
			if ( null !== $json && '' !== $this->config( 'message_id_path' ) ) {
				$value = JsonPath::get( $json, $this->config( 'message_id_path' ) );
				$id    = is_scalar( $value ) ? (string) $value : '';
			}

			return SendResult::sent( $id, $response->status(), $response->body() );
		}

		$message = '';
		if ( null !== $json && '' !== $this->config( 'error_path' ) ) {
			$value   = JsonPath::get( $json, $this->config( 'error_path' ) );
			$message = is_scalar( $value ) ? trim( (string) $value ) : '';
		}
		if ( '' === $message ) {
			$message = sprintf( 'The gateway response did not match the success rule (HTTP %d).', $response->status() );
		}

		return SendResult::failed( 'http_' . $response->status(), $message, $response->is_temporary_failure(), $response->status(), $response->body() );
	}

	/**
	 * Success matcher from the configuration.
	 *
	 * @throws InvalidArgumentException When the rule is invalid.
	 */
	private function matcher(): SuccessMatcher {
		return new SuccessMatcher( $this->config( 'success_type' ), $this->config( 'success_value' ) );
	}

	/**
	 * Parse "name=value" or "Name: value" lines.
	 *
	 * @param string $text Lines.
	 * @param string $separator "=" or ":".
	 * @return array<string,string>
	 */
	public static function parse_lines( string $text, string $separator ): array {
		$pairs = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) ?: array() as $line ) { // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- preg_split returns false only on a bad pattern.
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$pos = strpos( $line, $separator );
			if ( false === $pos || 0 === $pos ) {
				continue;
			}
			$name = trim( substr( $line, 0, $pos ) );
			if ( ':' === $separator && ! preg_match( '/^[A-Za-z0-9-]+$/', $name ) ) {
				continue;
			}
			$pairs[ $name ] = trim( substr( $line, $pos + 1 ) );
		}

		return $pairs;
	}
}
