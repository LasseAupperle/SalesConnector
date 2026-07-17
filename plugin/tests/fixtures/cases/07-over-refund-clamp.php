<?php
/**
 * Fixture: over-refund edges. Shirt is refunded beyond what was sold →
 * clamps to 0/0 and drops out (with notes). The unallocated refund
 * exceeds remaining period revenue → period revenue clamps at 0.00.
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

use LaunchUp\SalesConnector\Tests\Support\Fix;

return [
	'orders'   => [
		Fix::order(
			'2026-06-05 09:00',
			'completed',
			[ Fix::item( 10, 'Shirt', 1, 20.00 ) ],
			[ Fix::refund( 10, 'Shirt', 2, 30.00 ) ]
		),
		Fix::order(
			'2026-06-16 13:00',
			'completed',
			[ Fix::item( 40, 'Mok', 1, 10.00 ) ],
			[],
			15.00
		),
	],
	'expected' => [
		'2026-06' => [
			'period_start'    => '2026-06-01',
			'period_end'      => '2026-06-30',
			'revenue'         => 0.00,
			'orders'          => 2,
			'avg_order_value' => 0.00,
			'items_total'     => 1,
			'products'        => [
				[ 'name' => 'Mok', 'quantity' => 1, 'revenue' => 10.00 ],
			],
		],
	],
	'notes'    => [
		'2026-06' => [
			'clamped negative quantity for Shirt',
			'clamped negative revenue for Shirt',
			'unallocated refund €15.00 applied',
			'clamped negative period revenue',
		],
	],
];
