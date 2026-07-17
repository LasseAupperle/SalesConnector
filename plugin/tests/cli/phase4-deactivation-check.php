<?php
/**
 * Phase-4 gate (wp eval-file): with the plugin just DEACTIVATED, no lusc
 * actions may remain scheduled (specs/02 §5 deactivation behavior).
 *
 * @package LaunchUp\SalesConnector
 */

$lusc_fail = static function ( $msg ) {
	fwrite( STDERR, "PHASE4-DEACT FAIL: {$msg}\n" );
	exit( 1 );
};

if ( is_plugin_active( 'launchup-sales-connector/launchup-sales-connector.php' ) ) {
	$lusc_fail( 'plugin is still active — deactivate it before this check.' );
}

if ( false !== as_next_scheduled_action( 'lusc_daily_push', null, 'lusc' ) ) {
	$lusc_fail( 'daily push action still scheduled after deactivation.' );
}

$lusc_pending = as_get_scheduled_actions(
	array(
		'group'    => 'lusc',
		'status'   => ActionScheduler_Store::STATUS_PENDING,
		'per_page' => 10,
	),
	'ids'
);
if ( array() !== $lusc_pending ) {
	$lusc_fail( 'pending lusc actions remain: ' . implode( ',', $lusc_pending ) );
}

echo "PHASE4-DEACT OK: no lusc actions remain after deactivation.\n";
