<?php
/**
 * Snapshot test: PayloadBuilder output for the canonical fixture matches
 * tests/fixtures/expected-payload.json byte-for-byte (specs/01 §4).
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector\Tests\Unit\Engine;

use LaunchUp\SalesConnector\PayloadBuilder;
use LaunchUp\SalesConnector\PeriodAggregator;
use PHPUnit\Framework\TestCase;

/**
 * The snapshot is hand-written from hand-computed numbers — if this test
 * fails, first decide whether the code or the snapshot is wrong; never
 * regenerate the snapshot blindly.
 */
final class PayloadSnapshotTest extends TestCase {

	public function test_canonical_payload_matches_snapshot_byte_for_byte(): void {
		$orders     = require __DIR__ . '/../../fixtures/canonical.php';
		$aggregates = ( new PeriodAggregator() )->aggregate( $orders );

		$payload = PayloadBuilder::build(
			'https://shopnaam.nl',
			'esoo Merch',
			'lu_sk_TESTKEY1234567890AB',
			$aggregates
		);

		$json     = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		$expected = file_get_contents( __DIR__ . '/../../fixtures/expected-payload.json' );

		// Normalize CRLF in case git checkout rewrote the fixture on Windows.
		$expected = str_replace( "\r\n", "\n", (string) $expected );

		$this->assertSame( $expected, $json );
	}
}
