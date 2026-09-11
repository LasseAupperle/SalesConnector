<?php
/**
 * CatalogueSource — what the shop SELLS, as opposed to what it sold (specs/06 §2).
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector;

/**
 * Reads the shop's published products so Launch Hub can price them before their first sale.
 *
 * This exists because of the order Launch Up actually works in: contracts signed, shop built,
 * THEN somebody fills in the royalties. Without a catalogue a product only reaches Launch Hub
 * after an order arrives, so month one is always unpriced and the figures are chased backwards.
 *
 * Like OrderSource, this touches WooCommerce and therefore has no PHPUnit coverage — the pure
 * classes stay WordPress-free so the unit suite runs without WP. It is proven by the wp-env
 * gate in tests/cli/phase9-check.php instead.
 */
final class CatalogueSource {

	/**
	 * Most products one push will carry.
	 *
	 * A shop with more than this is a conversation, not something to truncate quietly — hitting
	 * the cap adds a note, and Launch Hub shows notes prominently.
	 */
	private const MAX_PRODUCTS = 500;

	/**
	 * How many products to pull per query.
	 *
	 * Read in pages rather than `limit => -1`: a shop with thousands of products would otherwise
	 * hydrate every WC_Product object at once, and this runs on shared hosting inside the same
	 * request as the push.
	 */
	private const PAGE_SIZE = 100;

	/**
	 * Notes raised while reading (currently only the cap).
	 *
	 * @var array<int, string>
	 */
	private array $notes = array();

	/**
	 * Every published parent product, newest first.
	 *
	 * @return array<int, array<string, mixed>> Catalogue entries for the payload.
	 */
	public function products(): array {
		$entries = array();
		$page    = 1;

		while ( count( $entries ) < self::MAX_PRODUCTS ) {
			$batch = wc_get_products(
				array(
					// Published only. Drafts, pending and private products are unannounced
					// designs: sending them would leak a creator's plans into a system other
					// staff read, and fill the pricing table with products that may never exist.
					'status'   => 'publish',
					// Parent-level types only, matching how orders are aggregated (specs/00 §4).
					// A variation is not a product anybody prices separately.
					'type'     => array( 'simple', 'variable' ),
					'limit'    => self::PAGE_SIZE,
					'page'     => $page,
					'orderby'  => 'date',
					'order'    => 'DESC',
					'paginate' => false,
				)
			);

			if ( empty( $batch ) ) {
				break;
			}

			foreach ( $batch as $product ) {
				if ( count( $entries ) >= self::MAX_PRODUCTS ) {
					break;
				}

				$entries[] = array(
					'external_ref' => 'wc:' . $product->get_id(),
					'name'         => $product->get_name(),
					'status'       => 'publish',
					// Context only — Launch Hub never uses this in a money calculation, because
					// what a thing is LISTED at is not what it sold for. Null rather than 0 when
					// the shop has no price set: zero is a number somebody believes.
					'price'        => $this->priceOf( $product ),
				);
			}

			if ( count( $batch ) < self::PAGE_SIZE ) {
				break;
			}

			++$page;
		}

		if ( count( $entries ) >= self::MAX_PRODUCTS ) {
			$this->notes[] = sprintf(
				'catalogue truncated at %d products; the rest were not sent',
				self::MAX_PRODUCTS
			);
		}

		return $entries;
	}

	/**
	 * Notes raised during the last read.
	 *
	 * @return array<int, string>
	 */
	public function notes(): array {
		return $this->notes;
	}

	/**
	 * The product's list price, or null when it has none.
	 *
	 * A variable product's own price is empty — its variations carry them — so the lowest
	 * variation price is used, which is what a shopper sees as "from €X".
	 *
	 * @param \WC_Product $product Product to read.
	 * @return float|null
	 */
	private function priceOf( $product ): ?float {
		$raw = $product->get_regular_price();

		if ( '' === $raw || null === $raw ) {
			$prices = method_exists( $product, 'get_variation_regular_price' )
				? $product->get_variation_regular_price( 'min' )
				: '';
			$raw    = '' === $prices ? null : $prices;
		}

		return null === $raw || '' === $raw ? null : (float) $raw;
	}
}
