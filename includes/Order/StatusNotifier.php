<?php
/**
 * Order status change notifications.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\WP\Order;

use Saizul\BdSms\Log\LogEntry;
use Saizul\BdSms\Notification\NotificationBuilder;
use Saizul\BdSms\Notification\StatusTemplates;
use Saizul\BdSms\Phone\PhoneNormalizer;
use Saizul\BdSms\Support\Masker;
use Saizul\BdSms\WP\Queue\WpRetryScheduler;
use Saizul\BdSms\WP\Services;
use WC_Order;

/**
 * Sends the configured SMS when an order changes status.
 *
 * The status hook only queues an async Action Scheduler job, so checkout and
 * payment callbacks are not slowed down by the SMS gateway.
 */
final class StatusNotifier {

	public const ASYNC_HOOK = 'woo_bd_sms_order_status';

	/**
	 * Services.
	 *
	 * @var Services
	 */
	private $services;

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( Services $services ) {
		$this->services = $services;
	}

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
		add_action( self::ASYNC_HOOK, array( $this, 'run' ), 10, 2 );
	}

	/**
	 * Queue the notification for a status change.
	 *
	 * @param int           $order_id Order id.
	 * @param string        $from Previous status.
	 * @param string        $to New status.
	 * @param WC_Order|null $order Order.
	 */
	public function on_status_changed( $order_id, $from, $to, $order = null ): void {
		unset( $order );
		$order_id = (int) $order_id;
		$to       = (string) $to;
		if ( ! $this->services->settings->get( 'enabled' ) || ! $this->has_templates( $to ) ) {
			return;
		}

		// WooCommerce can report the same transition twice in one request (for example from payment callbacks).
		$guard = 'woo_bd_sms_once_' . $order_id . '_' . md5( $to );
		if ( false !== get_transient( $guard ) ) {
			return;
		}
		set_transient( $guard, 1, 5 * MINUTE_IN_SECONDS );

		/**
		 * Filters whether status notifications are sent in the background.
		 *
		 * @param bool $async Default true.
		 */
		$async = (bool) apply_filters( 'woo_bd_sms_send_async', true );
		if ( $async && WpRetryScheduler::enqueue( self::ASYNC_HOOK, array( $order_id, (string) $from ) ) ) {
			return;
		}
		$this->run( $order_id, (string) $from );
	}

	/**
	 * Send the notifications for the current status of an order.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from Previous status.
	 * @param bool   $force Ignore the "already sent" marker (manual resend).
	 * @return LogEntry[]
	 */
	public function run( $order_id, $from = '', bool $force = false ): array {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return array();
		}
		$status = (string) $order->get_status();
		if ( ! $this->has_templates( $status ) ) {
			return array();
		}

		$marker = '_woo_bd_sms_sent_' . $status;
		if ( ! $force && $order->get_meta( $marker, true ) ) {
			return array();
		}

		$settings  = $this->services->settings;
		$templates = (array) $settings->get( 'templates' );
		$data      = ( new OrderMapper( $settings ) )->map( $order, (string) $from );
		$admins    = ( new PhoneNormalizer() )->parse_list( (string) $settings->get( 'admin_phones' ) );
		$messages  = ( new NotificationBuilder() )->build( $data, $status, StatusTemplates::from_array( (array) $templates[ $status ] ), $admins );

		/**
		 * Filters the SMS about to be sent for an order status.
		 *
		 * @param \Saizul\BdSms\Sms\OutgoingSms[] $messages Messages.
		 * @param WC_Order                        $order    Order.
		 * @param string                          $status   New status.
		 */
		$messages = (array) apply_filters( 'woo_bd_sms_order_messages', $messages, $order, $status );
		if ( array() === $messages ) {
			return array();
		}

		$sender  = $this->services->sender();
		$entries = array();
		foreach ( $messages as $message ) {
			$entry     = $sender->send( $message );
			$entries[] = $entry;
			$this->add_note( $order, $entry );

			/**
			 * Fires after an order SMS was handed to the gateway (or skipped).
			 *
			 * @param LogEntry $entry Log entry.
			 * @param WC_Order $order Order.
			 */
			do_action( 'woo_bd_sms_order_sms_processed', $entry, $order );
		}

		$order->update_meta_data( $marker, (string) time() );
		$order->save();

		return $entries;
	}

	/**
	 * Whether any template is enabled for a status.
	 *
	 * @param string $status Status slug.
	 */
	private function has_templates( string $status ): bool {
		$templates = (array) $this->services->settings->get( 'templates' );
		$row       = isset( $templates[ $status ] ) && is_array( $templates[ $status ] ) ? $templates[ $status ] : array();

		return ! empty( $row['customer_enabled'] ) || ! empty( $row['admin_enabled'] );
	}

	/**
	 * Order note describing the outcome.
	 *
	 * @param WC_Order $order Order.
	 * @param LogEntry $entry Entry.
	 */
	private function add_note( WC_Order $order, LogEntry $entry ): void {
		$who = LogEntry::TYPE_ADMIN === $entry->type ? __( 'admin', 'woo-bd-sms-notify' ) : __( 'customer', 'woo-bd-sms-notify' );
		switch ( $entry->status ) {
			case LogEntry::STATUS_SENT:
				/* translators: 1: recipient type, 2: masked phone number */
				$note = sprintf( __( 'SMS sent to %1$s %2$s.', 'woo-bd-sms-notify' ), $who, Masker::phone( $entry->phone ) );
				break;
			case LogEntry::STATUS_RETRYING:
			case LogEntry::STATUS_RATE_LIMITED:
				/* translators: 1: recipient type, 2: masked phone number, 3: error message */
				$note = sprintf( __( 'SMS to %1$s %2$s will be retried: %3$s', 'woo-bd-sms-notify' ), $who, Masker::phone( $entry->phone ), $entry->error_message );
				break;
			default:
				/* translators: 1: recipient type, 2: masked phone number, 3: error message */
				$note = sprintf( __( 'SMS to %1$s %2$s was not sent: %3$s', 'woo-bd-sms-notify' ), $who, Masker::phone( $entry->phone ), $entry->error_message );
		}
		$order->add_order_note( $note );
	}
}
