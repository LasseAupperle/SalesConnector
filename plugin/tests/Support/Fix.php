<?php
/**
 * Tiny fixture factory for engine tests.
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector\Tests\Support;

use LaunchUp\SalesConnector\Dto\LineItem;
use LaunchUp\SalesConnector\Dto\OrderData;
use LaunchUp\SalesConnector\Dto\RefundLine;

/**
 * Shorthand builders. Shop timezone is Europe/Amsterdam (specs/00 §4.7).
 */
final class Fix {

	public const TZ = 'Europe/Amsterdam';

	/**
	 * Build an order.
	 *
	 * @param string       $createdAt   'Y-m-d H:i' in shop timezone.
	 * @param string       $status      Order status.
	 * @param LineItem[]   $items       Product lines.
	 * @param RefundLine[] $refunds     Refund lines.
	 * @param float        $unallocated Amount-only refund total.
	 */
	public static function order( string $createdAt, string $status, array $items, array $refunds = [], float $unallocated = 0.0 ): OrderData {
		return new OrderData(
			new \DateTimeImmutable( $createdAt, new \DateTimeZone( self::TZ ) ),
			$status,
			$items,
			$refunds,
			$unallocated
		);
	}

	public static function item( int $parentId, string $name, int $qty, float $totalExTax ): LineItem {
		return new LineItem( $parentId, $name, $qty, $totalExTax );
	}

	public static function refund( int $parentId, string $name, int $qty, float $amountExTax ): RefundLine {
		return new RefundLine( $parentId, $name, $qty, $amountExTax );
	}
}
