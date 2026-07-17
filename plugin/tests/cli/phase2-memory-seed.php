<?php
/**
 * Phase-2 memory gate, step 1 (wp eval-file): seed 1k synthetic orders.
 *
 * Runs in its own process so seeding memory (WC creation caches) cannot
 * pollute the streaming measurement in phase2-memory-check.php.
 *
 * @package LaunchUp\SalesConnector
 */

add_filter( 'pre_wp_mail', '__return_false' );

$lusc_count = (int) get_option( 'lusc_synthetic_seeded', 0 );
if ( $lusc_count >= 1000 ) {
	echo "MEM-SEED SKIP: already seeded.\n";
	return;
}

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
	if ( 0 === $lusc_i % 250 ) {
		echo "seeded {$lusc_i}...\n";
		wp_cache_flush();
	}
}
update_option( 'lusc_synthetic_seeded', 1000 );
echo "MEM-SEED OK: 1000 synthetic orders.\n";
