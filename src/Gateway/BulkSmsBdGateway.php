<?php
/**
 * BulkSMSBD adapter.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Gateway;

use Saizul\BdSms\Http\Request;
use Saizul\BdSms\Http\Response;
use Saizul\BdSms\Support\JsonPath;

/**
 * BulkSMSBD (bulksmsbd.net) "Single SMS API".
 *
 * Request, as published at https://bulksmsbd.com/bulksms-api-bangladesh.php:
 * POST https://bulksmsbd.net/api/smsapi with api_key, senderid, number
 * (88017XXXXXXXX) and message. The GET example adds type=text, which is sent
 * here as well.
 *
 * The documentation lists result codes (202 = "SMS Submitted Successfully",
 * 1002-1032 = errors) but not the response body. The adapter reads the code
 * from a JSON "response_code" field, and falls back to a bare number in the
 * body.
 */
final class BulkSmsBdGateway extends AbstractGateway implements BalanceAware {

	public const ID          = 'bulksmsbd';
	public const SEND_URL    = 'https://bulksmsbd.net/api/smsapi';
	public const BALANCE_URL = 'https://bulksmsbd.net/api/getBalanceApi';

	/**
	 * Documented result codes.
	 *
	 * @var array<int,string>
	 */
	public const CODES = array(
		202  => 'SMS Submitted Successfully',
		1002 => 'sender id not correct/sender id is disabled',
		1003 => 'Please Required all fields/Contact Your System Administrator',
		1005 => 'Internal Error',
		1006 => 'Balance Validity Not Available',
		1007 => 'Balance Insufficient',
		1011 => 'User Id not found',
		1012 => 'Masking SMS must be sent in Bengali',
		1013 => 'Sender Id has not found Gateway by api key',
		1014 => 'Sender Type Name not found using this sender by api key',
		1015 => 'Sender Id has not found Any Valid Gateway by api key',
		1016 => 'Sender Type Name Active Price Info not found by this sender id',
		1017 => 'Sender Type Name Price Info not found by this sender id',
		1018 => 'The Owner of this (username) Account is disabled',
		1019 => 'The (sender type name) Price of this (username) Account is disabled',
		1020 => 'The parent of this account is not found.',
		1021 => 'The parent active (sender type name) price of this account is not found.',
		1031 => 'Your Account Not Verified, Please Contact Administrator.',
		1032 => 'IP Not whitelisted',
	);

	/**
	 * Codes worth retrying later.
	 *
	 * @var int[]
	 */
	private const RETRYABLE = array( 1005 );

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
		return 'BulkSMSBD';
	}

	/**
	 * Configuration fields.
	 *
	 * @return FieldDefinition[]
	 */
	public static function fields(): array {
		return array(
			new FieldDefinition( 'api_key', 'API key', FieldDefinition::SECRET, true ),
			new FieldDefinition( 'sender_id', 'Sender ID', FieldDefinition::TEXT, true, '', array(), 'An approved sender ID (masking name or number) from your BulkSMSBD panel.' ),
			new FieldDefinition( 'endpoint', 'API URL', FieldDefinition::URL, true, self::SEND_URL ),
		);
	}

	/**
	 * Build the request.
	 *
	 * @param Message $message Message.
	 */
	protected function build_request( Message $message ): Request {
		return Request::form(
			$this->config( 'endpoint' ),
			array(
				'api_key'  => $this->config( 'api_key' ),
				'type'     => 'text',
				'number'   => $message->to(),
				'senderid' => $this->config( 'sender_id' ),
				'message'  => $message->text(),
			)
		);
	}

	/**
	 * Interpret the response.
	 *
	 * @param Response $response Response.
	 */
	protected function interpret( Response $response ): SendResult {
		$code = self::extract_code( $response->body() );
		if ( null === $code ) {
			return $this->unexpected( $response );
		}
		if ( 202 === $code ) {
			$json = JsonPath::decode( $response->body() );
			$id   = null === $json ? '' : JsonPath::stringify( JsonPath::get( $json, 'message_id' ) ?? '' );

			return SendResult::sent( $id, $response->status(), $response->body() );
		}

		$json    = JsonPath::decode( $response->body() );
		$message = null === $json ? '' : trim( JsonPath::stringify( JsonPath::get( $json, 'error_message' ) ?? '' ) );
		if ( '' === $message ) {
			$message = self::CODES[ $code ] ?? sprintf( 'BulkSMSBD error %d.', $code );
		}

		return SendResult::failed(
			(string) $code,
			$message,
			in_array( $code, self::RETRYABLE, true ) || $response->is_temporary_failure(),
			$response->status(),
			$response->body()
		);
	}

	/**
	 * Read the result code from a response body.
	 *
	 * @param string $body Body.
	 */
	public static function extract_code( string $body ): ?int {
		$json = JsonPath::decode( $body );
		if ( null !== $json ) {
			foreach ( array( 'response_code', 'code', 'status_code' ) as $key ) {
				$value = JsonPath::get( $json, $key );
				if ( is_numeric( $value ) ) {
					return (int) $value;
				}
			}
			return null;
		}
		if ( preg_match( '/^\s*(\d{3,4})\b/', $body, $m ) ) {
			return (int) $m[1];
		}

		return null;
	}

	/**
	 * Account balance.
	 */
	public function balance(): ?string {
		if ( '' === $this->config( 'api_key' ) ) {
			return null;
		}
		try {
			$response = $this->http->send( Request::form( self::BALANCE_URL, array( 'api_key' => $this->config( 'api_key' ) ) ) );
		} catch ( \Saizul\BdSms\Http\HttpException $e ) {
			return null;
		}
		$json = JsonPath::decode( $response->body() );
		if ( null === $json ) {
			return null;
		}
		$balance = JsonPath::get( $json, 'balance' );

		return is_scalar( $balance ) ? (string) $balance : null;
	}
}
