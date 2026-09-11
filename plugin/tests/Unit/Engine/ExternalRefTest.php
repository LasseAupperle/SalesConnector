<?php
/**
 * Gate P8 (specs/06 §6) — the shop's own product id reaches the payload.
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector\Tests\Unit\Engine;

use LaunchUp\SalesConnector\Dto\LineItem;
use LaunchUp\SalesConnector\Dto\OrderData;
use LaunchUp\SalesConnector\PeriodAggregator;
use PHPUnit\Framework\TestCase;

/**
 * Identity is the point of this phase.
 *
 * Launch Hub keys a royalty on `external_ref`, so a product that changes its id — or shares one
 * with a second entry in the same month — stops being the thing somebody agreed a rate for.
 */
final class ExternalRefTest extends TestCase {

	/**
	 * One order in one month, built from the given line items.
	 *
	 * @param LineItem ...$items Lines to put on the order.
	 * @return array<int, OrderData>
	 */
	private function orders( LineItem ...$items ): array {
		return array(
			new OrderData(
				new \DateTimeImmutable( '2026-05-04 12:00:00' ),
				'completed',
				$items,
				array(),
				0.0
			),
		);
	}

	/**
	 * Variations roll up to the parent, so one design is one royalty.
	 */
	public function test_variations_report_under_their_parent_id(): void {
		// OrderSource resolves a variation to its parent before this point, so both lines arrive
		// carrying the SAME parent id — a small and a large of one hoodie.
		$payload = ( new PeriodAggregator() )->aggregate(
			$this->orders(
				new LineItem( 100, 'Hoodie zwart', 2, 60.0 ),
				new LineItem( 100, 'Hoodie zwart', 1, 30.0 )
			)
		)['2026-05']->toPayload();

		$this->assertCount( 1, $payload['products'], 'a variation must not become its own product' );
		$this->assertSame( 'wc:100', $payload['products'][0]['external_ref'] );
		$this->assertSame( 3, $payload['products'][0]['quantity'] );
		$this->assertSame( 90.0, $payload['products'][0]['revenue'] );
	}

	/**
	 * A product deleted from the shop keeps its revenue under the id-0 bucket.
	 */
	public function test_a_deleted_product_reports_under_wc_zero(): void {
		$payload = ( new PeriodAggregator() )->aggregate(
			$this->orders( new LineItem( 0, 'Verdwenen artikel', 1, 9.5 ) )
		)['2026-05']->toPayload();

		// The revenue is real and must not be dropped. Launch Hub keeps the row and refuses to
		// price it (LU-SAL-011), because it is not one product but whatever used to be there.
		$this->assertSame( 'wc:0', $payload['products'][0]['external_ref'] );
		$this->assertSame( 9.5, $payload['products'][0]['revenue'] );
	}

	/**
	 * A product renamed mid-month stays ONE product.
	 *
	 * The aggregation key used to be id|name, which split a renamed product into two cells. That
	 * was harmless while a product WAS its name. It stopped being harmless the moment both cells
	 * carried the same external_ref: Launch Hub joins per-product prices on that id, so two
	 * entries meant the royalty was counted twice — silently, in money.
	 */
	public function test_a_rename_mid_month_does_not_split_the_product(): void {
		$payload = ( new PeriodAggregator() )->aggregate(
			$this->orders(
				new LineItem( 100, 'Hoodie zwart', 1, 30.0 ),
				new LineItem( 100, 'Hoodie zwart V2', 1, 30.0 )
			)
		)['2026-05']->toPayload();

		$this->assertCount( 1, $payload['products'], 'a rename must not double the product' );
		$this->assertSame( 'wc:100', $payload['products'][0]['external_ref'] );
		$this->assertSame( 2, $payload['products'][0]['quantity'] );
		$this->assertSame( 60.0, $payload['products'][0]['revenue'] );
		// Whichever name the shop reported last — the id is the identity, the name is a label.
		$this->assertSame( 'Hoodie zwart V2', $payload['products'][0]['name'] );
	}

	/**
	 * Two genuinely different products stay two, even with identical names.
	 */
	public function test_two_products_sharing_a_name_stay_separate(): void {
		$payload = ( new PeriodAggregator() )->aggregate(
			$this->orders(
				new LineItem( 100, 'Poster', 1, 10.0 ),
				new LineItem( 200, 'Poster', 1, 10.0 )
			)
		)['2026-05']->toPayload();

		$refs = array_column( $payload['products'], 'external_ref' );
		sort( $refs );

		$this->assertSame( array( 'wc:100', 'wc:200' ), $refs );
	}
}
