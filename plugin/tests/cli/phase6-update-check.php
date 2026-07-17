<?php
/**
 * Phase-6 gate (wp eval-file): with the public repo carrying the
 * v0.9.0-test release, the INSTALLED plugin (an older release zip) must
 * surface that update through WordPress's own update system — the same
 * transient the Plugins screen and `wp plugin update` read.
 *
 * Version-agnostic on purpose: it only uses WP core APIs and the PUC
 * cron hook, so it works against any installed plugin version.
 *
 * @package LaunchUp\SalesConnector
 */

$lusc_fail = static function ( $msg ) {
	fwrite( STDERR, "PHASE6 FAIL: {$msg}\n" );
	exit( 1 );
};

$lusc_basename = 'launchup-sales-connector/launchup-sales-connector.php';
if ( ! is_plugin_active( $lusc_basename ) ) {
	$lusc_fail( 'plugin not active.' );
}

// Force a fresh WP update check, then a synchronous PUC check (the cron
// hook PUC v5 registers per slug), then read the transient PUC filters.
delete_site_transient( 'update_plugins' );
wp_update_plugins();
do_action( 'puc_cron_check_updates-launchup-sales-connector' );

$lusc_transient = get_site_transient( 'update_plugins' );
if ( ! is_object( $lusc_transient ) || empty( $lusc_transient->response[ $lusc_basename ] ) ) {
	$lusc_fail( 'no update visible in the update_plugins transient — expected the v0.9.0-test release.' );
}

$lusc_update = $lusc_transient->response[ $lusc_basename ];
if ( 0 !== strpos( (string) $lusc_update->new_version, '0.9.0' ) ) {
	$lusc_fail( "unexpected update version {$lusc_update->new_version} (expected 0.9.0*)" );
}
if ( false === strpos( (string) $lusc_update->package, 'launchup-sales-connector.zip' ) ) {
	$lusc_fail( "package URL is not the release asset: {$lusc_update->package}" );
}

$lusc_installed = get_plugin_data( WP_PLUGIN_DIR . '/' . $lusc_basename, false, false )['Version'];
echo "PHASE6 OK: update {$lusc_update->new_version} visible (installed {$lusc_installed}), release-asset package URL.\n";
