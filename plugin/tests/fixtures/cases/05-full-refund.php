<?php
/**
 * Fixture: fully refunded product drops out of the products list; the
 * order itself still counts (refunds never reduce the order count).
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

use LaunchUp\SalesConnector\Tests\Support\Fix;

return [
	'orders'   => [
		Fix::order(
			'2026-06-03 10:00',
			'completed',
			[
				Fix::item( 40, 'Mok', 1, 15.00 ),
				Fix::item( 10, 'Shirt', 1, 20.00 ),
			],
			[ Fix::refund( 40, 'Mok', 1, 15.00 ) ]
		),
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
