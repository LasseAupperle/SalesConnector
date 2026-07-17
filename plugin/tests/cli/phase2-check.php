<?php
/**
 * Phase-2 gate (wp eval-file): OrderSource DTO dump for the seeded fixture
 * shop equals tests/fixtures/wp-env-expected.json (ids normalized to
 * product names — auto-increment ids are not stable across seeds).
 *
 * @package LaunchUp\SalesConnector
 */

$lusc_fail = static function ( $msg ) {
	fwrite( STDERR, "PHASE2 FAIL: {$msg}\n" );
	exit( 1 );
};

if ( ! get_option( 'lusc_fixture_seeded' ) ) {
	$lusc_fail( 'fixture not seeded — run seed-fixture.php first.' );
}

$lusc_tz     = wp_timezone();
$lusc_source = new \LaunchUp\SalesConnector\OrderSource();
$lusc_window_start = new DateTimeImmutable( '2026-04-01 00:00:00', $lusc_tz );
$lusc_window_end   = new DateTimeImmutable( '2026-06-30 23:59:59', $lusc_tz );

$lusc_dump = array();
foreach ( $lusc_source->ordersForWindow( $lusc_window_start, $lusc_window_end ) as $lusc_order ) {
	$lusc_items = array();
	foreach ( $lusc_order->items as $lusc_item ) {
		if ( $lusc_item->parentProductId <= 0 ) {
			$lusc_fail( 'unexpected id-0 fallback for item ' . $lusc_item->productName );
		}
		$lusc_items[] = array(
			'product'        => $lusc_item->productName,
			'quantity'       => $lusc_item->quantity,
			'lineTotalExTax' => round( $lusc_item->lineTotalExTax, 2 ),
		);
	}
	$lusc_refunds = array();
	foreach ( $lusc_order->refundLines as $lusc_refund ) {
		$lusc_refunds[] = array(
			'product'     => $lusc_refund->productName,
			'quantity'    => $lusc_refund->quantity,
			'amountExTax' => round( $lusc_refund->amountExTax, 2 ),
		);
	}
	$lusc_dump[] = array(
		'createdAt'              => $lusc_order->createdAt->format( 'Y-m-d H:i:s P' ),
		'status'                 => $lusc_order->status,
		'items'                  => $lusc_items,
		'refundLines'            => $lusc_refunds,
		'unallocatedRefundExTax' => round( $lusc_order->unallocatedRefundExTax, 2 ),
	);
}

$lusc_expected_raw = file_get_contents( __DIR__ . '/../fixtures/wp-env-expected.json' );
$lusc_expected     = json_decode( $lusc_expected_raw, true );
if ( null === $lusc_expected ) {
	$lusc_fail( 'could not parse wp-env-expected.json' );
}

$lusc_actual_json   = json_encode( $lusc_dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$lusc_expected_json = json_encode( $lusc_expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

if ( $lusc_actual_json !== $lusc_expected_json ) {
	fwrite( STDERR, "--- expected ---\n{$lusc_expected_json}\n--- actual ---\n{$lusc_actual_json}\n" );
	$lusc_fail( 'DTO dump does not match wp-env-expected.json' );
}

echo 'PHASE2 OK: DTO dump matches wp-env-expected.json (' . count( $lusc_dump ) . " orders)\n";
