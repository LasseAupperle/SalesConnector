<?php
/**
 * Fixture: orders across three months → three periods, ascending.
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

use LaunchUp\SalesConnector\Tests\Support\Fix;

return [
	'orders'   => [
		// Deliberately out of chronological order: input order must not matter.
		Fix::order( '2026-06-15 11:00', 'completed', [ Fix::item( 10, 'Shirt', 1, 20.00 ) ] ),
		Fix::order( '2026-04-10 09:00', 'completed', [ Fix::item( 10, 'Shirt', 2, 40.00 ) ] ),
		Fix::order( '2026-05-20 17:45', 'processing', [ Fix::item( 20, 'Hoodie', 1, 35.00 ) ] ),
	],
	'expected' => [
		'2026-04' => [
			'period_start'    => '2026-04-01',
			'period_end'      => '2026-04-30',
			'revenue'         => 40.00,
			'orders'          => 1,
			'avg_order_value' => 40.00,
			'items_total'     => 2,
			'products'        => [
				[ 'name' => 'Shirt', 'quantity' => 2, 'revenue' => 40.00 ],
			],
		],
		'2026-05' => [
			'period_start'    => '2026-05-01',
			'period_end'      => '2026-05-31',
			'revenue'         => 35.00,
			'orders'          => 1,
			'avg_order_value' => 35.00,
			'items_total'     => 1,
			'products'        => [
				[ 'name' => 'Hoodie', 'quantity' => 1, 'revenue' => 35.00 ],
			],
		],
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
	],
	'notes'    => [
		'2026-04' => [],
		'2026-05' => [],
		'2026-06' => [],
	],
];
