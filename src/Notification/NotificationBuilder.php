<?php
/**
 * Order status notifications.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Notification;

use Saizul\BdSms\Log\LogEntry;
use Saizul\BdSms\Order\OrderData;
use Saizul\BdSms\Sms\OutgoingSms;
use Saizul\BdSms\Template\TemplateRenderer;

/**
 * Turns an order status change into the SMS to send.
 */
final class NotificationBuilder {

	/**
	 * Renderer.
	 *
	 * @var TemplateRenderer
	 */
	private $renderer;

	/**
	 * Constructor.
	 *
	 * @param TemplateRenderer|null $renderer Renderer.
	 */
	public function __construct( ?TemplateRenderer $renderer = null ) {
		$this->renderer = $renderer ?? new TemplateRenderer();
	}

	/**
	 * Messages for an order that moved to a status.
	 *
	 * @param OrderData       $order Order values.
	 * @param string          $status New status slug without "wc-".
	 * @param StatusTemplates $templates Templates of the status.
	 * @param string[]        $admin_phones Admin numbers.
	 * @return OutgoingSms[]
	 */
	public function build( OrderData $order, string $status, StatusTemplates $templates, array $admin_phones = array() ): array {
		$messages = array();
		$vars     = $order->to_vars();

		if ( $templates->customer_enabled && '' !== trim( $templates->customer ) && '' !== trim( $order->phone() ) ) {
			$text = $this->renderer->render( $templates->customer, $vars );
			if ( '' !== trim( $text ) ) {
				$messages[] = new OutgoingSms( $order->phone(), $text, LogEntry::TYPE_CUSTOMER, $status, $order->id() );
			}
		}

		if ( $templates->admin_enabled && '' !== trim( $templates->admin ) ) {
			$text = $this->renderer->render( $templates->admin, $vars );
			if ( '' !== trim( $text ) ) {
				foreach ( array_unique( $admin_phones ) as $phone ) {
					$messages[] = new OutgoingSms( $phone, $text, LogEntry::TYPE_ADMIN, $status, $order->id() );
				}
			}
		}

		return $messages;
	}
}
