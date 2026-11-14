<?php
/**
 * Minimal WordPress function stubs for unit tests.
 *
 * Only what the WP layer touches is implemented. State lives in WpStubs so
 * tests can reset and inspect it. Variadic forwarding uses positional
 * arrays only (string keys break argument unpacking on PHP 7.4).
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

// phpcs:ignoreFile

final class WpStubs {
	/** @var array<string,array<int,array<int,callable>>> */
	public static $filters = array();
	/** @var array<string,mixed> */
	public static $options = array();
	/** @var array<string,mixed> */
	public static $transients = array();
	/** @var bool */
	public static $can = true;
	/** @var array<int,array{0:string,1:array<int,mixed>}> */
	public static $actions = array();
	/** @var array<int,array{0:string,1:array<string,mixed>}> */
	public static $http_requests = array();
	/** @var array<int,mixed> Queued wp_remote_request() results. */
	public static $http_responses = array();
	/** @var array<int,array{0:string,1:array<int,mixed>,2:string}> */
	public static $async = array();

	public static function reset(): void {
		self::$filters        = array();
		self::$options        = array();
		self::$transients     = array();
		self::$can            = true;
		self::$actions        = array();
		self::$http_requests  = array();
		self::$http_responses = array();
		self::$async          = array();
	}
}

final class WpDieException extends RuntimeException {
}

class WP_Error {
	/** @var string */
	private $message;
	/** @var array<string,string> */
	public $errors = array();

	public function __construct( $code = '', $message = '' ) {
		$this->message = (string) $message;
		if ( '' !== $code ) {
			$this->errors[ (string) $code ] = (string) $message;
		}
	}

	public function get_error_message() {
		return $this->message;
	}

	public function add( $code, $message ) {
		$this->errors[ (string) $code ] = (string) $message;
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}

function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	WpStubs::$filters[ $name ][ $priority ][] = $callback;
	return true;
}

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $name, $callback, $priority, $accepted_args );
}

function apply_filters( $name, $value, ...$args ) {
	if ( empty( WpStubs::$filters[ $name ] ) ) {
		return $value;
	}
	ksort( WpStubs::$filters[ $name ] );
	foreach ( WpStubs::$filters[ $name ] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = call_user_func_array( $callback, array_merge( array( $value ), array_values( $args ) ) );
		}
	}
	return $value;
}

function do_action( $name, ...$args ) {
	WpStubs::$actions[] = array( $name, array_values( $args ) );
	if ( empty( WpStubs::$filters[ $name ] ) ) {
		return;
	}
	foreach ( WpStubs::$filters[ $name ] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			call_user_func_array( $callback, array_values( $args ) );
		}
	}
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	return 1 === (int) $number ? $single : $plural;
}

function esc_html__( $text, $domain = 'default' ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_url( $url ) {
	return (string) $url;
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, WpStubs::$options ) ? WpStubs::$options[ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	WpStubs::$options[ $name ] = $value;
	return true;
}

function get_transient( $name ) {
	return WpStubs::$transients[ $name ] ?? false;
}

function set_transient( $name, $value, $ttl = 0 ) {
	WpStubs::$transients[ $name ] = $value;
	return true;
}

function delete_transient( $name ) {
	unset( WpStubs::$transients[ $name ] );
	return true;
}

function current_user_can( $capability ) {
	return WpStubs::$can;
}

function get_current_user_id() {
	return 1;
}

function wp_salt( $scheme = 'auth' ) {
	return 'unit-test-salt-' . $scheme;
}

function home_url( $path = '' ) {
	return 'https://shop.test' . $path;
}

function admin_url( $path = '' ) {
	return 'https://shop.test/wp-admin/' . ltrim( $path, '/' );
}

function get_bloginfo( $show = '' ) {
	return 'name' === $show ? 'Dhaka Shop &amp; Co' : '';
}

function wp_specialchars_decode( $text, $quote_style = ENT_NOQUOTES ) {
	return htmlspecialchars_decode( (string) $text, ENT_QUOTES );
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function absint( $value ) {
	return abs( (int) $value );
}

function is_admin() {
	return false;
}

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_remote_request( $url, $args = array() ) {
	WpStubs::$http_requests[] = array( $url, $args );
	return array_shift( WpStubs::$http_responses ) ?? array(
		'response' => array( 'code' => 200 ),
		'headers'  => array(),
		'body'     => '',
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? 0;
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? '';
}

function wp_remote_retrieve_headers( $response ) {
	return $response['headers'] ?? array();
}

function wp_date( $format, $timestamp = null ) {
	return gmdate( $format, $timestamp ?? 1762956000 );
}

function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
	WpStubs::$async[] = array( $hook, $args, $group );
	return count( WpStubs::$async );
}

function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
	WpStubs::$async[] = array( $hook, $args, $group, $timestamp );
	return count( WpStubs::$async );
}
function __return_false() { return false; }

final class WpJsonResponse extends RuntimeException {
	/** @var bool */
	public $success;
	/** @var mixed */
	public $data;
	/** @var int|null */
	public $status;

	public function __construct( bool $success, $data, $status ) {
		parent::__construct( 'json' );
		$this->success = $success;
		$this->data    = $data;
		$this->status  = $status;
	}
}

function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
	if ( empty( $_REQUEST[ $query_arg ] ) || 'valid-nonce' !== $_REQUEST[ $query_arg ] ) {
		throw new WpDieException( 'bad nonce' );
	}
	return 1;
}

function wp_send_json_success( $data = null, $status = null ) {
	throw new WpJsonResponse( true, $data, $status );
}

function wp_send_json_error( $data = null, $status = null ) {
	throw new WpJsonResponse( false, $data, $status );
}

function sanitize_text_field( $text ) {
	return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) );
}

function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	return substr( bin2hex( random_bytes( 32 ) ), 0, $length );
}
