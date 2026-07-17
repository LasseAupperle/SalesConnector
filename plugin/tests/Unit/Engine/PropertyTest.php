<?php
/**
 * Property tests for PeriodAggregator (specs/01 §4): determinism,
 * idempotence, invariants — ~200 random cases with a seeded RNG.
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector\Tests\Unit\Engine;

use LaunchUp\SalesConnector\Dto\OrderData;
use LaunchUp\SalesConnector\PayloadBuilder;
use LaunchUp\SalesConnector\PeriodAggregator;
use LaunchUp\SalesConnector\Tests\Support\Fix;
use PHPUnit\Framework\TestCase;

/**
 * Loop-based randomized properties (simple randomizer per spec).
 */
final class PropertyTest extends TestCase {

	private const ITERATIONS = 200;

	/**
	 * Serialize aggregates (payload + notes) for exact comparison.
	 *
	 * @param array<string, \LaunchUp\SalesConnector\Dto\PeriodAggregate> $aggregates Aggregates.
	 */
	private static function fingerprint( array $aggregates ): string {
		$out = [];
		foreach ( $aggregates as $ym => $aggregate ) {
			$out[ $ym ] = [ $aggregate->toPayload(), $aggregate->notes ];
		}
		return json_encode( $out );
	}

	/**
	 * @return OrderData[]
	 */
	private static function randomOrders(): array {
		$statuses = [ 'completed', 'processing', 'pending', 'cancelled', 'refunded', 'on-hold' ];
		$products = [
			[ 10, 'Shirt' ],
			[ 20, 'Hoodie' ],
			[ 30, 'esoo Shirt' ],
			[ 40, 'Mok' ],
			[ 0, 'Deleted product' ],
		];

		$orders = [];
		$count  = mt_rand( 0, 25 );
		for ( $i = 0; $i < $count; $i++ ) {
			$items   = [];
			$refunds = [];
			$lines   = mt_rand( 1, 4 );
			for ( $j = 0; $j < $lines; $j++ ) {
				[ $id, $name ] = $products[ mt_rand( 0, count( $products ) - 1 ) ];
				$qty           = mt_rand( 1, 5 );
				$items[]       = Fix::item( $id, $name, $qty, round( $qty * ( mt_rand( 500, 5000 ) / 100 ), 2 ) );
			}
			if ( 0 === mt_rand( 0, 2 ) ) {
				[ $id, $name ] = $products[ mt_rand( 0, count( $products ) - 1 ) ];
				$refunds[]     = Fix::refund( $id, $name, mt_rand( 0, 6 ), round( mt_rand( 0, 8000 ) / 100, 2 ) );
			}
			$unallocated = 0 === mt_rand( 0, 3 ) ? round( mt_rand( 0, 5000 ) / 100, 2 ) : 0.0;

			$month    = mt_rand( 1, 12 );
			$day      = mt_rand( 1, 28 );
			$orders[] = Fix::order(
				sprintf( '2026-%02d-%02d %02d:%02d', $month, $day, mt_rand( 0, 23 ), mt_rand( 0, 59 ) ),
				$statuses[ mt_rand( 0, count( $statuses ) - 1 ) ],
				$items,
				$refunds,
				$unallocated
			);
		}
		return $orders;
	}

	public function test_properties_hold_across_random_cases(): void {
		mt_srand( 424242 );
		$aggregator = new PeriodAggregator();

		for ( $iteration = 0; $iteration < self::ITERATIONS; $iteration++ ) {
			$orders = self::randomOrders();

			$baseline = $aggregator->aggregate( $orders );
			$base     = self::fingerprint( $baseline );

			// Shuffling never changes output (notes included).
			$shuffled = $orders;
			shuffle( $shuffled );
			$this->assertSame( $base, self::fingerprint( $aggregator->aggregate( $shuffled ) ), "iteration $iteration: shuffle changed output" );

			// Feeding the same list as a generator (streamed/split input) is identical.
			$generator = static function () use ( $orders ) {
				yield from $orders;
			};
			$this->assertSame( $base, self::fingerprint( $aggregator->aggregate( $generator() ) ), "iteration $iteration: generator input changed output" );

			// Duplicating the call is idempotent.
			$this->assertSame( $base, self::fingerprint( $aggregator->aggregate( $orders ) ), "iteration $iteration: repeat call changed output" );

			foreach ( $baseline as $ym => $aggregate ) {
				$payload = $aggregate->toPayload();

				// Invariant: items_total == Σ products[].quantity.
				$this->assertSame(
					$payload['items_total'],
					array_sum( array_column( $payload['products'], 'quantity' ) ),
					"iteration $iteration $ym: items invariant broken"
				);

				// No negative numbers ever serialize.
				array_walk_recursive(
					$payload,
					function ( $value ) use ( $iteration, $ym ): void {
						if ( is_int( $value ) || is_float( $value ) ) {
							$this->assertGreaterThanOrEqual( 0, $value, "iteration $iteration $ym: negative number serialized" );
						}
					}
				);
			}

			// PayloadBuilder is pure and sorted: periods ascend by period_start.
			$payload = PayloadBuilder::build( 'https://shop.test', 'Shop', 'lu_sk_x', $baseline );
			$starts  = array_column( $payload['periods'], 'period_start' );
			$sorted  = $starts;
			sort( $sorted, SORT_STRING );
			$this->assertSame( $sorted, $starts, "iteration $iteration: periods not sorted" );
		}
	}
}
