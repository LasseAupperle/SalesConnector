<?php
/**
 * Fixture: amount-only refund (no line items) subtracts from period
 * revenue only; quantities stay untouched; a note is appended.
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

use LaunchUp\SalesConnector\Tests\Support\Fix;

return [
	'orders'   => [
		Fix::order(
			'2026-06-11 16:20',
			'completed',
			[ Fix::item( 10, 'Shirt', 2, 40.00 ) ],
			[],
			12.50
		),
	],
	'expected' => [
		'2026-06' => [
			'period_start'    => '2026-06-01',
			'period_end'      => '2026-06-30',
			'revenue'         => 27.50,
			'orders'          => 1,
			'avg_order_value' => 27.50,
			'items_total'     => 2,
			'products'        => [
				[ 'name' => 'Shirt', 'quantity' => 2, 'revenue' => 40.00 ],
			],
		],
	],
	'notes'    => [ '2026-06' => [ 'unallocated refund €12.50 applied' ] ],
];
