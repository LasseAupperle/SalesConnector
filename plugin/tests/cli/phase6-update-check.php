<?php
/**
 * Phase-6 gate (wp eval-file): with the public repo carrying the
 * v0.9.0-test release, the update checker must report an available
 * update with a release-asset download URL (specs/04 §5).
 *
 * Runs on a wp-env whose mapped plugin is an older version (main).
 *
 * @package LaunchUp\SalesConnector
 */

$lusc_fail = static function ( $msg ) {
	fwrite( STDERR, "PHASE6 FAIL: {$msg}\n" );
	exit( 1 );
};

// Reuse the checker built in lusc_boot() — PUC slugs must be unique.
$lusc_checker = \LaunchUp\SalesConnector\Updater::checker();
if ( null === $lusc_checker ) {
	$lusc_fail( 'boot-time update checker not registered.' );
}

$lusc_checker->checkForUpdates();
$lusc_update = $lusc_checker->getUpdate();

if ( null === $lusc_update ) {
	$lusc_fail( 'no update visible — expected the v0.9.0-test release. Installed: ' . LUSC_VERSION );
}
if ( 0 !== strpos( (string) $lusc_update->version, '0.9.0' ) ) {
	$lusc_fail( "unexpected update version {$lusc_update->version} (expected 0.9.0*)" );
}
if ( false === strpos( (string) $lusc_update->download_url, 'launchup-sales-connector.zip' ) ) {
	$lusc_fail( "download URL is not the release asset: {$lusc_update->download_url}" );
}

echo "PHASE6 OK: update {$lusc_update->version} visible (installed " . LUSC_VERSION . "), release-asset download URL.\n";
