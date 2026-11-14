<?php
/**
 * Service wiring.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\WP;

use Saizul\BdSms\Gateway\Gateway;
use Saizul\BdSms\Gateway\GatewayRegistry;
use Saizul\BdSms\Http\HttpClient;
use Saizul\BdSms\Log\LogRepository;
use Saizul\BdSms\Otp\OtpConfig;
use Saizul\BdSms\Otp\OtpManager;
use Saizul\BdSms\Rate\RateLimiter;
use Saizul\BdSms\Retry\RetryPolicy;
use Saizul\BdSms\Retry\RetryScheduler;
use Saizul\BdSms\Sms\SenderOptions;
use Saizul\BdSms\Sms\SmsSender;
use Saizul\BdSms\Support\Clock;
use Saizul\BdSms\Support\KeyValueStore;
use Saizul\BdSms\Support\SystemClock;
use Saizul\BdSms\WP\Db\DbLogRepository;
use Saizul\BdSms\WP\Http\WpHttpClient;
use Saizul\BdSms\WP\Queue\WpRetryScheduler;
use Saizul\BdSms\WP\Store\TransientStore;

/**
 * Builds the core services from the settings. Every dependency can be
 * replaced, which the tests and the CLI dry-run mode use.
 */
final class Services {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	public $settings;

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	public $http;

	/**
	 * Log.
	 *
	 * @var LogRepository
	 */
	public $log;

	/**
	 * Store for counters and OTP records.
	 *
	 * @var KeyValueStore
	 */
	public $store;

	/**
	 * Retry scheduler.
	 *
	 * @var RetryScheduler
	 */
	public $scheduler;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	public $clock;

	/**
	 * Constructor.
	 *
	 * @param Settings            $settings Settings.
	 * @param HttpClient|null     $http HTTP client.
	 * @param LogRepository|null  $log Log.
	 * @param KeyValueStore|null  $store Store.
	 * @param RetryScheduler|null $scheduler Scheduler.
	 * @param Clock|null          $clock Clock.
	 */
	public function __construct( Settings $settings, ?HttpClient $http = null, ?LogRepository $log = null, ?KeyValueStore $store = null, ?RetryScheduler $scheduler = null, ?Clock $clock = null ) {
		$this->settings  = $settings;
		$this->http      = $http ?? new WpHttpClient();
		$this->log       = $log ?? new DbLogRepository();
		$this->store     = $store ?? new TransientStore();
		$this->scheduler = $scheduler ?? new WpRetryScheduler();
		$this->clock     = $clock ?? new SystemClock();
	}

	/**
	 * Registry including gateways added with the `woo_bd_sms_gateways` filter.
	 */
	public static function registry(): GatewayRegistry {
		/**
		 * Filters the available gateways.
		 *
		 * @param array<string,string> $classes Gateway id => class extending AbstractGateway.
		 */
		$classes = apply_filters( 'woo_bd_sms_gateways', GatewayRegistry::defaults() );

		return new GatewayRegistry( is_array( $classes ) ? $classes : GatewayRegistry::defaults() );
	}

	/**
	 * The active gateway.
	 *
	 * @param string|null $id Gateway id; the configured one when null.
	 */
	public function gateway( ?string $id = null ): Gateway {
		$id = $id ?? $this->settings->gateway_id();

		return $this->settings->registry()->create( $id, $this->http, $this->settings->gateway_config( $id ) );
	}

	/**
	 * Sending pipeline.
	 *
	 * @param Gateway|null $gateway Gateway override.
	 */
	public function sender( ?Gateway $gateway = null ): SmsSender {
		$all   = $this->settings->all();
		$retry = (array) $all['retry'];

		return new SmsSender(
			$gateway ?? $this->gateway(),
			$this->log,
			new RateLimiter( $this->store, $this->clock ),
			new RetryPolicy( (int) ( $retry['max_attempts'] ?? 3 ), (int) ( $retry['base_delay'] ?? 60 ) ),
			$this->scheduler,
			$this->clock,
			SenderOptions::from_settings( $all )
		);
	}

	/**
	 * OTP manager.
	 */
	public function otp(): OtpManager {
		return new OtpManager( $this->store, $this->clock, $this->settings->otp_secret(), OtpConfig::from_array( (array) $this->settings->get( 'otp' ) ) );
	}
}
