<?php
/**
 * Phase-2 gate (wp eval-file): memory sanity on a 1k-order synthetic seed.
 * The generator + paging keep peak memory far under the 256MB target
 * (specs/02 §1 targets 10k under 256MB; CI seeds 1k for time reasons and
 * asserts the same ceiling).
 *
 * @package LaunchUp\SalesConnector
 */

$lusc_fail = static function ( $msg ) {
	fwrite( STDERR, "PHASE2-MEM FAIL: {$msg}\n" );
	exit( 1 );
};

add_filter( 'pre_wp_mail', '__return_false' );

$lusc_count = (int) get_option( 'lusc_synthetic_seeded', 0 );
if ( $lusc_count < 1000 ) {
	$lusc_product = new WC_Product_Simple();
	$lusc_product->set_name( 'Synthetic Product' );
	$lusc_product->set_regular_price( '10.00' );
	$lusc_product->save();

	for ( $lusc_i = $lusc_count; $lusc_i < 1000; $lusc_i++ ) {
		$lusc_order = wc_create_order();
		$lusc_order->add_product( $lusc_product, 1 + ( $lusc_i % 3 ) );
		$lusc_order->calculate_totals( false );
		$lusc_order->set_date_created( sprintf( '2026-%02d-%02d 12:00:00', 1 + ( $lusc_i % 3 ), 1 + ( $lusc_i % 28 ) ) );
		$lusc_order->set_status( 'completed' );
		$lusc_order->save();
		if ( 0 === $lusc_i % 200 ) {
			echo "seeded {$lusc_i}...\n";
		}
	}
	update_option( 'lusc_synthetic_seeded', 1000 );
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
