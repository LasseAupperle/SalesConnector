<?php
/**
 * Uninstall — remove every trace from THIS shop (specs/03 §4).
 *
 * Deletes lusc_* options and unschedules all Action Scheduler actions in
 * group 'lusc'. Never contacts Launch Hub and never removes remote data:
 * revoking the key is a conscious action in Launch Hub, not a side effect
 * of uninstalling.
 *
 * @package LaunchUp\SalesConnector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$lusc_options = array(
	'lusc_settings',
	'lusc_status',
	'lusc_first_test_ok',
	'lusc_backfill_started',
	'lusc_backfill_notice_dismissed',
	'lusc_failure_notice_dismissed',
);
foreach ( $lusc_options as $lusc_option ) {
	delete_option( $lusc_option );
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'lusc' );
}
