<?php
/**
 * Phase-4 gate (wp eval-file): scheduler + backfill against the REAL
 * Action Scheduler inside wp-env (fixture + synthetic orders seeded by
 * the phase-2 steps).
 *
 * Uses a pinned clock (2026-07-15 10:00 shop time) so expectations stay
 * deterministic regardless of when CI runs.
 *
 * @package LaunchUp\SalesConnector
 */

use LaunchUp\SalesConnector\Scheduler;
use LaunchUp\SalesConnector\StatusStore;

$lusc_fail = static function ( $msg ) {
	fwrite( STDERR, "PHASE4 FAIL: {$msg}\n" );
	exit( 1 );
};
$lusc_assert = static function ( $cond, $msg ) use ( $lusc_fail ) {
	if ( ! $cond ) {
		$lusc_fail( $msg );
	}
};

if ( ! get_option( 'lusc_fixture_seeded' ) || (int) get_option( 'lusc_synthetic_seeded', 0 ) < 1000 ) {
	$lusc_fail( 'fixture/synthetic seeds missing — phase-2 steps must run first.' );
}

$lusc_tz     = wp_timezone();
$lusc_pinned = ( new DateTimeImmutable( '2026-07-15 10:00:00', $lusc_tz ) )->getTimestamp();
$lusc_clock  = static function () use ( $lusc_pinned ) {
	return $lusc_pinned;
};

update_option(
	'lusc_settings',
	array(
		'ingest_url' => 'https://hub.mock.test/functions/v1/sales-ingest',
		'api_key'    => 'lu_sk_PHASE4TESTKEY0000042',
	)
);
delete_option( 'lusc_status' );

// Mock the ingest endpoint at the WP HTTP boundary (as in phase 3).
$GLOBALS['lusc_mock_reply'] = null;
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		$GLOBALS['lusc_mock_requests'][] = json_decode( (string) $args['body'], true );
		return $GLOBALS['lusc_mock_reply'];
	},
	10,
	3
);
$lusc_ok_reply = array(
	'headers'  => array(),
	'response' => array(
		'code'    => 200,
		'message' => '',
	),
	'body'     => '{"imported":1}',
	'cookies'  => array(),
);

// --- 1 · Daily action scheduled at the next 04:00 shop time ---------------

Scheduler::unscheduleAll();
$lusc_scheduler = new Scheduler( $lusc_clock );
$lusc_scheduler->ensureScheduled();

$lusc_next = as_next_scheduled_action( Scheduler::HOOK_DAILY, null, Scheduler::GROUP );
$lusc_expected_next = ( new DateTimeImmutable( '2026-07-16 04:00:00', $lusc_tz ) )->getTimestamp();
$lusc_assert( $lusc_next === $lusc_expected_next, "daily next-run {$lusc_next} !== expected 04:00 shop time {$lusc_expected_next}" );

// Self-healing: a second call must not double-schedule.
$lusc_scheduler->ensureScheduled();
$lusc_pending_daily = as_get_scheduled_actions(
	array(
		'hook'     => Scheduler::HOOK_DAILY,
		'group'    => Scheduler::GROUP,
		'status'   => ActionScheduler_Store::STATUS_PENDING,
		'per_page' => 10,
	),
	'ids'
);
$lusc_assert( 1 === count( $lusc_pending_daily ), 'daily action double-scheduled' );

// --- 2 · Double-running one window yields byte-identical payloads ---------

$lusc_build = static function () use ( $lusc_tz ) {
	$source     = new \LaunchUp\SalesConnector\OrderSource();
	$aggregates = ( new \LaunchUp\SalesConnector\PeriodAggregator() )->aggregate(
		$source->ordersForWindow(
			new DateTimeImmutable( '2026-04-01 00:00:00', $lusc_tz ),
			new DateTimeImmutable( '2026-07-15 10:00:00', $lusc_tz )
		)
	);
	return wp_json_encode( \LaunchUp\SalesConnector\PayloadBuilder::build( site_url(), get_bloginfo( 'name' ), 'lu_sk_PHASE4TESTKEY0000042', $aggregates ) );
};
$lusc_first  = $lusc_build();
$lusc_second = $lusc_build();
$lusc_assert( $lusc_first === $lusc_second, 'double-running the same window produced different payloads' );
$lusc_assert( 3 === count( json_decode( $lusc_first, true )['periods'] ), 'fixture window must contain exactly 3 periods' );

// --- 3 · Retry ladder at +5m/+30m/+2h, then stop --------------------------

$GLOBALS['lusc_mock_reply'] = new WP_Error( 'http_request_failed', 'connection refused' );

$lusc_scheduler->handleDaily();
$lusc_next_retry = as_next_scheduled_action( Scheduler::HOOK_RETRY, null, Scheduler::GROUP );
$lusc_assert( $lusc_next_retry === $lusc_pinned + 300, 'first retry must be at +5 min' );
as_unschedule_all_actions( Scheduler::HOOK_RETRY );

$lusc_scheduler->handleRetry( 'daily', 2 );
$lusc_assert( as_next_scheduled_action( Scheduler::HOOK_RETRY, null, Scheduler::GROUP ) === $lusc_pinned + 1800, 'second retry must be at +30 min' );
as_unschedule_all_actions( Scheduler::HOOK_RETRY );

$lusc_scheduler->handleRetry( 'daily', 3 );
$lusc_assert( as_next_scheduled_action( Scheduler::HOOK_RETRY, null, Scheduler::GROUP ) === $lusc_pinned + 7200, 'third retry must be at +2 h' );
as_unschedule_all_actions( Scheduler::HOOK_RETRY );

$lusc_scheduler->handleRetry( 'daily', 4 );
$lusc_assert( false === as_next_scheduled_action( Scheduler::HOOK_RETRY, null, Scheduler::GROUP ), 'ladder must stop after the 3rd retry' );

$lusc_store  = new StatusStore();
$lusc_status = $lusc_store->read();
$lusc_assert( 4 === $lusc_status['consecutive_failures'], "consecutive_failures should be 4, got {$lusc_status['consecutive_failures']}" );

$GLOBALS['lusc_mock_reply'] = $lusc_ok_reply;
$lusc_scheduler->handleDaily();
$lusc_assert( 0 === $lusc_store->read()['consecutive_failures'], 'success must reset consecutive_failures' );

// --- 4 · Backfill: oldest → window chunks, then stops ---------------------

// Oldest counted order overall = synthetic 2026-01 → single clamped chunk
// [2026-01, 2026-05) with 4 periods (Jan/Feb/Mar synthetic + Apr fixture).
as_unschedule_all_actions( Scheduler::HOOK_PUSH_NOW );
$GLOBALS['lusc_mock_requests'] = array();

$lusc_scheduler->handleBackfillChunk( 0, 6 );

$lusc_entry = $lusc_store->read()['log'][0];
$lusc_assert( 'backfill' === $lusc_entry['kind'], 'backfill attempt not logged' );
$lusc_assert( '2026-01…2026-04' === $lusc_entry['window'], "backfill window wrong: {$lusc_entry['window']}" );
$lusc_assert( 4 === $lusc_entry['periods'], "backfill must push 4 periods, got {$lusc_entry['periods']}" );

$lusc_sent_periods = array_column( end( $GLOBALS['lusc_mock_requests'] )['periods'], 'period_start' );
$lusc_assert(
	array( '2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01' ) === $lusc_sent_periods,
	'backfill payload months wrong: ' . implode( ',', $lusc_sent_periods )
);

$lusc_assert( false === as_next_scheduled_action( Scheduler::HOOK_BACKFILL, null, Scheduler::GROUP ), 'no further chunk after reaching the rolling window' );
$lusc_assert( false !== as_next_scheduled_action( Scheduler::HOOK_PUSH_NOW, null, Scheduler::GROUP ), 'chain completion must enqueue a rolling-window push' );

// Cleanup: leave a clean real-clock daily schedule behind.
Scheduler::unscheduleAll();
( new Scheduler() )->ensureScheduled();

echo "PHASE4 OK: daily @04:00, idempotent window payloads, retry ladder +5m/+30m/+2h with stop+reset, backfill chunking + final rolling push.\n";
