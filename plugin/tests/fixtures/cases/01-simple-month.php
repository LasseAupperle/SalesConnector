<?php
/**
 * Fixture: one simple month, two counted orders.
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

use LaunchUp\SalesConnector\Tests\Support\Fix;

return [
	'orders'   => [
		Fix::order( '2026-06-05 10:00', 'completed', [
			Fix::item( 10, 'Shirt', 2, 40.00 ),
			Fix::item( 20, 'Hoodie', 1, 35.00 ),
		] ),
		Fix::order( '2026-06-20 15:30', 'processing', [
			Fix::item( 10, 'Shirt', 1, 20.00 ),
		] ),
	],
	'expected' => [
		'2026-06' => [
			'period_start'    => '2026-06-01',
			'period_end'      => '2026-06-30',
			'revenue'         => 95.00,
			'orders'          => 2,
			'avg_order_value' => 47.50,
			'items_total'     => 4,
			'products'        => [
				[ 'name' => 'Shirt', 'quantity' => 3, 'revenue' => 60.00 ],
				[ 'name' => 'Hoodie', 'quantity' => 1, 'revenue' => 35.00 ],
			],
		],
	],
	'notes'    => [ '2026-06' => [] ],
];
