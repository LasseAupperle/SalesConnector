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
