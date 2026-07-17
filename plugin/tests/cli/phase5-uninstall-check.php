<?php
/**
 * Phase-5 gate (wp eval-file): after `wp plugin uninstall --skip-delete`,
 * no lusc_* options and no scheduled lusc actions remain (specs/03 §4).
 *
 * @package LaunchUp\SalesConnector
 */

$lusc_fail = static function ( $msg ) {
	fwrite( STDERR, "PHASE5-UNINSTALL FAIL: {$msg}\n" );
	exit( 1 );
};

foreach ( array(
	'lusc_settings',
	'lusc_status',
	'lusc_first_test_ok',
	'lusc_backfill_started',
	'lusc_backfill_notice_dismissed',
	'lusc_failure_notice_dismissed',
) as $lusc_option ) {
	if ( false !== get_option( $lusc_option ) ) {
		$lusc_fail( "option {$lusc_option} still exists after uninstall" );
	}
}

if ( function_exists( 'as_next_scheduled_action' )
	&& false !== as_next_scheduled_action( 'lusc_daily_push', null, 'lusc' ) ) {
	$lusc_fail( 'daily action still scheduled after uninstall' );
}

echo "PHASE5-UNINSTALL OK: no lusc_* options or scheduled actions remain.\n";
