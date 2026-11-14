<?php
/**
 * SSL Wireless ISMS Plus adapter.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Gateway;

use Saizul\BdSms\Http\Request;
use Saizul\BdSms\Http\Response;
use Saizul\BdSms\Support\JsonPath;

/**
 * SSL Wireless ISMS Plus API v3, single SMS.
 *
 * As documented at https://ismsplus.sslwireless.com/api-documentation:
 * POST https://smsplus.sslwireless.com/api/v3/send-sms with a JSON body
 * {api_token, sid, msisdn, sms, csms_id}. A successful response has
 * status "SUCCESS", status_code 200 and smsinfo[0].sms_status "SUCCESS";
 * the reference_id identifies the message. The server IP must be
 * whitelisted in the ISMS Plus portal (error 4003 otherwise).
 */
final class SslWirelessGateway extends AbstractGateway {

	public const ID       = 'ssl_wireless';
	public const SEND_URL = 'https://smsplus.sslwireless.com/api/v3/send-sms';

	/**
	 * Documented status codes.
	 *
	 * @var array<int,string>
	 */
	public const CODES = array(
		4001 => 'Unauthorized',
		4002 => 'SID Not Permitted',
		4003 => 'IP Blacklisted (not whitelisted)',
		4004 => 'Invalid Request Format',
		4005 => 'End Point Not Found',
		4020 => 'Invalid CSMS ID',
		4022 => 'Required Parameter Missing',
		4023 => 'Duplicate CSMS ID',
		4024 => 'Duplicate MSISDN',
		4025 => 'Invalid MSISDN',
		4026 => 'Blocked MSISDN',
		4027 => 'Message Length Exceeded',
		4028 => 'Invalid Message Data',
		4029 => 'Too Many Requests',
		4030 => 'Limit Exceed',
		4031 => 'TPS Exceeded',
		4032 => 'Invalid SMS',
		5000 => 'Internal Error',
	);

	/**
	 * Codes worth retrying later.
	 *
	 * @var int[]
	 */
	private const RETRYABLE = array( 4029, 4031, 5000 );

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
		return 'SSL Wireless (ISMS Plus)';
	}

	/**
	 * Configuration fields.
	 *
	 * @return FieldDefinition[]
	 */
	public static function fields(): array {
		return array(
			new FieldDefinition( 'api_token', 'API token', FieldDefinition::SECRET, true ),
			new FieldDefinition( 'sid', 'SID (sender ID)', FieldDefinition::TEXT, true ),
			new FieldDefinition( 'endpoint', 'API URL', FieldDefinition::URL, true, self::SEND_URL ),
		);
	}

	/**
	 * Build the request.
	 *
	 * @param Message $message Message.
	 */
	protected function build_request( Message $message ): Request {
		return Request::json(
			$this->config( 'endpoint' ),
			array(
				'api_token' => $this->config( 'api_token' ),
				'sid'       => $this->config( 'sid' ),
				'msisdn'    => $message->to(),
				'sms'       => $message->text(),
				'csms_id'   => $message->reference(),
			)
		);
	}

	/**
	 * Interpret the response.
	 *
	 * @param Response $response Response.
	 */
	protected function interpret( Response $response ): SendResult {
		$json = JsonPath::decode( $response->body() );
		if ( null === $json || null === JsonPath::get( $json, 'status' ) ) {
			return $this->unexpected( $response );
		}

		$status     = strtoupper( JsonPath::stringify( JsonPath::get( $json, 'status' ) ) );
		$code       = (int) JsonPath::stringify( JsonPath::get( $json, 'status_code' ) ?? 0 );
		$sms_status = strtoupper( JsonPath::stringify( JsonPath::get( $json, 'smsinfo.0.sms_status' ) ?? 'SUCCESS' ) );

		if ( 'SUCCESS' === $status && 200 === $code && 'SUCCESS' === $sms_status ) {
			$id = JsonPath::get( $json, 'smsinfo.0.reference_id' );

			return SendResult::sent( is_scalar( $id ) ? (string) $id : '', $response->status(), $response->body() );
		}

		$message = trim( JsonPath::stringify( JsonPath::get( $json, 'error_message' ) ?? '' ) );
		if ( '' === $message ) {
			$message = trim( JsonPath::stringify( JsonPath::get( $json, 'smsinfo.0.status_message' ) ?? '' ) );
		}
		if ( '' === $message ) {
			$message = self::CODES[ $code ] ?? sprintf( 'SSL Wireless error %d.', $code );
		}

		return SendResult::failed(
			(string) $code,
			$message,
			in_array( $code, self::RETRYABLE, true ) || $response->is_temporary_failure(),
			$response->status(),
			$response->body()
		);
	}
}
