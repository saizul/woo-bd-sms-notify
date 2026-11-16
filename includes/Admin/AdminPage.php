<?php
/**
 * Settings, log and tools screen.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\WP\Admin;

use Saizul\BdSms\Gateway\BalanceAware;
use Saizul\BdSms\Gateway\FailoverGateway;
use Saizul\BdSms\Gateway\FailoverPolicy;
use Saizul\BdSms\Gateway\FieldDefinition;
use Saizul\BdSms\Gateway\GenericHttpGateway;
use Saizul\BdSms\Gateway\GenericPresets;
use Saizul\BdSms\Log\LogEntry;
use Saizul\BdSms\Log\LogQuery;
use Saizul\BdSms\Sms\OutgoingSms;
use Saizul\BdSms\Sms\SegmentCounter;
use Saizul\BdSms\Support\Masker;
use Saizul\BdSms\Template\Placeholders;
use Saizul\BdSms\Template\TemplateRenderer;
use Saizul\BdSms\WP\Order\OrderScreen;
use Saizul\BdSms\WP\Plugin;
use Saizul\BdSms\WP\Services;
use Saizul\BdSms\WP\Settings;

/**
 * WooCommerce > SMS Notify.
 */
final class AdminPage {

	public const SLUG = 'woo-bd-sms';

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
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_post_woo_bd_sms_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_woo_bd_sms_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_woo_bd_sms_retry_now', array( $this, 'handle_retry' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WOO_BD_SMS_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Add the submenu.
	 */
	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'SMS Notify', 'woo-bd-sms-notify' ),
			__( 'SMS Notify', 'woo-bd-sms-notify' ),
			Plugin::capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ): array {
		$links   = (array) $links;
		$links[] = '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'woo-bd-sms-notify' ) . '</a>';

		return $links;
	}

	/**
	 * Screen URL.
	 *
	 * @param string               $tab Tab.
	 * @param array<string,string> $args Extra query arguments.
	 */
	public static function url( string $tab = 'gateway', array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Tabs.
	 *
	 * @return array<string,string>
	 */
	private function tabs(): array {
		return array(
			'gateway'   => __( 'Gateway', 'woo-bd-sms-notify' ),
			'templates' => __( 'Templates', 'woo-bd-sms-notify' ),
			'otp'       => __( 'COD verification', 'woo-bd-sms-notify' ),
			'log'       => __( 'Send log', 'woo-bd-sms-notify' ),
			'tools'     => __( 'Test & tools', 'woo-bd-sms-notify' ),
		);
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage SMS settings.', 'woo-bd-sms-notify' ), '', array( 'response' => 403 ) );
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'gateway'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab selection only.
		if ( ! isset( $this->tabs()[ $tab ] ) ) {
			$tab = 'gateway';
		}

		echo '<div class="wrap woo-bd-sms">';
		echo '<h1>' . esc_html__( 'SMS Notify for WooCommerce', 'woo-bd-sms-notify' ) . '</h1>';
		$this->render_notices();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $this->tabs() as $id => $label ) {
			printf( '<a href="%1$s" class="nav-tab%2$s">%3$s</a>', esc_url( self::url( $id ) ), $id === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';

		switch ( $tab ) {
			case 'log':
				$this->render_log();
				break;
			case 'tools':
				$this->render_tools();
				break;
			default:
				$this->render_form( $tab );
		}
		echo '</div>';
	}

	/**
	 * Settings form for the gateway, templates and OTP tabs.
	 *
	 * @param string $tab Tab.
	 */
	private function render_form( string $tab ): void {
		$settings = $this->services->settings->all();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="woo_bd_sms_save">';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( 'woo_bd_sms_save' );

		if ( 'templates' === $tab ) {
			$this->render_templates( $settings );
		} elseif ( 'otp' === $tab ) {
			$this->render_otp( $settings );
		} else {
			$this->render_gateway( $settings );
		}

		submit_button();
		echo '</form>';
	}

	/**
	 * Gateway tab.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private function render_gateway( array $settings ): void {
		$registry = $this->services->settings->registry();
		$active   = $this->services->settings->gateway_id();

		echo '<table class="form-table" role="presentation">';
		$this->checkbox_row( 'enabled', __( 'Send order SMS', 'woo-bd-sms-notify' ), ! empty( $settings['enabled'] ) );

		echo '<tr><th scope="row"><label for="woo-bd-sms-gateway">' . esc_html__( 'Gateway', 'woo-bd-sms-notify' ) . '</label></th><td><select id="woo-bd-sms-gateway" name="settings[gateway]">';
		foreach ( $registry->ids() as $id ) {
			$label = $this->services->gateway( $id )->label();
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $id ), selected( $active, $id, false ), esc_html( $label ) );
		}
		echo '</select>';
		if ( defined( 'WOO_BD_SMS_GATEWAY' ) ) {
			echo '<p class="description">' . esc_html__( 'Set by WOO_BD_SMS_GATEWAY in wp-config.php.', 'woo-bd-sms-notify' ) . '</p>';
		}
		echo '</td></tr>';
		$this->render_failover( $settings, $active );
		echo '</table>';

		foreach ( $registry->ids() as $id ) {
			$gateway = $this->services->gateway( $id );
			echo '<h2>' . esc_html( $gateway->label() ) . '</h2>';
			if ( GenericHttpGateway::ID === $id ) {
				echo '<p>' . esc_html__( 'Connect any HTTP SMS API. Placeholders: {to}, {to_local}, {to_e164}, {message}, {sender_id}, {api_key}, {api_secret}, {reference}, {unicode}, {type}.', 'woo-bd-sms-notify' ) . '</p>';
				echo '<p><label for="woo-bd-sms-preset">' . esc_html__( 'Fill in from a preset:', 'woo-bd-sms-notify' ) . '</label> <select id="woo-bd-sms-preset" name="settings[generic_preset]"><option value="">&mdash;</option>';
				foreach ( $this->presets() as $preset_id => $preset ) {
					printf( '<option value="%1$s">%2$s</option>', esc_attr( $preset_id ), esc_html( $preset['label'] ) );
				}
				echo '</select></p>';
			}
			echo '<table class="form-table" role="presentation">';
			$stored = isset( $settings['gateways'][ $id ] ) ? (array) $settings['gateways'][ $id ] : array();
			foreach ( $registry->fields( $id ) as $field ) {
				$this->gateway_field_row( $id, $field, (string) ( $stored[ $field->key ] ?? '' ) );
			}
			echo '</table>';
		}

		echo '<h2>' . esc_html__( 'Recipients and limits', 'woo-bd-sms-notify' ) . '</h2><table class="form-table" role="presentation">';
		$this->text_row( 'admin_phones', __( 'Admin phone numbers', 'woo-bd-sms-notify' ), (string) $settings['admin_phones'], __( 'Comma separated. Receive the admin copies enabled on the Templates tab.', 'woo-bd-sms-notify' ) );
		$this->number_row( 'rate[per_phone_limit]', __( 'SMS per number', 'woo-bd-sms-notify' ), (int) $settings['rate']['per_phone_limit'], __( 'Per window below; 0 = unlimited.', 'woo-bd-sms-notify' ) );
		$this->number_row( 'rate[per_phone_window]', __( 'Per-number window (seconds)', 'woo-bd-sms-notify' ), (int) $settings['rate']['per_phone_window'] );
		$this->number_row( 'rate[global_limit]', __( 'SMS for the whole store', 'woo-bd-sms-notify' ), (int) $settings['rate']['global_limit'], __( 'Per window below; 0 = unlimited.', 'woo-bd-sms-notify' ) );
		$this->number_row( 'rate[global_window]', __( 'Store window (seconds)', 'woo-bd-sms-notify' ), (int) $settings['rate']['global_window'] );
		$this->number_row( 'retry[max_attempts]', __( 'Attempts per SMS', 'woo-bd-sms-notify' ), (int) $settings['retry']['max_attempts'], __( 'Temporary gateway errors are retried with Action Scheduler.', 'woo-bd-sms-notify' ) );
		$this->number_row( 'retry[base_delay]', __( 'First retry after (seconds)', 'woo-bd-sms-notify' ), (int) $settings['retry']['base_delay'] );
		$this->number_row( 'max_segments', __( 'Longest SMS (segments)', 'woo-bd-sms-notify' ), (int) $settings['max_segments'] );
		$this->checkbox_row( 'plain_punctuation', __( 'Replace curly quotes and dashes so English SMS stay GSM-7', 'woo-bd-sms-notify' ), ! empty( $settings['plain_punctuation'] ) );
		$this->number_row( 'log_retention_days', __( 'Keep the log for (days)', 'woo-bd-sms-notify' ), (int) $settings['log_retention_days'], __( '0 keeps entries forever.', 'woo-bd-sms-notify' ) );
		$this->checkbox_row( 'delete_on_uninstall', __( 'Delete settings and the log when the plugin is deleted', 'woo-bd-sms-notify' ), ! empty( $settings['delete_on_uninstall'] ) );
		echo '</table>';
	}

	/**
	 * Backup gateway rows of the gateway tab.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param string              $active Active gateway id.
	 */
	private function render_failover( array $settings, string $active ): void {
		$failover = isset( $settings['failover'] ) && is_array( $settings['failover'] ) ? $settings['failover'] : array();
		$backup   = $this->services->settings->failover_gateway_id();

		echo '<tr><th scope="row"><label for="woo-bd-sms-failover-gateway">' . esc_html__( 'Backup gateway', 'woo-bd-sms-notify' ) . '</label></th><td><select id="woo-bd-sms-failover-gateway" name="settings[failover][gateway]">';
		printf( '<option value="">%s</option>', esc_html__( 'None (no failover)', 'woo-bd-sms-notify' ) );
		foreach ( $this->services->settings->registry()->ids() as $id ) {
			if ( $id === $active ) {
				continue;
			}
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $id ), selected( $backup, $id, false ), esc_html( $this->services->gateway( $id )->label() ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Messages the main gateway cannot send are sent through this gateway right away.', 'woo-bd-sms-notify' ) . '</p>';
		if ( defined( 'WOO_BD_SMS_FAILOVER_GATEWAY' ) ) {
			echo '<p class="description">' . esc_html__( 'Set by WOO_BD_SMS_FAILOVER_GATEWAY in wp-config.php.', 'woo-bd-sms-notify' ) . '</p>';
		}
		$gateway = $this->services->sending_gateway();
		if ( $gateway instanceof FailoverGateway ) {
			$state = $gateway->state();
			if ( $state['open'] ) {
				/* translators: %s: date and time */
				echo '<p class="description"><strong>' . esc_html( sprintf( __( 'The main gateway failed repeatedly and is skipped until %s.', 'woo-bd-sms-notify' ), wp_date( 'Y-m-d H:i:s', $state['open_until'] ) ) ) . '</strong></p>';
			}
		}
		echo '</td></tr>';

		$mode = (string) ( $failover['mode'] ?? FailoverPolicy::MODE_TEMPORARY );
		echo '<tr><th scope="row"><label for="woo-bd-sms-failover-mode">' . esc_html__( 'Fail over on', 'woo-bd-sms-notify' ) . '</label></th><td><select id="woo-bd-sms-failover-mode" name="settings[failover][mode]">';
		printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( FailoverPolicy::MODE_TEMPORARY ), selected( $mode, FailoverPolicy::MODE_TEMPORARY, false ), esc_html__( 'Temporary errors (network, server errors, rate limits)', 'woo-bd-sms-notify' ) );
		printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( FailoverPolicy::MODE_ANY ), selected( $mode, FailoverPolicy::MODE_ANY, false ), esc_html__( 'Any error (also balance, credentials, sender ID)', 'woo-bd-sms-notify' ) );
		echo '</select></td></tr>';
		$this->text_row( 'failover[codes]', __( 'Also fail over on codes', 'woo-bd-sms-notify' ), (string) ( $failover['codes'] ?? '' ), __( 'Comma-separated gateway error codes, for example 1007 for an empty BulkSMSBD balance.', 'woo-bd-sms-notify' ) );
		$this->number_row( 'failover[threshold]', __( 'Skip the main gateway after (failures)', 'woo-bd-sms-notify' ), (int) ( $failover['threshold'] ?? 3 ), __( 'Consecutive failures; 0 always tries the main gateway first.', 'woo-bd-sms-notify' ) );
		$this->number_row( 'failover[cooldown]', __( 'Skip it for (seconds)', 'woo-bd-sms-notify' ), (int) ( $failover['cooldown'] ?? 300 ) );
	}

	/**
	 * Templates tab.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private function render_templates( array $settings ): void {
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$counter  = new SegmentCounter();
		$renderer = new TemplateRenderer();
		$sample   = $this->sample_vars();

		echo '<p>' . esc_html__( 'Placeholders:', 'woo-bd-sms-notify' ) . ' ';
		$codes = array();
		foreach ( Placeholders::ORDER as $name => $description ) {
			$codes[] = '<code title="' . esc_attr( $description ) . '">{' . esc_html( $name ) . '}</code>';
		}
		echo wp_kses( implode( ' ', $codes ), array( 'code' => array( 'title' => true ) ) );
		echo '<br>' . esc_html__( 'Use {placeholder|fallback} for a default, for example {tracking|not yet available}. Bangla text is sent as Unicode: 70 characters per SMS, 67 per part when longer.', 'woo-bd-sms-notify' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Status', 'woo-bd-sms-notify' ) . '</th><th>' . esc_html__( 'Customer SMS', 'woo-bd-sms-notify' ) . '</th><th>' . esc_html__( 'Admin copy', 'woo-bd-sms-notify' ) . '</th></tr></thead><tbody>';
		$templates = (array) $settings['templates'];
		foreach ( $statuses as $key => $label ) {
			$slug = 'wc-' === substr( (string) $key, 0, 3 ) ? substr( (string) $key, 3 ) : (string) $key;
			$row  = isset( $templates[ $slug ] ) ? (array) $templates[ $slug ] : array();
			echo '<tr><th scope="row">' . esc_html( (string) $label ) . '<br><code>' . esc_html( $slug ) . '</code></th>';
			foreach ( array( 'customer', 'admin' ) as $who ) {
				$text = (string) ( $row[ $who ] ?? '' );
				$info = $counter->count( $renderer->render( $text, $sample ) );
				echo '<td>';
				printf(
					'<label><input type="checkbox" name="settings[templates][%1$s][%2$s_enabled]" value="1"%3$s> %4$s</label>',
					esc_attr( $slug ),
					esc_attr( $who ),
					checked( ! empty( $row[ $who . '_enabled' ] ), true, false ),
					esc_html__( 'Enabled', 'woo-bd-sms-notify' )
				);
				printf( '<textarea name="settings[templates][%1$s][%2$s]" rows="3" class="large-text">%3$s</textarea>', esc_attr( $slug ), esc_attr( $who ), esc_textarea( $text ) );
				printf(
					'<p class="description">%s</p>',
					esc_html(
						sprintf(
							/* translators: 1: number of SMS, 2: encoding, 3: characters */
							_n( 'Preview: %1$d SMS, %2$s, %3$d characters', 'Preview: %1$d SMS, %2$s, %3$d characters', $info->segments(), 'woo-bd-sms-notify' ),
							$info->segments(),
							$info->encoding(),
							$info->characters()
						)
					)
				);
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Tracking', 'woo-bd-sms-notify' ) . '</h2><table class="form-table" role="presentation">';
		$this->text_row( 'tracking_meta_keys', __( 'Tracking number meta keys', 'woo-bd-sms-notify' ), (string) $settings['tracking_meta_keys'], __( 'Comma separated order meta keys checked before the built-in ones (_tracking_number, Shipment Tracking items).', 'woo-bd-sms-notify' ) );
		$this->text_row( 'tracking_url', __( 'Tracking link template', 'woo-bd-sms-notify' ), (string) $settings['tracking_url'], __( 'Used for {tracking_url} when no link is stored, for example https://courier.example/track/{tracking}', 'woo-bd-sms-notify' ) );
		echo '</table>';
	}

	/**
	 * OTP tab.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private function render_otp( array $settings ): void {
		$otp     = (array) $settings['otp'];
		$methods = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
				$methods[ (string) $id ] = wp_strip_all_tags( (string) $gateway->get_method_title() );
			}
		}
		if ( array() === $methods ) {
			$methods = array( 'cod' => __( 'Cash on delivery', 'woo-bd-sms-notify' ) );
		}

		echo '<p>' . esc_html__( 'Customers paying with the selected methods must confirm their billing phone with a one-time code before the order is placed. Works with the classic (shortcode) checkout.', 'woo-bd-sms-notify' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		$this->checkbox_row( 'otp[enabled]', __( 'Require phone verification', 'woo-bd-sms-notify' ), ! empty( $otp['enabled'] ) );
		echo '<tr><th scope="row">' . esc_html__( 'Payment methods', 'woo-bd-sms-notify' ) . '</th><td>';
		foreach ( $methods as $id => $title ) {
			printf(
				'<label><input type="checkbox" name="settings[otp][methods][]" value="%1$s"%2$s> %3$s</label><br>',
				esc_attr( $id ),
				checked( in_array( $id, (array) $otp['methods'], true ), true, false ),
				esc_html( $title )
			);
		}
		echo '</td></tr>';
		$this->text_row( 'otp[min_total]', __( 'Only for orders from (amount)', 'woo-bd-sms-notify' ), (string) $otp['min_total'] );
		$this->number_row( 'otp[length]', __( 'Code length', 'woo-bd-sms-notify' ), (int) $otp['length'] );
		$this->number_row( 'otp[ttl]', __( 'Code valid for (seconds)', 'woo-bd-sms-notify' ), (int) $otp['ttl'] );
		$this->number_row( 'otp[max_attempts]', __( 'Wrong codes allowed', 'woo-bd-sms-notify' ), (int) $otp['max_attempts'] );
		$this->number_row( 'otp[resend_cooldown]', __( 'Resend cooldown (seconds)', 'woo-bd-sms-notify' ), (int) $otp['resend_cooldown'] );
		$this->number_row( 'otp[max_sends]', __( 'Codes per number', 'woo-bd-sms-notify' ), (int) $otp['max_sends'] );
		$this->number_row( 'otp[send_window]', __( 'Per window (seconds)', 'woo-bd-sms-notify' ), (int) $otp['send_window'] );
		echo '<tr><th scope="row"><label for="woo-bd-sms-otp-template">' . esc_html__( 'Message', 'woo-bd-sms-notify' ) . '</label></th><td>';
		printf( '<textarea id="woo-bd-sms-otp-template" name="settings[otp][template]" rows="3" class="large-text">%s</textarea>', esc_textarea( (string) $otp['template'] ) );
		echo '<p class="description">' . esc_html__( 'Placeholders: {otp}, {minutes}, {site_name}. Codes are never stored in plain text or in the log.', 'woo-bd-sms-notify' ) . '</p></td></tr>';
		echo '</table>';
	}

	/**
	 * Log tab.
	 */
	private function render_log(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$args = array(
			'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'order_id' => isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0,
			'phone'    => isset( $_GET['phone'] ) ? sanitize_text_field( wp_unslash( $_GET['phone'] ) ) : '',
			'limit'    => 50,
		);
		$page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable

		$args['offset'] = ( $page - 1 ) * 50;
		$query          = LogQuery::from_array( $args );
		$entries        = $this->services->log->search( $query );
		$total          = $this->services->log->count( $query );

		echo '<form method="get" class="woo-bd-sms-filter"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="log">';
		echo '<select name="status"><option value="">' . esc_html__( 'All statuses', 'woo-bd-sms-notify' ) . '</option>';
		foreach ( LogEntry::STATUSES as $status ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $status ), selected( $query->status, $status, false ), esc_html( OrderScreen::status_label( $status ) ) );
		}
		echo '</select> ';
		printf( '<input type="search" name="phone" value="%1$s" placeholder="%2$s"> ', esc_attr( $query->phone ), esc_attr__( 'Phone (01XXXXXXXXX)', 'woo-bd-sms-notify' ) );
		printf( '<input type="number" name="order_id" value="%1$s" placeholder="%2$s"> ', esc_attr( $query->order_id ? (string) $query->order_id : '' ), esc_attr__( 'Order', 'woo-bd-sms-notify' ) );
		submit_button( __( 'Filter', 'woo-bd-sms-notify' ), 'secondary', '', false );
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Date', 'woo-bd-sms-notify' ), __( 'Recipient', 'woo-bd-sms-notify' ), __( 'Order', 'woo-bd-sms-notify' ), __( 'Message', 'woo-bd-sms-notify' ), __( 'Status', 'woo-bd-sms-notify' ), __( 'Details', 'woo-bd-sms-notify' ), '' ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( array() === $entries ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No SMS logged yet.', 'woo-bd-sms-notify' ) . '</td></tr>';
		}
		foreach ( $entries as $entry ) {
			echo '<tr>';
			echo '<td>' . esc_html( wp_date( 'Y-m-d H:i:s', $entry->created_at ) ) . '</td>';
			echo '<td>' . esc_html( Masker::phone( $entry->phone ) ) . '<br><small>' . esc_html( $entry->type ) . '</small></td>';
			echo '<td>' . ( $entry->order_id ? '#' . esc_html( (string) $entry->order_id ) : '&mdash;' ) . '<br><small>' . esc_html( $entry->event ) . '</small></td>';
			echo '<td>' . esc_html( $entry->message ) . '<br><small>' . esc_html( sprintf( '%1$d x %2$s', $entry->segments, $entry->encoding ) ) . '</small></td>';
			echo '<td>' . esc_html( OrderScreen::status_label( $entry->status ) ) . '<br><small>' . esc_html( $entry->gateway . ' / ' . $entry->attempts ) . '</small></td>';
			echo '<td>' . esc_html( trim( $entry->error_code . ' ' . $entry->error_message ) ) . ( '' !== $entry->provider_id ? '<br><small>' . esc_html( $entry->provider_id ) . '</small>' : '' ) . '</td>';
			echo '<td>';
			if ( in_array( $entry->status, array( LogEntry::STATUS_FAILED, LogEntry::STATUS_RETRYING, LogEntry::STATUS_RATE_LIMITED ), true ) && LogEntry::TYPE_OTP !== $entry->type ) {
				printf(
					'<a class="button button-small" href="%1$s">%2$s</a>',
					esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'woo_bd_sms_retry_now', 'log_id' => $entry->id ), admin_url( 'admin-post.php' ) ), 'woo_bd_sms_retry_' . $entry->id ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short URL arguments.
					esc_html__( 'Retry now', 'woo-bd-sms-notify' )
				);
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / 50 );
		if ( $pages > 1 ) {
			echo '<p class="tablenav-pages">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				if ( $i === $page ) {
					echo '<strong>' . esc_html( (string) $i ) . '</strong> ';
				} else {
					printf( '<a href="%1$s">%2$s</a> ', esc_url( self::url( 'log', array_filter( array( 'paged' => (string) $i, 'status' => $query->status, 'phone' => $query->phone ) ) ) ), esc_html( (string) $i ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short URL arguments.
				}
			}
			echo '</p>';
		}
	}

	/**
	 * Tools tab.
	 */
	private function render_tools(): void {
		$gateway = $this->services->gateway();
		echo '<h2>' . esc_html__( 'Send a test SMS', 'woo-bd-sms-notify' ) . '</h2>';
		/* translators: %s: gateway name */
		echo '<p>' . esc_html( sprintf( __( 'Uses the active gateway: %s.', 'woo-bd-sms-notify' ), $gateway->label() ) ) . '</p>';
		$errors = $gateway->validate();
		if ( array() !== $errors ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( implode( ' ', $errors ) ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="woo_bd_sms_test">';
		wp_nonce_field( 'woo_bd_sms_test' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="woo-bd-sms-test-phone">' . esc_html__( 'Phone', 'woo-bd-sms-notify' ) . '</label></th><td><input id="woo-bd-sms-test-phone" type="tel" name="phone" class="regular-text" placeholder="01XXXXXXXXX" required></td></tr>';
		echo '<tr><th scope="row"><label for="woo-bd-sms-test-message">' . esc_html__( 'Message', 'woo-bd-sms-notify' ) . '</label></th><td><textarea id="woo-bd-sms-test-message" name="message" rows="3" class="large-text">' . esc_textarea( __( 'Test SMS from SMS Notify for WooCommerce.', 'woo-bd-sms-notify' ) ) . '</textarea></td></tr>';
		echo '</table>';
		submit_button( __( 'Send test SMS', 'woo-bd-sms-notify' ), 'secondary' );
		echo '</form>';

		if ( $gateway instanceof BalanceAware && array() === $errors ) {
			$balance = $gateway->balance();
			echo '<h2>' . esc_html__( 'Balance', 'woo-bd-sms-notify' ) . '</h2><p>' . esc_html( null === $balance ? __( 'Not available.', 'woo-bd-sms-notify' ) : $balance ) . '</p>';
		}

		echo '<h2>' . esc_html__( 'WP-CLI', 'woo-bd-sms-notify' ) . '</h2><p><code>wp bd-sms test 01XXXXXXXXX</code> <code>wp bd-sms send &lt;order-id&gt;</code> <code>wp bd-sms log --status=failed</code> <code>wp bd-sms count "&lt;text&gt;"</code></p>';
	}

	/**
	 * Save settings.
	 */
	public function handle_save(): void {
		check_admin_referer( 'woo_bd_sms_save' );
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage SMS settings.', 'woo-bd-sms-notify' ), '', array( 'response' => 403 ) );
		}
		$tab   = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'gateway';
		$input = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised field by field by SettingsSchema.

		$current = $this->services->settings->all();
		// Each tab only posts its own fields; keep the others.
		$input = $this->complete_input( $tab, (array) $input, $current );

		if ( ! empty( $input['generic_preset'] ) ) {
			$generic                      = isset( $input['gateways']['generic'] ) ? (array) $input['gateways']['generic'] : array();
			$input['gateways']['generic'] = $this->apply_preset( sanitize_key( (string) $input['generic_preset'] ), $generic );
		}

		$statuses = array();
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( array_keys( wc_get_order_statuses() ) as $key ) {
				$statuses[] = 'wc-' === substr( (string) $key, 0, 3 ) ? substr( (string) $key, 3 ) : (string) $key;
			}
		}
		$payment_methods = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$payment_methods = array_map( 'strval', array_keys( WC()->payment_gateways()->payment_gateways() ) );
		}

		$result = $this->services->settings->schema()->sanitize( $input, $current, $statuses, $payment_methods );
		$this->services->settings->save( $result['settings'] );

		$this->notice( array() === $result['errors'] ? 'success' : 'warning', array() === $result['errors'] ? __( 'Settings saved.', 'woo-bd-sms-notify' ) : implode( ' ', $result['errors'] ) );
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	/**
	 * Send a test SMS.
	 */
	public function handle_test(): void {
		check_admin_referer( 'woo_bd_sms_test' );
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to send SMS.', 'woo-bd-sms-notify' ), '', array( 'response' => 403 ) );
		}
		$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$entry   = $this->services->sender()->send( new OutgoingSms( $phone, $message, LogEntry::TYPE_TEST, 'test' ) );

		if ( LogEntry::STATUS_SENT === $entry->status ) {
			/* translators: %s: masked phone number */
			$this->notice( 'success', sprintf( __( 'Test SMS sent to %s.', 'woo-bd-sms-notify' ), Masker::phone( $entry->phone ) ) );
		} else {
			/* translators: 1: status, 2: error */
			$this->notice( 'error', sprintf( __( 'Test SMS %1$s: %2$s', 'woo-bd-sms-notify' ), OrderScreen::status_label( $entry->status ), trim( $entry->error_code . ' ' . $entry->error_message ) ) );
		}
		wp_safe_redirect( self::url( 'tools' ) );
		exit;
	}

	/**
	 * Retry a logged SMS now.
	 */
	public function handle_retry(): void {
		$log_id = isset( $_GET['log_id'] ) ? absint( wp_unslash( $_GET['log_id'] ) ) : 0;
		check_admin_referer( 'woo_bd_sms_retry_' . $log_id );
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to send SMS.', 'woo-bd-sms-notify' ), '', array( 'response' => 403 ) );
		}
		$entry = $this->services->log->find( $log_id );
		if ( null !== $entry && LogEntry::STATUS_FAILED === $entry->status ) {
			// A manual retry gets a fresh set of attempts.
			$entry->status   = LogEntry::STATUS_RETRYING;
			$entry->attempts = 0;
			$this->services->log->update( $entry );
		}
		$entry = $this->services->sender()->retry( $log_id );
		$this->notice( null !== $entry && LogEntry::STATUS_SENT === $entry->status ? 'success' : 'warning', null === $entry ? __( 'Nothing to retry.', 'woo-bd-sms-notify' ) : OrderScreen::status_label( $entry->status ) . ' ' . $entry->error_message );
		wp_safe_redirect( self::url( 'log' ) );
		exit;
	}

	/**
	 * Fill in the values of the tabs that were not submitted.
	 *
	 * @param string              $tab Submitted tab.
	 * @param array<string,mixed> $input Input.
	 * @param array<string,mixed> $current Current settings.
	 * @return array<string,mixed>
	 */
	private function complete_input( string $tab, array $input, array $current ): array {
		$own    = array(
			'gateway'   => array( 'enabled', 'gateway', 'gateways', 'failover', 'admin_phones', 'rate', 'retry', 'max_segments', 'plain_punctuation', 'log_retention_days', 'delete_on_uninstall', 'generic_preset' ),
			'templates' => array( 'templates', 'tracking_meta_keys', 'tracking_url' ),
			'otp'       => array( 'otp' ),
		);
		$fields = $own[ $tab ] ?? array();
		foreach ( $current as $key => $value ) {
			if ( in_array( $key, $fields, true ) ) {
				continue;
			}
			// Secrets are not echoed back by the form; an empty value keeps the stored one anyway.
			$input[ $key ] = $value;
		}

		return $input;
	}

	/**
	 * Presets including the filtered ones.
	 *
	 * @return array<string,array{label:string,config:array<string,string>}>
	 */
	private function presets(): array {
		/**
		 * Filters the presets offered for the generic HTTP gateway.
		 *
		 * @param array $presets Preset id => array( 'label' => string, 'config' => array ).
		 */
		return (array) apply_filters( 'woo_bd_sms_generic_presets', GenericPresets::all() );
	}

	/**
	 * Apply a preset to the submitted generic gateway values.
	 *
	 * @param string               $preset Preset id.
	 * @param array<string,string> $values Submitted values.
	 * @return array<string,string>
	 */
	private function apply_preset( string $preset, array $values ): array {
		$presets = $this->presets();
		if ( ! isset( $presets[ $preset ]['config'] ) ) {
			return $values;
		}

		return array_merge( $values, (array) $presets[ $preset ]['config'] );
	}

	/**
	 * Values used to preview templates.
	 *
	 * @return array<string,string>
	 */
	private function sample_vars(): array {
		return array(
			'order_id'        => '1024',
			'order_number'    => '1024',
			'status'          => __( 'Processing', 'woo-bd-sms-notify' ),
			'total'           => '1,250',
			'currency'        => 'BDT',
			'currency_symbol' => '৳',
			'name'            => 'Karim Ahmed',
			'first_name'      => 'Karim',
			'last_name'       => 'Ahmed',
			'phone'           => '01711234567',
			'item_count'      => '2',
			'items'           => 'Saree, Gift wrap',
			'tracking'        => 'SF12345678',
			'site_name'       => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);
	}

	/**
	 * Gateway field row.
	 *
	 * @param string          $gateway Gateway id.
	 * @param FieldDefinition $field Field.
	 * @param string          $value Stored value.
	 */
	private function gateway_field_row( string $gateway, FieldDefinition $field, string $value ): void {
		$name     = sprintf( 'settings[gateways][%1$s][%2$s]', $gateway, $field->key );
		$id       = 'woo-bd-sms-' . $gateway . '-' . $field->key;
		$constant = $this->services->settings->constant_value( $gateway, $field->key );

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field->label ) . ( $field->required ? ' *' : '' ) . '</label></th><td>';
		if ( null !== $constant ) {
			$shown = $field->is_secret() ? Masker::secret( $constant ) : $constant;
			printf( '<input type="text" id="%1$s" class="regular-text" value="%2$s" disabled> ', esc_attr( $id ), esc_attr( $shown ) );
			/* translators: %s: constant name */
			echo '<p class="description">' . esc_html( sprintf( __( 'Defined by %s in wp-config.php.', 'woo-bd-sms-notify' ), Settings::constant_name( $gateway, $field->key ) ) ) . '</p></td></tr>';
			return;
		}

		switch ( $field->type ) {
			case FieldDefinition::SECRET:
				printf(
					'<input type="password" id="%1$s" name="%2$s" class="regular-text" value="" autocomplete="new-password" placeholder="%3$s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( '' === $value ? '' : Masker::secret( $value ) )
				);
				if ( '' !== $value ) {
					printf( ' <label><input type="checkbox" name="%1$s" value="1"> %2$s</label>', esc_attr( sprintf( 'settings[gateways][%1$s][%2$s__clear]', $gateway, $field->key ) ), esc_html__( 'Remove', 'woo-bd-sms-notify' ) );
				}
				/* translators: %s: constant name */
				echo '<p class="description">' . esc_html( sprintf( __( 'Leave empty to keep the saved value. Can also be set with %s in wp-config.php.', 'woo-bd-sms-notify' ), Settings::constant_name( $gateway, $field->key ) ) ) . '</p>';
				break;
			case FieldDefinition::SELECT:
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $field->options as $option => $label ) {
					printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $option ), selected( $value, (string) $option, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;
			case FieldDefinition::TEXTAREA:
				printf( '<textarea id="%1$s" name="%2$s" rows="5" class="large-text code">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $value ) );
				break;
			default:
				printf( '<input type="%1$s" id="%2$s" name="%3$s" class="regular-text" value="%4$s">', FieldDefinition::URL === $field->type ? 'url' : 'text', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) );
		}
		if ( '' !== $field->help && ! $field->is_secret() ) {
			echo '<p class="description">' . esc_html( $field->help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $key Settings key (may contain brackets).
	 * @param string $label Label.
	 * @param bool   $checked Checked.
	 */
	private function checkbox_row( string $key, string $label, bool $checked ): void {
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="settings[%2$s]" value="1"%3$s> %1$s</label></td></tr>',
			esc_html( $label ),
			esc_attr( self::bracket( $key ) ),
			checked( $checked, true, false )
		);
	}

	/**
	 * Text row.
	 *
	 * @param string $key Settings key.
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $help Help text.
	 */
	private function text_row( string $key, string $label, string $value, string $help = '' ): void {
		$id = 'woo-bd-sms-' . sanitize_html_class( $key );
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" id="%1$s" name="settings[%3$s]" class="regular-text" value="%4$s">%5$s</td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( self::bracket( $key ) ),
			esc_attr( $value ),
			'' === $help ? '' : '<p class="description">' . esc_html( $help ) . '</p>'
		);
	}

	/**
	 * Number row.
	 *
	 * @param string $key Settings key.
	 * @param string $label Label.
	 * @param int    $value Value.
	 * @param string $help Help text.
	 */
	private function number_row( string $key, string $label, int $value, string $help = '' ): void {
		$id = 'woo-bd-sms-' . sanitize_html_class( $key );
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="number" min="0" id="%1$s" name="settings[%3$s]" class="small-text" value="%4$d">%5$s</td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( self::bracket( $key ) ),
			(int) $value,
			'' === $help ? '' : '<p class="description">' . esc_html( $help ) . '</p>'
		);
	}

	/**
	 * "rate[global]" inside settings[...] becomes "rate][global".
	 *
	 * @param string $key Key.
	 */
	private static function bracket( string $key ): string {
		return str_replace( '[', '][', str_replace( ']', '', $key ) );
	}

	/**
	 * Store a notice for the next page load.
	 *
	 * @param string $type success, warning or error.
	 * @param string $message Message.
	 */
	private function notice( string $type, string $message ): void {
		set_transient(
			'woo_bd_sms_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Print and clear the stored notice.
	 */
	private function render_notices(): void {
		$key    = 'woo_bd_sms_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		$type = in_array( $notice['type'] ?? '', array( 'success', 'warning', 'error' ), true ) ? $notice['type'] : 'info';
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( (string) ( $notice['message'] ?? '' ) ) );
	}
}
