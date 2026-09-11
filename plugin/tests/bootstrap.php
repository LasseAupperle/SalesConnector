<?php
/**
 * PHPUnit bootstrap for the fast, Docker-free "unit" suite.
 *
 * Loads the plugin classes via autoloader and provides just enough WordPress
 * function stubs for pure-logic classes to run without a WordPress install.
 * The aggregation engine (specs/01) imports nothing from WordPress at all;
 * stubs exist for the thin WP-touching seams tested in later phases.
 * Integration tests that need a real WordPress run inside wp-env (CI).
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

// Composer autoloader (includes + tests PSR-4). Falls back to a hand-rolled
// loader so the suite runs even before `composer install`.
$lusc_autoload = __DIR__ . '/../vendor/autoload.php';
if ( is_readable( $lusc_autoload ) ) {
	require $lusc_autoload;
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			foreach ( array(
				'LaunchUp\\SalesConnector\\Tests\\' => __DIR__ . '/',
				'LaunchUp\\SalesConnector\\'        => __DIR__ . '/../includes/',
			) as $prefix => $dir ) {
				$len = strlen( $prefix );
				if ( 0 === strncmp( $prefix, $class, $len ) ) {
					$file = $dir . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';
					if ( is_readable( $file ) ) {
						require $file;
					}
					return;
				}
			}
		}
	);
}

/*
 * -------------------------------------------------------------------------
 * Minimal WordPress stubs (in-memory option store)
 * -------------------------------------------------------------------------
 */

$GLOBALS['lusc_test_options'] = array();

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

/**
 * Reset the in-memory option store between tests.
 */
function lusc_test_reset_options(): void {
	$GLOBALS['lusc_test_options'] = array();
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * In-memory get_option stub.
	 *
	 * @param string $name          Option name.
	 * @param mixed  $default_value Default when unset.
	 * @return mixed
	 */
	function get_option( string $name, $default_value = false ) {
		return $GLOBALS['lusc_test_options'][ $name ] ?? $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * In-memory update_option stub.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value to store.
	 * @param mixed  $autoload Ignored.
	 */
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['lusc_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * In-memory delete_option stub.
	 *
	 * @param string $name Option name.
	 */
	function delete_option( string $name ): bool {
		unset( $GLOBALS['lusc_test_options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Passthrough to json_encode.
	 *
	 * @param mixed $data    Data to encode.
	 * @param int   $options json_encode flags.
	 * @param int   $depth   Max depth.
	 * @return string|false
	 */
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Identity translation stub.
	 *
	 * @param string $text   Source text.
	 * @param string $domain Text domain (ignored).
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

/*
 * -------------------------------------------------------------------------
 * HTTP + site stubs for PushClient tests. A test sets
 * $GLOBALS['lusc_http_response'] to a WP-style response array or a
 * LuscTestError; the last request lands in $GLOBALS['lusc_http_request'].
 * -------------------------------------------------------------------------
 */

/**
 * WP_Error stand-in for transport failures.
 */
final class LuscTestError {

	/**
	 * Constructor.
	 *
	 * @param string $message Error message.
	 */
	public function __construct( private string $message ) {}

	/**
	 * WP_Error-compatible accessor.
	 */
	public function get_error_message(): string {
		return $this->message;
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	/**
	 * Recording HTTP stub.
	 *
	 * @param string               $url  Target URL.
	 * @param array<string, mixed> $args Request args.
	 * @return mixed
	 */
	function wp_remote_post( string $url, array $args = array() ) {
		$GLOBALS['lusc_http_request'] = array(
			'url'  => $url,
			'args' => $args,
		);
		return $GLOBALS['lusc_http_response'] ?? array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"imported":0}',
		);
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Duck-typed is_wp_error stub.
	 *
	 * @param mixed $thing Value to test.
	 */
	function is_wp_error( $thing ): bool {
		return is_object( $thing ) && method_exists( $thing, 'get_error_message' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Response-code accessor stub.
	 *
	 * @param mixed $response WP-style response array.
	 */
	function wp_remote_retrieve_response_code( $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Response-body accessor stub.
	 *
	 * @param mixed $response WP-style response array.
	 */
	function wp_remote_retrieve_body( $response ): string {
		return (string) ( $response['body'] ?? '' );
	}
}

/*
 * -------------------------------------------------------------------------
 * Action Scheduler + WooCommerce stubs for Scheduler tests. Scheduled
 * actions land in $GLOBALS['lusc_test_actions']; wc_get_orders returns
 * $GLOBALS['lusc_test_orders'] (only for the oldest-order lookup — window
 * queries return an empty list in the unit environment).
 * -------------------------------------------------------------------------
 */

$GLOBALS['lusc_test_actions'] = array();
$GLOBALS['lusc_test_orders']  = array();

/**
 * Reset scheduled-action + order stubs between tests.
 */
function lusc_test_reset_actions(): void {
	$GLOBALS['lusc_test_actions'] = array();
	$GLOBALS['lusc_test_orders']  = array();
}

if ( ! function_exists( 'wp_timezone' ) ) {
	/**
	 * Shop timezone stub (specs/00 §4.7 operating assumption).
	 */
	function wp_timezone(): DateTimeZone {
		return new DateTimeZone( 'Europe/Amsterdam' );
	}
}

if ( ! function_exists( 'as_next_scheduled_action' ) ) {
	/**
	 * Next-scheduled lookup stub.
	 *
	 * @param string     $hook  Hook name.
	 * @param mixed      $args  Args filter (ignored).
	 * @param string     $group Group.
	 * @return int|false
	 */
	function as_next_scheduled_action( string $hook, $args = null, string $group = '' ) {
		foreach ( $GLOBALS['lusc_test_actions'] as $action ) {
			if ( $action['hook'] === $hook ) {
				return $action['timestamp'] ?? true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
	/**
	 * Recurring-action stub.
	 *
	 * @param int                  $timestamp First run.
	 * @param int                  $interval  Interval seconds.
	 * @param string               $hook      Hook name.
	 * @param array<string, mixed> $args      Args.
	 * @param string               $group     Group.
	 */
	function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = array(), string $group = '' ): int {
		$GLOBALS['lusc_test_actions'][] = compact( 'timestamp', 'interval', 'hook', 'args', 'group' ) + array( 'type' => 'recurring' );
		return count( $GLOBALS['lusc_test_actions'] );
	}
}

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	/**
	 * Single-action stub.
	 *
	 * @param int                  $timestamp Run time.
	 * @param string               $hook      Hook name.
	 * @param array<string, mixed> $args      Args.
	 * @param string               $group     Group.
	 */
	function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int {
		$GLOBALS['lusc_test_actions'][] = compact( 'timestamp', 'hook', 'args', 'group' ) + array( 'type' => 'single' );
		return count( $GLOBALS['lusc_test_actions'] );
	}
}

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	/**
	 * Async-action stub.
	 *
	 * @param string               $hook  Hook name.
	 * @param array<string, mixed> $args  Args.
	 * @param string               $group Group.
	 */
	function as_enqueue_async_action( string $hook, array $args = array(), string $group = '' ): int {
		$GLOBALS['lusc_test_actions'][] = compact( 'hook', 'args', 'group' ) + array( 'type' => 'async' );
		return count( $GLOBALS['lusc_test_actions'] );
	}
}

if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	/**
	 * Unschedule-all stub.
	 *
	 * @param string               $hook  Hook name ('' = all).
	 * @param array<string, mixed> $args  Args.
	 * @param string               $group Group.
	 */
	function as_unschedule_all_actions( string $hook = '', array $args = array(), string $group = '' ): void {
		$GLOBALS['lusc_test_actions'] = array_values(
			array_filter(
				$GLOBALS['lusc_test_actions'],
				static fn ( array $action ): bool => $action['group'] !== $group
			)
		);
	}
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	/**
	 * Order query stub: the oldest-order lookup (limit 1) gets the fake
	 * order list; window queries return nothing in the unit environment.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<int, object>
	 */
	function wc_get_orders( array $args = array() ) {
		if ( 1 === ( $args['limit'] ?? 0 ) ) {
			return $GLOBALS['lusc_test_orders'];
		}
		return array();
	}
}

if ( ! function_exists( 'wc_get_products' ) ) {
	/**
	 * Product query stub, mirroring wc_get_orders above.
	 *
	 * Returns whatever a test put in $GLOBALS['lusc_test_products'] for the FIRST page and
	 * nothing thereafter, so CatalogueSource's paging loop terminates instead of spinning.
	 * Empty by default: the Scheduler tests are about the retry ladder and the windows, and
	 * they should not grow a catalogue just because the push now carries one.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<int, object>
	 */
	function wc_get_products( array $args = array() ) {
		if ( 1 !== ( $args['page'] ?? 1 ) ) {
			return array();
		}
		return $GLOBALS['lusc_test_products'] ?? array();
	}
}

if ( ! function_exists( 'site_url' ) ) {
	/**
	 * Site URL stub.
	 */
	function site_url(): string {
		return 'https://shop.example.test';
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * Blog info stub.
	 *
	 * @param string $show Requested field.
	 */
	function get_bloginfo( string $show = '' ): string {
		return 'Test Shop';
	}
}
