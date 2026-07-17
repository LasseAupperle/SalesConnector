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

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$lusc_fail = static function ( $msg ) {
	fwrite( STDERR, "PHASE6 FAIL: {$msg}\n" );
	exit( 1 );
};

require_once WP_PLUGIN_DIR . '/launchup-sales-connector/lib/plugin-update-checker/plugin-update-checker.php';

$lusc_checker = PucFactory::buildUpdateChecker(
	\LaunchUp\SalesConnector\Updater::REPO_URL,
	WP_PLUGIN_DIR . '/launchup-sales-connector/launchup-sales-connector.php',
	'launchup-sales-connector'
);
$lusc_checker->getVcsApi()->enableReleaseAssets();

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
