<?php
/**
 * Plugin Name:       Launch Up Sales Connector
 * Plugin URI:        https://github.com/LasseAupperle/SalesConnector
 * Description:       Aggregates WooCommerce sales per month and pushes them to Launch Hub. No customer data ever leaves the shop.
 * Version:           1.2.0
 * Author:            Launch Up
 * Author URI:        https://launch-up.nl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       launchup-sales-connector
 * Domain Path:       /languages
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 11.0
 * WC tested up to:   11.1
 * Update URI:        https://github.com/LasseAupperle/SalesConnector
 *
 * @package LaunchUp\SalesConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LUSC_VERSION', '1.2.0' );
define( 'LUSC_PLUGIN_FILE', __FILE__ );

/*
 * Autoloader for LaunchUp\SalesConnector\* → includes/*. Hand-rolled because
 * composer is dev-only in this plugin (no build step on creator shops).
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'LaunchUp\\SalesConnector\\';
		$len    = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class_name, $len ) ) {
			return;
		}
		$file = __DIR__ . '/includes/' . str_replace( '\\', '/', substr( $class_name, $len ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

/*
 * HPOS (custom order tables) compatibility. Declared unconditionally on
 * before_woocommerce_init per WooCommerce guidance; the hook only fires when
 * WooCommerce is active.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Render the admin notice shown when WooCommerce is not active.
 */
function lusc_woocommerce_missing_notice(): void {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Launch Up Sales Connector requires WooCommerce to be installed and active. The plugin does nothing until WooCommerce is activated.', 'launchup-sales-connector' )
	);
}

/**
 * Boot the plugin once all plugins are loaded, or bail with a notice when
 * WooCommerce is missing (never fatal).
 */
function lusc_boot(): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'lusc_woocommerce_missing_notice' );
		return;
	}

	load_plugin_textdomain( 'launchup-sales-connector', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	( new \LaunchUp\SalesConnector\Scheduler() )->register();
	if ( is_admin() ) {
		( new \LaunchUp\SalesConnector\SettingsPage() )->register();
	}
	\LaunchUp\SalesConnector\Updater::register();
}
add_action( 'plugins_loaded', 'lusc_boot' );

/*
 * Activation: schedule the daily push (the self-healing admin_init check
 * covers the case where WooCommerce is activated later). Deactivation:
 * remove all lusc actions (specs/02 §5).
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		( new \LaunchUp\SalesConnector\Scheduler() )->ensureScheduled();
	}
);
register_deactivation_hook(
	__FILE__,
	static function (): void {
		\LaunchUp\SalesConnector\Scheduler::unscheduleAll();
	}
);
