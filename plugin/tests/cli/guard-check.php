<?php
/**
 * Phase-0 gate (wp eval-file): with WooCommerce DEACTIVATED, the plugin loads
 * without a fatal and shows the guard notice instead.
 *
 * The fact that this script executes at all (WP fully booted with our plugin
 * active and WooCommerce off) already proves the no-fatal requirement.
 *
 * @package LaunchUp\SalesConnector
 */

$lusc_fail = static function ( string $msg ): void {
	fwrite( STDERR, "GUARD FAIL: {$msg}\n" );
	exit( 1 );
};

if ( class_exists( 'WooCommerce' ) ) {
	$lusc_fail( 'WooCommerce is still active — deactivate it before this check.' );
}

if ( ! defined( 'LUSC_VERSION' ) ) {
	$lusc_fail( 'LUSC_VERSION not defined — plugin not loaded.' );
}

ob_start();
do_action( 'admin_notices' );
$lusc_notices = (string) ob_get_clean();

if ( false === strpos( $lusc_notices, 'requires WooCommerce' ) ) {
	$lusc_fail( 'guard notice not rendered. admin_notices output: ' . $lusc_notices );
}

echo "GUARD OK: no fatal without WooCommerce, notice rendered.\n";
