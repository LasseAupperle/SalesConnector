<?php
/**
 * Phase-2 memory gate, step 2 (wp eval-file): stream the 1k synthetic
 * orders through OrderSource in a fresh process and assert peak memory
 * < 256MB (specs/02 §1 targets 10k under 256MB).
 *
 * @package LaunchUp\SalesConnector
 */

$lusc_fail = static function ( $msg ) {
	fwrite( STDERR, "PHASE2-MEM FAIL: {$msg}\n" );
	exit( 1 );
};

if ( (int) get_option( 'lusc_synthetic_seeded', 0 ) < 1000 ) {
	$lusc_fail( 'synthetic orders not seeded — run phase2-memory-seed.php first.' );
}

$lusc_tz     = wp_timezone();
$lusc_source = new \LaunchUp\SalesConnector\OrderSource();
$lusc_seen   = 0;
foreach ( $lusc_source->ordersForWindow(
	new DateTimeImmutable( '2026-01-01 00:00:00', $lusc_tz ),
	new DateTimeImmutable( '2026-03-31 23:59:59', $lusc_tz )
) as $lusc_dto ) {
	++$lusc_seen;
}

$lusc_peak_mb = memory_get_peak_usage( true ) / 1048576;

if ( $lusc_seen < 1000 ) {
	$lusc_fail( "expected >= 1000 synthetic orders iterated, got {$lusc_seen}" );
}
if ( $lusc_peak_mb >= 256 ) {
	$lusc_fail( sprintf( 'peak memory %.1fMB >= 256MB', $lusc_peak_mb ) );
}

printf( "PHASE2-MEM OK: %d orders streamed, peak memory %.1fMB (< 256MB)\n", $lusc_seen, $lusc_peak_mb );
