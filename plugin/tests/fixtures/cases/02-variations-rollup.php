<?php
/**
 * Fixture: variation lines (already resolved to the parent id + name by
 * OrderSource) roll up into one parent product row.
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

use LaunchUp\SalesConnector\Tests\Support\Fix;

return [
	'orders'   => [
		Fix::order( '2026-06-02 09:00', 'completed', [
			Fix::item( 30, 'esoo Shirt', 1, 20.00 ), // maat M.
			Fix::item( 30, 'esoo Shirt', 2, 40.00 ), // maat L.
		] ),
		Fix::order( '2026-06-04 12:00', 'completed', [
			Fix::item( 30, 'esoo Shirt', 1, 20.00 ), // maat S.
		] ),
	],
	'expected' => [
		'2026-06' => [
			'period_start'    => '2026-06-01',
			'period_end'      => '2026-06-30',
			'revenue'         => 80.00,
			'orders'          => 2,
			'avg_order_value' => 40.00,
			'items_total'     => 4,
			'products'        => [
				[ 'name' => 'esoo Shirt', 'quantity' => 4, 'revenue' => 80.00 ],
			],
		],
	],
	'notes'    => [ '2026-06' => [] ],
];
