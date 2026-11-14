<?php
/**
 * Order screen integration.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\WP\Order;

use Saizul\BdSms\Log\LogEntry;
use Saizul\BdSms\Log\LogQuery;
use Saizul\BdSms\Sms\OutgoingSms;
use Saizul\BdSms\Support\Masker;
use Saizul\BdSms\WP\Plugin;
use Saizul\BdSms\WP\Services;
use WC_Order;
use WP_Post;

/**
 * "Resend SMS" order action and an SMS meta box with the order's log and a
 * form to send a custom message. Works on the HPOS and the legacy screens.
 */
final class OrderScreen {

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
	 * Attach hooks.
	 */
	public function register(): void {
		add_filter( 'woocommerce_order_actions', array( $this, 'order_actions' ) );
		add_action( 'woocommerce_order_action_woo_bd_sms_resend', array( $this, 'resend' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_post_woo_bd_sms_send_custom', array( $this, 'handle_custom' ) );
	}

	/**
	 * Add the order action.
	 *
	 * @param array<string,string> $actions Actions.
	 * @return array<string,string>
	 */
	public function order_actions( $actions ): array {
		$actions = (array) $actions;
		if ( current_user_can( Plugin::capability() ) ) {
			$actions['woo_bd_sms_resend'] = __( 'Resend SMS for the current status', 'woo-bd-sms-notify' );
		}

		return $actions;
	}

	/**
	 * Run the order action (WooCommerce checks the edit nonce).
	 *
	 * @param WC_Order $order Order.
	 */
	public function resend( $order ): void {
		if ( $order instanceof WC_Order && current_user_can( Plugin::capability() ) ) {
			$entries = $this->notifier->run( $order->get_id(), '', true );
			if ( array() === $entries ) {
				$order->add_order_note( __( 'No SMS template is enabled for the current status.', 'woo-bd-sms-notify' ) );
			}
		}
	}

	/**
	 * Register the meta box on both order screens.
	 */
	public function add_meta_box(): void {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'woo-bd-sms', __( 'SMS', 'woo-bd-sms-notify' ), array( $this, 'render_meta_box' ), $screen, 'side', 'default' );
		}
	}

	/**
	 * Meta box content.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post (legacy) or order (HPOS).
	 */
	public function render_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order instanceof WP_Post ? $post_or_order->ID : 0 );
		if ( ! $order instanceof WC_Order || ! current_user_can( Plugin::capability() ) ) {
			return;
		}

		$query           = new LogQuery();
		$query->order_id = $order->get_id();
		$query->limit    = 10;
		$entries         = $this->services->log->search( $query );

		if ( array() === $entries ) {
			echo '<p>' . esc_html__( 'No SMS sent for this order yet.', 'woo-bd-sms-notify' ) . '</p>';
		} else {
			echo '<ul class="woo-bd-sms-log">';
			foreach ( $entries as $entry ) {
				printf(
					'<li><strong>%1$s</strong> %2$s &middot; %3$s<br><small>%4$s</small></li>',
					esc_html( self::status_label( $entry->status ) ),
					esc_html( Masker::phone( $entry->phone ) ),
					esc_html( wp_date( 'Y-m-d H:i', $entry->created_at ) ),
					esc_html( '' !== $entry->error_message ? $entry->error_message : $entry->event )
				);
			}
			echo '</ul>';
		}

		// The form posts to admin-post.php; it is rendered outside the order form via the "form" attribute.
		$form_id = 'woo-bd-sms-custom-' . $order->get_id();
		printf(
			'<p><label for="%1$s-text">%2$s</label><textarea id="%1$s-text" name="woo_bd_sms_text" rows="3" class="widefat" form="%1$s"></textarea></p>',
			esc_attr( $form_id ),
			esc_html__( 'Send a custom SMS to the customer', 'woo-bd-sms-notify' )
		);
		printf(
			'<p><button type="submit" class="button" form="%1$s">%2$s</button></p>',
			esc_attr( $form_id ),
			esc_html__( 'Send SMS', 'woo-bd-sms-notify' )
		);
		add_action(
			'admin_footer',
			static function () use ( $form_id, $order ) {
				printf( '<form id="%1$s" method="post" action="%2$s">', esc_attr( $form_id ), esc_url( admin_url( 'admin-post.php' ) ) );
				echo '<input type="hidden" name="action" value="woo_bd_sms_send_custom">';
				printf( '<input type="hidden" name="order_id" value="%d">', (int) $order->get_id() );
				wp_nonce_field( 'woo_bd_sms_send_custom_' . $order->get_id() );
				echo '</form>';
			}
		);
	}

	/**
	 * Send the custom SMS.
	 */
	public function handle_custom(): void {
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		check_admin_referer( 'woo_bd_sms_send_custom_' . $order_id );
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to send SMS.', 'woo-bd-sms-notify' ), '', array( 'response' => 403 ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'woo-bd-sms-notify' ), '', array( 'response' => 404 ) );
		}
		$text = isset( $_POST['woo_bd_sms_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['woo_bd_sms_text'] ) ) : '';
		if ( '' !== trim( $text ) ) {
			$data  = ( new OrderMapper( $this->services->settings ) )->map( $order );
			$text  = ( new \Saizul\BdSms\Template\TemplateRenderer() )->render( $text, $data->to_vars() );
			$entry = $this->services->sender()->send( new OutgoingSms( $data->phone(), $text, LogEntry::TYPE_MANUAL, 'manual', $order_id ) );
			/* translators: 1: masked phone number, 2: status */
			$order->add_order_note( sprintf( __( 'Custom SMS to %1$s: %2$s', 'woo-bd-sms-notify' ), Masker::phone( $entry->phone ), self::status_label( $entry->status ) ) );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Translated status label.
	 *
	 * @param string $status Status constant.
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			LogEntry::STATUS_QUEUED       => __( 'Queued', 'woo-bd-sms-notify' ),
			LogEntry::STATUS_SENT         => __( 'Sent', 'woo-bd-sms-notify' ),
			LogEntry::STATUS_FAILED       => __( 'Failed', 'woo-bd-sms-notify' ),
			LogEntry::STATUS_RETRYING     => __( 'Retrying', 'woo-bd-sms-notify' ),
			LogEntry::STATUS_RATE_LIMITED => __( 'Rate limited', 'woo-bd-sms-notify' ),
			LogEntry::STATUS_SKIPPED      => __( 'Skipped', 'woo-bd-sms-notify' ),
		);

		return $labels[ $status ] ?? $status;
	}
}
