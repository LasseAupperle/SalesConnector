<?php
/**
 * Phase-2 fixture seed (wp eval-file, inside wp-env with WooCommerce).
 *
 * Seeds the deterministic fixture shop from specs/02 §6: 1 simple product,
 * 1 variable product with 2 variations, orders across 3 months incl. a
 * partial refund and an amount-only refund, and one ignored pending order.
 * Idempotent: bails if the fixture marker option exists.
 *
 * NOTE: eval-file scripts must not declare strict_types or a namespace.
 *
 * @package LaunchUp\SalesConnector
 */

if ( get_option( 'lusc_fixture_seeded' ) ) {
	echo "SEED SKIP: fixture already seeded.\n";
	return;
}

// Shop operating assumption: Europe/Amsterdam (specs/00 §4.7).
update_option( 'timezone_string', 'Europe/Amsterdam' );

// No mail in CI.
add_filter( 'pre_wp_mail', '__return_false' );

// --- Products -------------------------------------------------------------

$lusc_mok = new WC_Product_Simple();
$lusc_mok->set_name( 'Fixture Mok' );
$lusc_mok->set_regular_price( '12.00' );
$lusc_mok->save();

$lusc_shirt = new WC_Product_Variable();
$lusc_shirt->set_name( 'Fixture Shirt' );
$lusc_attr = new WC_Product_Attribute();
$lusc_attr->set_name( 'Size' );
$lusc_attr->set_options( array( 'S', 'M' ) );
$lusc_attr->set_visible( true );
$lusc_attr->set_variation( true );
$lusc_shirt->set_attributes( array( $lusc_attr ) );
$lusc_shirt->save();

$lusc_var_s = new WC_Product_Variation();
$lusc_var_s->set_parent_id( $lusc_shirt->get_id() );
$lusc_var_s->set_attributes( array( 'size' => 'S' ) );
$lusc_var_s->set_regular_price( '20.00' );
$lusc_var_s->save();

$lusc_var_m = new WC_Product_Variation();
$lusc_var_m->set_parent_id( $lusc_shirt->get_id() );
$lusc_var_m->set_attributes( array( 'size' => 'M' ) );
$lusc_var_m->set_regular_price( '20.00' );
$lusc_var_m->save();

// --- Helper ---------------------------------------------------------------

/**
 * Create an order with product lines at a fixed shop-local datetime.
 *
 * @param string $when   'Y-m-d H:i:s' in site timezone.
 * @param string $status Target status.
 * @param array  $lines  Array of [product, qty].
 * @return WC_Order
 */
$lusc_make_order = static function ( $when, $status, array $lines ) {
	$order = wc_create_order();
	foreach ( $lines as $line ) {
		$order->add_product( $line[0], $line[1] );
	}
	$order->calculate_totals( false );
	$order->set_date_created( $when );
	$order->set_status( $status );
	$order->save();
	return $order;
};

// --- Orders ---------------------------------------------------------------

// O1 · April, completed: 2× Shirt S + 1× Mok.
$lusc_o1 = $lusc_make_order(
	'2026-04-05 10:00:00',
	'completed',
	array( array( $lusc_var_s, 2 ), array( $lusc_mok, 1 ) )
);

// O2 · May, processing: 3× Shirt M, then partial refund of 1 (€20 ex tax).
$lusc_o2 = $lusc_make_order( '2026-05-10 11:00:00', 'processing', array( array( $lusc_var_m, 3 ) ) );
$lusc_o2_items = $lusc_o2->get_items();
$lusc_o2_item_id = array_key_first( $lusc_o2_items );
wc_create_refund(
	array(
		'order_id'   => $lusc_o2->get_id(),
		'amount'     => 20.00,
		'line_items' => array(
			$lusc_o2_item_id => array(
				'qty'          => 1,
				'refund_total' => 20.00,
			),
		),
	)
);

// O3 · June, completed: 1× Mok, then amount-only refund €5 (no line items).
$lusc_o3 = $lusc_make_order( '2026-06-02 09:00:00', 'completed', array( array( $lusc_mok, 1 ) ) );
wc_create_refund(
	array(
		'order_id' => $lusc_o3->get_id(),
		'amount'   => 5.00,
	)
);

// O4 · June, pending: must be ignored by OrderSource.
$lusc_make_order( '2026-06-15 14:00:00', 'pending', array( array( $lusc_mok, 1 ) ) );

update_option( 'lusc_fixture_seeded', 1 );

printf(
	"SEED OK: mok=%d shirt=%d orders=%d,%d,%d\n",
	$lusc_mok->get_id(),
	$lusc_shirt->get_id(),
	$lusc_o1->get_id(),
	$lusc_o2->get_id(),
	$lusc_o3->get_id()
);
