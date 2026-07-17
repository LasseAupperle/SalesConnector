<?php
/**
 * Phase-0 gate (wp eval-file): plugin active, HPOS compatibility declared.
 *
 * Runs inside wp-env with WooCommerce active. Exits non-zero on any failure.
 * NOTE: eval-file scripts must not declare strict_types or a namespace.
 *
 * @package LaunchUp\SalesConnector
 */

$lusc_fail = static function ( string $msg ): void {
	fwrite( STDERR, "PHASE0 FAIL: {$msg}\n" );
	exit( 1 );
};

if ( ! defined( 'LUSC_VERSION' ) ) {
	$lusc_fail( 'LUSC_VERSION not defined — plugin not loaded.' );
}

if ( ! is_plugin_active( 'launchup-sales-connector/launchup-sales-connector.php' ) ) {
	$lusc_fail( 'plugin is not active.' );
}

if ( ! class_exists( 'WooCommerce' ) ) {
	$lusc_fail( 'WooCommerce is not active — this check needs it.' );
}

// HPOS compatibility must be declared (WooCommerce → Features would list us as compatible).
$lusc_compat = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables' );
if ( ! in_array( 'launchup-sales-connector/launchup-sales-connector.php', $lusc_compat['compatible'] ?? array(), true ) ) {
	$lusc_fail( 'HPOS compatibility not declared: ' . wp_json_encode( $lusc_compat ) );
}

// HPOS must actually be the active order storage in the test environment.
if ( ! \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
	$lusc_fail( 'HPOS (custom order tables) is not enabled in this environment.' );
}

echo "PHASE0 OK: plugin active, HPOS compatible + enabled, version " . LUSC_VERSION . "\n";
