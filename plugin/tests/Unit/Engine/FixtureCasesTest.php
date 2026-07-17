<?php
/**
 * Table-driven fixture tests for PeriodAggregator (specs/01 §4).
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector\Tests\Unit\Engine;

use LaunchUp\SalesConnector\PeriodAggregator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs every fixture in tests/fixtures/cases against the aggregator and
 * compares serialized payloads and notes exactly.
 */
final class FixtureCasesTest extends TestCase {

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function caseProvider(): iterable {
		foreach ( glob( __DIR__ . '/../../fixtures/cases/*.php' ) as $file ) {
			yield basename( $file, '.php' ) => [ $file ];
		}
	}

	#[DataProvider( 'caseProvider' )]
	public function test_fixture( string $file ): void {
		$case = require $file;

		$aggregates = ( new PeriodAggregator() )->aggregate( $case['orders'] );

		$this->assertSame( array_keys( $case['expected'] ), array_keys( $aggregates ), 'month keys' );

		foreach ( $case['expected'] as $ym => $expectedPayload ) {
			$actual = $aggregates[ $ym ]->toPayload();
			// Compare money fields with cent tolerance (delta 0.005, specs/01 §2 rule 6), the rest exactly.
			$this->assertSame( $expectedPayload['period_start'], $actual['period_start'], "$ym period_start" );
			$this->assertSame( $expectedPayload['period_end'], $actual['period_end'], "$ym period_end" );
			$this->assertSame( $expectedPayload['orders'], $actual['orders'], "$ym orders" );
			$this->assertSame( $expectedPayload['items_total'], $actual['items_total'], "$ym items_total" );
			$this->assertEqualsWithDelta( $expectedPayload['revenue'], $actual['revenue'], 0.005, "$ym revenue" );
			if ( null === $expectedPayload['avg_order_value'] ) {
				$this->assertNull( $actual['avg_order_value'], "$ym avg_order_value" );
			} else {
				$this->assertEqualsWithDelta( $expectedPayload['avg_order_value'], $actual['avg_order_value'], 0.005, "$ym avg_order_value" );
			}
			$this->assertCount( count( $expectedPayload['products'] ), $actual['products'], "$ym product count" );
			foreach ( $expectedPayload['products'] as $i => $expectedProduct ) {
				$this->assertSame( $expectedProduct['name'], $actual['products'][ $i ]['name'], "$ym product $i name" );
				$this->assertSame( $expectedProduct['quantity'], $actual['products'][ $i ]['quantity'], "$ym product $i quantity" );
				$this->assertEqualsWithDelta( $expectedProduct['revenue'], $actual['products'][ $i ]['revenue'], 0.005, "$ym product $i revenue" );
			}

			$this->assertSame( $case['notes'][ $ym ], $aggregates[ $ym ]->notes, "$ym notes" );

			// Contract invariant holds by construction — assert anyway.
			$sum = array_sum( array_column( $actual['products'], 'quantity' ) );
			$this->assertSame( $actual['items_total'], $sum, "$ym items invariant" );
		}
	}
}
