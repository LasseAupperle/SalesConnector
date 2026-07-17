<?php
/**
 * Fixture: partial refund — 2 of 5 shirts refunded, allocated to the
 * original order's month.
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

use LaunchUp\SalesConnector\Tests\Support\Fix;

return [
	'orders'   => [
		Fix::order(
			'2026-06-08 14:00',
			'completed',
			[ Fix::item( 10, 'Shirt', 5, 100.00 ) ],
			[ Fix::refund( 10, 'Shirt', 2, 40.00 ) ]
		),
	],
	'expected' => [
		'2026-06' => [
			'period_start'    => '2026-06-01',
			'period_end'      => '2026-06-30',
			'revenue'         => 60.00,
			'orders'          => 1,
			'avg_order_value' => 60.00,
			'items_total'     => 3,
			'products'        => [
				[ 'name' => 'Shirt', 'quantity' => 3, 'revenue' => 60.00 ],
			],
		],
	],
	'notes'    => [ '2026-06' => [] ],
];
