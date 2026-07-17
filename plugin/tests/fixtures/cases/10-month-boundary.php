<?php
/**
 * Fixture: an order at 23:59 shop time on the last day of the month lands
 * in that month; midnight on the 1st lands in the next.
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

use LaunchUp\SalesConnector\Tests\Support\Fix;

return [
	'orders'   => [
		Fix::order( '2026-06-30 23:59', 'completed', [ Fix::item( 10, 'Shirt', 1, 20.00 ) ] ),
		Fix::order( '2026-07-01 00:00', 'completed', [ Fix::item( 10, 'Shirt', 1, 20.00 ) ] ),
	],
	'expected' => [
		'2026-06' => [
			'period_start'    => '2026-06-01',
			'period_end'      => '2026-06-30',
			'revenue'         => 20.00,
			'orders'          => 1,
			'avg_order_value' => 20.00,
			'items_total'     => 1,
			'products'        => [
				[ 'name' => 'Shirt', 'quantity' => 1, 'revenue' => 20.00 ],
			],
		],
		'2026-07' => [
			'period_start'    => '2026-07-01',
			'period_end'      => '2026-07-31',
			'revenue'         => 20.00,
			'orders'          => 1,
			'avg_order_value' => 20.00,
			'items_total'     => 1,
			'products'        => [
				[ 'name' => 'Shirt', 'quantity' => 1, 'revenue' => 20.00 ],
			],
		],
	],
	'notes'    => [
		'2026-06' => [],
		'2026-07' => [],
	],
];
