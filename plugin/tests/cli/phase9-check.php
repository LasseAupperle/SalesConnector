<?php
/**
 * Phase-9 gate (wp eval-file): the catalogue sends the published products and nothing else.
 *
 * Runs inside wp-env with WooCommerce active. Exits non-zero on any failure.
 * NOTE: eval-file scripts must not declare strict_types or a namespace.
 *
 * Creates its own products rather than leaning on the fixture shop: the statuses are the whole
 * point here, and a fixture built for order aggregation has no reason to contain a draft.
 *
 * @package LaunchUp\SalesConnector
 */

use LaunchUp\SalesConnector\CatalogueSource;

$lusc_fail = static function ( string $msg ): void {
	fwrite( STDERR, "PHASE9 FAIL: {$msg}\n" );
	exit( 1 );
};

$lusc_assert = static function ( bool $ok, string $msg ) use ( $lusc_fail ): void {
	if ( ! $ok ) {
		$lusc_fail( $msg );
	}
};

if ( ! class_exists( 'WooCommerce' ) ) {
	$lusc_fail( 'WooCommerce is not active — this check needs it.' );
}

/**
 * Make one simple product.
 *
 * @param string $name   Product name.
 * @param string $status publish|draft|private|pending.
 * @param string $price  Regular price.
 * @return int Product id.
 */
$lusc_make = static function ( string $name, string $status, string $price = '19.95' ): int {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_status( $status );
	$product->set_regular_price( $price );
	return (int) $product->save();
};

// ── a shop with one of each status, plus a variable product ──────────────────
$lusc_published = $lusc_make( 'P9 Published hoodie', 'publish', '34.95' );
$lusc_draft     = $lusc_make( 'P9 Draft hoodie', 'draft' );
$lusc_private   = $lusc_make( 'P9 Private hoodie', 'private' );
$lusc_pending   = $lusc_make( 'P9 Pending hoodie', 'pending' );

$lusc_variable = new WC_Product_Variable();
$lusc_variable->set_name( 'P9 Variable cap' );
$lusc_variable->set_status( 'publish' );
$lusc_variable_id = (int) $lusc_variable->save();

$lusc_variation = new WC_Product_Variation();
$lusc_variation->set_parent_id( $lusc_variable_id );
$lusc_variation->set_regular_price( '12.50' );
$lusc_variation->save();

$lusc_entries = ( new CatalogueSource() )->products();
$lusc_refs    = array_column( $lusc_entries, 'external_ref' );

// ── only what the shop publishes leaves the shop ─────────────────────────────
//
// Drafts, private and pending products are unannounced designs. Sending them would leak a
// creator's plans into a system other staff read, and clutter the pricing table with products
// that may never exist.
$lusc_assert(
	in_array( 'wc:' . $lusc_published, $lusc_refs, true ),
	'a published product must be in the catalogue'
);
foreach ( array( 'draft' => $lusc_draft, 'private' => $lusc_private, 'pending' => $lusc_pending ) as $lusc_kind => $lusc_id ) {
	$lusc_assert(
		! in_array( 'wc:' . $lusc_id, $lusc_refs, true ),
		"a {$lusc_kind} product must NOT be in the catalogue"
	);
}

// ── a variable product appears ONCE, as its parent ───────────────────────────
//
// The same rule order aggregation follows: a variation is not something anybody prices
// separately, so sizes and colours of one design share one royalty.
$lusc_variable_rows = array_filter(
	$lusc_refs,
	static function ( string $ref ) use ( $lusc_variable_id ): bool {
		return 'wc:' . $lusc_variable_id === $ref;
	}
);
$lusc_assert( 1 === count( $lusc_variable_rows ), 'a variable product must appear exactly once' );

$lusc_variation_leaked = array_filter(
	$lusc_entries,
	static function ( array $entry ): bool {
		return str_contains( (string) $entry['name'], 'Variation' );
	}
);
$lusc_assert( array() === $lusc_variation_leaked, 'variations must not appear as their own products' );

// ── the shape Launch Hub expects ─────────────────────────────────────────────
$lusc_first = null;
foreach ( $lusc_entries as $lusc_entry ) {
	if ( 'wc:' . $lusc_published === $lusc_entry['external_ref'] ) {
		$lusc_first = $lusc_entry;
	}
}
$lusc_assert( null !== $lusc_first, 'could not find the published product back' );
$lusc_assert( 'publish' === $lusc_first['status'], 'status must be publish' );
$lusc_assert( 'P9 Published hoodie' === $lusc_first['name'], 'name must survive' );
$lusc_assert( 34.95 === $lusc_first['price'], 'price must be the regular price, as a float' );

// ── the payload carries it, and omits it when there is nothing ───────────────
$lusc_payload = \LaunchUp\SalesConnector\PayloadBuilder::build( 'https://shop.test', 'Shop', 'lu_sk_x', array(), $lusc_entries );
$lusc_assert( isset( $lusc_payload['catalogue'] ), 'payload must carry the catalogue' );
$lusc_assert( '1.2' === $lusc_payload['contract'], 'catalogue requires contract 1.2' );

$lusc_bare = \LaunchUp\SalesConnector\PayloadBuilder::build( 'https://shop.test', 'Shop', 'lu_sk_x', array() );
// Omitted, not empty: Launch Hub treats a catalogue-bearing payload as a real push rather than a
// connectivity test, so "catalogue": [] from the Test button would turn a probe into an import.
$lusc_assert( ! isset( $lusc_bare['catalogue'] ), 'an empty catalogue must be OMITTED, not sent empty' );

echo "PHASE9 OK: published only, variations rolled up, payload shaped\n";
