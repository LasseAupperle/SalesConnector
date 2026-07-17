<?php
/**
 * Fixture: non-counted statuses are ignored entirely — their line items,
 * refunds, and unallocated amounts never touch any bucket.
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

use LaunchUp\SalesConnector\Tests\Support\Fix;

return [
	'orders'   => [
		Fix::order( '2026-06-05 10:00', 'pending', [ Fix::item( 10, 'Shirt', 3, 60.00 ) ] ),
		Fix::order( '2026-06-06 10:00', 'cancelled', [ Fix::item( 10, 'Shirt', 1, 20.00 ) ], [ Fix::refund( 10, 'Shirt', 1, 20.00 ) ] ),
		Fix::order( '2026-06-07 10:00', 'refunded', [ Fix::item( 20, 'Hoodie', 1, 35.00 ) ], [], 35.00 ),
		Fix::order( '2026-06-08 10:00', 'on-hold', [ Fix::item( 20, 'Hoodie', 2, 70.00 ) ] ),
		Fix::order( '2026-06-09 10:00', 'completed', [ Fix::item( 10, 'Shirt', 1, 20.00 ) ] ),
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
	],
	'notes'    => [ '2026-06' => [] ],
];
