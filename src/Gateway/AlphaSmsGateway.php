<?php
/**
 * Alpha SMS adapter.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Gateway;

use Saizul\BdSms\Http\HttpException;
use Saizul\BdSms\Http\Request;
use Saizul\BdSms\Http\Response;
use Saizul\BdSms\Support\JsonPath;

/**
 * Alpha SMS (Alpha Net, sms.net.bd).
 *
 * As documented at https://sms.net.bd/api: POST (or GET)
 * https://api.sms.net.bd/sendsms with api_key, msg, to (880... or 01...)
 * and an optional sender_id. The response is JSON with "error" (0 means
 * success), "msg" and data.request_id. Balance: GET
 * https://api.sms.net.bd/user/balance/?api_key=...
 */
final class AlphaSmsGateway extends AbstractGateway implements BalanceAware {

	public const ID          = 'alpha_sms';
	public const SEND_URL    = 'https://api.sms.net.bd/sendsms';
	public const BALANCE_URL = 'https://api.sms.net.bd/user/balance/';

	/**
	 * Documented error codes.
	 *
	 * @var array<int,string>
	 */
	public const CODES = array(
		400 => 'The request was rejected, due to a missing or invalid parameter.',
		403 => 'You don\'t have permissions to perform the request.',
		404 => 'The requested resource not found.',
		405 => 'Authorization required.',
		409 => 'Unknown error occurred on Server end.',
		410 => 'Account expired',
		411 => 'Reseller Account expired or suspended',
		412 => 'Invalid Schedule',
		413 => 'Invalid Sender ID',
		414 => 'Message is empty',
		415 => 'Message is too long',
		416 => 'No valid number found',
		417 => 'Insufficient balance',
		420 => 'Content Blocked',
	);

	/**
	 * Codes worth retrying later.
	 *
	 * @var int[]
	 */
	private const RETRYABLE = array( 409 );

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
		return 'Alpha SMS (sms.net.bd)';
	}

	/**
	 * Configuration fields.
	 *
	 * @return FieldDefinition[]
	 */
	public static function fields(): array {
		return array(
			new FieldDefinition( 'api_key', 'API key', FieldDefinition::SECRET, true ),
			new FieldDefinition( 'sender_id', 'Sender ID', FieldDefinition::TEXT, false, '', array(), 'Optional. Only an approved sender ID can be used.' ),
			new FieldDefinition( 'endpoint', 'API URL', FieldDefinition::URL, true, self::SEND_URL ),
		);
	}

	/**
	 * Build the request.
	 *
	 * @param Message $message Message.
	 */
	protected function build_request( Message $message ): Request {
		$fields = array(
			'api_key' => $this->config( 'api_key' ),
			'msg'     => $message->text(),
			'to'      => $message->to(),
		);
		if ( '' !== $this->config( 'sender_id' ) ) {
			$fields['sender_id'] = $this->config( 'sender_id' );
		}

		return Request::form( $this->config( 'endpoint' ), $fields );
	}

	/**
	 * Interpret the response.
	 *
	 * @param Response $response Response.
	 */
	protected function interpret( Response $response ): SendResult {
		$json = JsonPath::decode( $response->body() );
		if ( null === $json || ! is_numeric( JsonPath::get( $json, 'error' ) ) ) {
			return $this->unexpected( $response );
		}

		$code = (int) JsonPath::get( $json, 'error' );
		if ( 0 === $code ) {
			$id = JsonPath::get( $json, 'data.request_id' );

			return SendResult::sent( is_scalar( $id ) ? (string) $id : '', $response->status(), $response->body() );
		}

		$message = trim( JsonPath::stringify( JsonPath::get( $json, 'msg' ) ?? '' ) );

		return SendResult::failed(
			(string) $code,
			'' !== $message ? $message : ( self::CODES[ $code ] ?? sprintf( 'Alpha SMS error %d.', $code ) ),
			in_array( $code, self::RETRYABLE, true ) || $response->is_temporary_failure(),
			$response->status(),
			$response->body()
		);
	}

	/**
	 * Account balance.
	 */
	public function balance(): ?string {
		if ( '' === $this->config( 'api_key' ) ) {
			return null;
		}
		try {
			$response = $this->http->send( Request::get( self::BALANCE_URL, array( 'api_key' => $this->config( 'api_key' ) ) ) );
		} catch ( HttpException $e ) {
			return null;
		}
		$json = JsonPath::decode( $response->body() );
		if ( null === $json || 0 !== (int) JsonPath::get( $json, 'error' ) ) {
			return null;
		}
		$balance = JsonPath::get( $json, 'data.balance' );

		return is_scalar( $balance ) ? (string) $balance : null;
	}
}
