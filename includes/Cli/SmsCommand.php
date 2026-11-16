<?php
/**
 * WP-CLI commands.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\WP\Cli;

use Saizul\BdSms\Gateway\FailoverGateway;
use Saizul\BdSms\Http\FakeHttpClient;
use Saizul\BdSms\Http\Response;
use Saizul\BdSms\Log\InMemoryLogRepository;
use Saizul\BdSms\Log\LogEntry;
use Saizul\BdSms\Log\LogQuery;
use Saizul\BdSms\Phone\PhoneNormalizer;
use Saizul\BdSms\Retry\InMemoryRetryScheduler;
use Saizul\BdSms\Sms\OutgoingSms;
use Saizul\BdSms\Sms\SegmentCounter;
use Saizul\BdSms\Sms\TextNormalizer;
use Saizul\BdSms\Support\ArrayStore;
use Saizul\BdSms\Support\Masker;
use Saizul\BdSms\Support\SystemClock;
use Saizul\BdSms\WP\Order\StatusNotifier;
use Saizul\BdSms\WP\Services;
use WP_CLI;

/**
 * Send and inspect SMS from the command line.
 */
final class SmsCommand {

	/**
	 * Services.
	 *
	 * @var Services
	 */
	private $services;

	/**
	 * Notifier.
	 *
	 * @var StatusNotifier
	 */
	private $notifier;

	/**
	 * Constructor.
	 *
	 * @param Services       $services Services.
	 * @param StatusNotifier $notifier Notifier.
	 */
	public function __construct( Services $services, StatusNotifier $notifier ) {
		$this->services = $services;
		$this->notifier = $notifier;
	}

	/**
	 * Send a test SMS through the active gateway.
	 *
	 * ## OPTIONS
	 *
	 * <phone>
	 * : Recipient, for example 01711234567 or +8801711234567.
	 *
	 * [--message=<text>]
	 * : Message text.
	 * ---
	 * default: Test SMS from SMS Notify for WooCommerce.
	 * ---
	 *
	 * [--gateway=<id>]
	 * : Use another configured gateway.
	 *
	 * [--dry-run]
	 * : Build the request without sending it and print it with secrets removed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bd-sms test 01711234567
	 *     wp bd-sms test 01711234567 --message="আপনার অর্ডার পাঠানো হয়েছে" --dry-run
	 *
	 * @param string[]             $args Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function test( $args, $assoc_args ): void {
		$phone   = (string) ( $args[0] ?? '' );
		$message = (string) ( $assoc_args['message'] ?? 'Test SMS from SMS Notify for WooCommerce.' );
		$id      = isset( $assoc_args['gateway'] ) ? (string) $assoc_args['gateway'] : null;

		if ( null !== $id && ! $this->services->settings->registry()->has( $id ) ) {
			WP_CLI::error( sprintf( 'Unknown gateway "%s". Available: %s', $id, implode( ', ', $this->services->settings->registry()->ids() ) ) );
		}

		$info = ( new SegmentCounter() )->count( ( new TextNormalizer() )->normalize( $message ) );
		WP_CLI::log( sprintf( 'Message: %1$d SMS, %2$s, %3$d characters.', $info->segments(), $info->encoding(), $info->characters() ) );

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$http     = new FakeHttpClient( new Response( 200, '{}' ) );
			$services = new Services( $this->services->settings, $http, new InMemoryLogRepository(), new ArrayStore( new SystemClock() ), new InMemoryRetryScheduler() );
			$gateway  = $services->gateway( $id );
			$entry    = $services->sender( $gateway )->send( new OutgoingSms( $phone, $message, LogEntry::TYPE_TEST, 'test' ) );
			$request  = $http->last();
			if ( null === $request ) {
				WP_CLI::error( sprintf( 'Nothing would be sent: %s', $entry->error_message ) );
				return;
			}
			$secrets = $gateway->secrets();
			WP_CLI::log( $request->method() . ' ' . Masker::redact( $request->url(), $secrets ) );
			foreach ( $request->headers() as $name => $value ) {
				WP_CLI::log( $name . ': ' . Masker::redact( $value, $secrets ) );
			}
			WP_CLI::log( '' );
			WP_CLI::log( Masker::redact( $request->body(), $secrets ) );
			WP_CLI::success( 'Dry run: nothing was sent.' );
			return;
		}

		$entry = $this->services->sender( $this->services->gateway( $id ) )->send( new OutgoingSms( $phone, $message, LogEntry::TYPE_TEST, 'test' ) );
		$this->report( $entry );
	}

	/**
	 * Send the notifications for an order's current status.
	 *
	 * ## OPTIONS
	 *
	 * <order-id>
	 * : Order ID.
	 *
	 * [--message=<text>]
	 * : Send this text (placeholders allowed) to the customer instead of the status templates.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bd-sms send 1024
	 *     wp bd-sms send 1024 --message="Hi {first_name}, your parcel {tracking} is on the way."
	 *
	 * @param string[]             $args Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function send( $args, $assoc_args ): void {
		$order_id = (int) ( $args[0] ?? 0 );
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			WP_CLI::error( sprintf( 'Order %d not found.', $order_id ) );
		}

		if ( isset( $assoc_args['message'] ) ) {
			$data  = ( new \Saizul\BdSms\WP\Order\OrderMapper( $this->services->settings ) )->map( $order );
			$text  = ( new \Saizul\BdSms\Template\TemplateRenderer() )->render( (string) $assoc_args['message'], $data->to_vars() );
			$entry = $this->services->sender()->send( new OutgoingSms( $data->phone(), $text, LogEntry::TYPE_MANUAL, 'manual', $order_id ) );
			$this->report( $entry );
			return;
		}

		$entries = $this->notifier->run( $order_id, '', true );
		if ( array() === $entries ) {
			WP_CLI::warning( sprintf( 'No template is enabled for the status "%s".', $order->get_status() ) );
			return;
		}
		foreach ( $entries as $entry ) {
			$this->report( $entry, false );
		}
		WP_CLI::success( sprintf( '%d SMS processed.', count( $entries ) ) );
	}

	/**
	 * List the send log.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : queued, sent, failed, retrying, rate_limited or skipped.
	 *
	 * [--order=<id>]
	 * : Only this order.
	 *
	 * [--phone=<phone>]
	 * : Only this number.
	 *
	 * [--type=<type>]
	 * : customer, admin, otp, test or manual.
	 *
	 * [--limit=<n>]
	 * : Rows to show.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * [--unmask]
	 * : Show full phone numbers.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bd-sms log --status=failed
	 *     wp bd-sms log --order=1024 --format=json
	 *
	 * @param string[]             $args Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function log( $args, $assoc_args ): void {
		unset( $args );
		$phone  = isset( $assoc_args['phone'] ) ? ( new PhoneNormalizer() )->to_local( (string) $assoc_args['phone'] ) : null;
		$query  = LogQuery::from_array(
			array(
				'status'   => $assoc_args['status'] ?? '',
				'order_id' => $assoc_args['order'] ?? 0,
				'phone'    => $phone ?? '',
				'type'     => $assoc_args['type'] ?? '',
				'limit'    => $assoc_args['limit'] ?? 20,
			)
		);
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'count' === $format ) {
			WP_CLI::line( (string) $this->services->log->count( $query ) );
			return;
		}

		$unmask = ! empty( $assoc_args['unmask'] );
		$rows   = array();
		foreach ( $this->services->log->search( $query ) as $entry ) {
			$rows[] = array(
				'id'       => $entry->id,
				'date'     => gmdate( 'Y-m-d H:i:s', $entry->created_at ),
				'type'     => $entry->type,
				'order'    => $entry->order_id ? $entry->order_id : '',
				'event'    => $entry->event,
				'phone'    => $unmask ? $entry->phone : Masker::phone( $entry->phone ),
				'status'   => $entry->status,
				'attempts' => $entry->attempts,
				'segments' => $entry->segments . ' ' . $entry->encoding,
				'gateway'  => $entry->gateway,
				'error'    => trim( $entry->error_code . ' ' . $entry->error_message ),
				'message'  => $entry->message,
			);
		}
		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'date', 'type', 'order', 'event', 'phone', 'status', 'attempts', 'segments', 'gateway', 'error', 'message' ) );
	}

	/**
	 * Count the SMS segments of a text.
	 *
	 * ## OPTIONS
	 *
	 * <text>
	 * : Message text.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp bd-sms count "আপনার অর্ডার #1024 পাঠানো হয়েছে"
	 *
	 * @param string[]             $args Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function count( $args, $assoc_args ): void {
		$info = ( new SegmentCounter() )->count( (string) ( $args[0] ?? '' ) );
		WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), array( $info->to_array() ), array_keys( $info->to_array() ) );
	}

	/**
	 * Retry a failed or deferred SMS now.
	 *
	 * ## OPTIONS
	 *
	 * <log-id>
	 * : Log entry ID (see `wp bd-sms log`).
	 *
	 * @param string[]             $args Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function retry( $args, $assoc_args ): void {
		unset( $assoc_args );
		$id    = (int) ( $args[0] ?? 0 );
		$entry = $this->services->log->find( $id );
		if ( null === $entry ) {
			WP_CLI::error( sprintf( 'Log entry %d not found.', $id ) );
			return;
		}
		if ( LogEntry::STATUS_FAILED === $entry->status ) {
			$entry->status   = LogEntry::STATUS_RETRYING;
			$entry->attempts = 0;
			$this->services->log->update( $entry );
		}
		$entry = $this->services->sender()->retry( $id );
		if ( null === $entry ) {
			WP_CLI::error( 'This entry cannot be retried (already sent, skipped or an OTP code).' );
			return;
		}
		$this->report( $entry );
	}

	/**
	 * Show or reset the gateway failover state.
	 *
	 * ## OPTIONS
	 *
	 * [--reset]
	 * : Close the breaker so the next SMS tries the main gateway again.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp bd-sms failover
	 *     wp bd-sms failover --reset
	 *
	 * @param string[]             $args Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function failover( $args, $assoc_args ): void {
		unset( $args );
		$gateway = $this->services->sending_gateway();
		if ( ! $gateway instanceof FailoverGateway ) {
			WP_CLI::log( sprintf( 'Failover is off: every SMS goes through %s. Choose a backup gateway on the settings screen or define WOO_BD_SMS_FAILOVER_GATEWAY.', $gateway->id() ) );
			return;
		}
		if ( ! empty( $assoc_args['reset'] ) ) {
			$gateway->reset();
			WP_CLI::success( sprintf( 'Breaker closed; the next SMS tries %s first.', $gateway->id() ) );
			return;
		}

		$state  = $gateway->state();
		$policy = $gateway->policy();
		$row    = array(
			'primary'    => $state['primary'],
			'backup'     => $state['backup'],
			'mode'       => $policy->mode(),
			'codes'      => implode( ',', $policy->codes() ),
			'failures'   => $state['failures'],
			'threshold'  => $policy->threshold(),
			'state'      => $state['open'] ? 'open' : 'closed',
			'open_until' => $state['open'] ? gmdate( 'Y-m-d H:i:s', $state['open_until'] ) . ' UTC' : '',
		);
		WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), array( $row ), array_keys( $row ) );
		foreach ( $gateway->validate_backup() as $problem ) {
			WP_CLI::warning( sprintf( 'Backup gateway: %s', $problem ) );
		}
	}

	/**
	 * Print the outcome of one SMS.
	 *
	 * @param LogEntry $entry Entry.
	 * @param bool     $as_result Use success/error instead of log lines.
	 */
	private function report( LogEntry $entry, bool $as_result = true ): void {
		$line = sprintf( '#%1$d %2$s %3$s: %4$s %5$s', $entry->id, $entry->type, Masker::phone( $entry->phone ), $entry->status, trim( $entry->error_code . ' ' . $entry->error_message ) );
		if ( ! $as_result ) {
			WP_CLI::log( trim( $line ) );
			return;
		}
		if ( LogEntry::STATUS_SENT === $entry->status ) {
			WP_CLI::success( trim( $line ) );
		} elseif ( in_array( $entry->status, array( LogEntry::STATUS_RETRYING, LogEntry::STATUS_RATE_LIMITED ), true ) ) {
			WP_CLI::warning( trim( $line ) );
		} else {
			WP_CLI::error( trim( $line ) );
		}
	}
}
