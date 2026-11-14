<?php
declare(strict_types=1);

namespace Saizul\BdSms\Tests;

use PHPUnit\Framework\TestCase;
use Saizul\BdSms\Http\FakeHttpClient;
use Saizul\BdSms\Log\InMemoryLogRepository;
use Saizul\BdSms\Retry\InMemoryRetryScheduler;
use Saizul\BdSms\Support\ArrayStore;
use Saizul\BdSms\Support\FrozenClock;
use Saizul\BdSms\WP\Services;
use Saizul\BdSms\WP\Settings;
use WC_Order;
use WcStubs;
use WP_CLI;
use WpStubs;

/**
 * Base class for tests of the WordPress layer (running on stubs).
 */
abstract class WpTestCase extends TestCase {

	/** @var FakeHttpClient */
	protected $http;

	/** @var InMemoryLogRepository */
	protected $log;

	/** @var InMemoryRetryScheduler */
	protected $scheduler;

	/** @var FrozenClock */
	protected $clock;

	/** @var array<string,string> */
	protected $constants = array();

	protected function setUp(): void {
		WpStubs::reset();
		WcStubs::reset();
		WP_CLI::reset();
		$this->http      = new FakeHttpClient();
		$this->log       = new InMemoryLogRepository();
		$this->scheduler = new InMemoryRetryScheduler();
		$this->clock     = new FrozenClock( 1762956000 );
		$this->constants = array();
	}

	/**
	 * Store settings in the option.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	protected function settings_option( array $settings ): void {
		WpStubs::$options[ Settings::OPTION ] = $settings;
	}

	protected function settings(): Settings {
		$constants = &$this->constants;

		return new Settings(
			Services::registry(),
			static function ( string $name ) use ( &$constants ): ?string {
				return $constants[ $name ] ?? null;
			}
		);
	}

	protected function services(): Services {
		return new Services( $this->settings(), $this->http, $this->log, new ArrayStore( $this->clock ), $this->scheduler, $this->clock );
	}

	/**
	 * Create and register a fake order.
	 *
	 * @param array<string,mixed> $overrides Data overrides.
	 */
	protected function order( array $overrides = array() ): WC_Order {
		$order = new WC_Order(
			array_merge(
				array(
					'id'                   => 1024,
					'number'               => '1024',
					'status'               => 'processing',
					'date_created'         => '2026-11-05 10:00:00',
					'billing_first_name'   => 'Karim',
					'billing_last_name'    => 'Ahmed',
					'billing_phone'        => '+880 1711-234567',
					'billing_email'        => 'karim@example.com',
					'billing_city'         => 'Dhaka',
					'total'                => '1250.00',
					'currency'             => 'BDT',
					'payment_method'       => 'cod',
					'payment_method_title' => 'Cash on delivery',
					'shipping_method'      => 'Inside Dhaka',
					'customer_note'        => '<b>Call</b> first',
					'items'                => array(
						array(
							'name'     => 'Saree',
							'quantity' => 1,
						),
						array(
							'name'     => 'Gift wrap',
							'quantity' => 2,
						),
					),
				),
				$overrides
			)
		);
		WcStubs::$orders[ $order->get_id() ] = $order;

		return $order;
	}
}
