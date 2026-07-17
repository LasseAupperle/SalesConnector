<?php
/**
 * Canonical fixture for the byte-for-byte payload snapshot (specs/01 §4).
 *
 * Hand-computed expectations (verify against expected-payload.json):
 * - 2026-05: Shirt zwart qty 2+3−1=4 rev 40+60−20=80 · Hoodie qty 1 rev 35
 *            revenue 115, orders 2, avg 57.50, items 5.
 * - 2026-06: Shirt zwart qty 2 rev 40 (two variation lines) · Mok qty 2 rev 24
 *            unallocated refund 4.00 → revenue 60, orders 2, avg 30, items 4.
 *            (pending order ignored)
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

use LaunchUp\SalesConnector\Tests\Support\Fix;

return [
	Fix::order( '2026-05-03 10:15', 'completed', [
		Fix::item( 100, 'esoo Shirt zwart', 2, 40.00 ),
		Fix::item( 200, 'esoo Hoodie', 1, 35.00 ),
	] ),
	Fix::order(
		'2026-05-15 18:40',
		'processing',
		[ Fix::item( 100, 'esoo Shirt zwart', 3, 60.00 ) ],
		[ Fix::refund( 100, 'esoo Shirt zwart', 1, 20.00 ) ]
	),
	Fix::order( '2026-06-01 00:05', 'completed', [
		Fix::item( 100, 'esoo Shirt zwart', 1, 20.00 ), // maat M.
		Fix::item( 100, 'esoo Shirt zwart', 1, 20.00 ), // maat L.
	] ),
	Fix::order( '2026-06-10 12:00', 'completed', [ Fix::item( 300, 'esoo Mok', 2, 24.00 ) ], [], 4.00 ),
	Fix::order( '2026-06-12 09:30', 'pending', [ Fix::item( 300, 'esoo Mok', 5, 60.00 ) ] ),
];
