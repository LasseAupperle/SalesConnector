<?php
/**
 * StatusStore unit tests (specs/02 §4, phase-3 gate).
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector\Tests\Unit\Push;

use LaunchUp\SalesConnector\Dto\PushResult;
use LaunchUp\SalesConnector\StatusStore;
use PHPUnit\Framework\TestCase;

final class StatusStoreTest extends TestCase {

	private int $now = 1_780_000_000;

	protected function setUp(): void {
		lusc_test_reset_options();
	}

	private function store(): StatusStore {
		return new StatusStore( fn (): int => $this->now );
	}

	private static function okResult( int $imported = 1 ): PushResult {
		return new PushResult( true, 200, $imported, false, null, 'OK', [] );
	}

	private static function failResult( string $message = 'boom', ?string $luCode = null ): PushResult {
		return new PushResult( false, 403, null, false, $luCode, $message, [] );
	}

	public function test_defaults_when_never_pushed(): void {
		$status = $this->store()->read();
		$this->assertSame( 'never', $status['last_result'] );
		$this->assertNull( $status['last_success_at'] );
		$this->assertNull( $status['last_attempt_at'] );
		$this->assertSame( 0, $status['consecutive_failures'] );
		$this->assertSame( [], $status['log'] );
	}

	public function test_success_sets_timestamps_and_resets_failures(): void {
		$store = $this->store();
		$store->recordAttempt( 'daily', '2026-04…2026-06', 3, self::failResult() );
		$store->recordAttempt( 'daily', '2026-04…2026-06', 3, self::failResult() );
		$this->assertSame( 2, $store->read()['consecutive_failures'] );

		$this->now += 60;
		$store->recordAttempt( 'manual', '2026-04…2026-06', 3, self::okResult( 3 ) );

		$status = $store->read();
		$this->assertSame( 'ok', $status['last_result'] );
		$this->assertSame( 0, $status['consecutive_failures'] );
		$this->assertSame( $status['last_attempt_at'], $status['last_success_at'] );
		$this->assertSame( gmdate( 'c', $this->now ), $status['last_success_at'] );
	}

	public function test_failure_keeps_last_success_and_increments(): void {
		$store = $this->store();
		$store->recordAttempt( 'daily', 'w', 1, self::okResult() );
		$successAt = $store->read()['last_success_at'];

		$this->now += 120;
		$store->recordAttempt( 'daily', 'w', 1, self::failResult( 'nope', 'LU-SAL-002' ) );

		$status = $store->read();
		$this->assertSame( 'failed', $status['last_result'] );
		$this->assertSame( 1, $status['consecutive_failures'] );
		$this->assertSame( $successAt, $status['last_success_at'] );
		$this->assertSame( 'LU-SAL-002', $status['log'][0]['lu_code'] );
	}

	public function test_log_is_ring_buffer_of_ten_newest_first(): void {
		$store = $this->store();
		for ( $i = 1; $i <= 12; $i++ ) {
			$this->now += 10;
			$store->recordAttempt( 'backfill', "window-$i", $i, self::okResult( $i ) );
		}

		$log = $store->read()['log'];
		$this->assertCount( 10, $log );
		$this->assertSame( 'window-12', $log[0]['window'] );
		$this->assertSame( 'window-3', $log[9]['window'] );
	}

	public function test_log_entry_shape(): void {
		$store = $this->store();
		$store->recordAttempt( 'test', '—', 0, self::failResult( 'API key invalid', 'LU-SAL-002' ) );

		$entry = $store->read()['log'][0];
		$this->assertSame(
			[ 'time', 'kind', 'window', 'periods', 'http', 'lu_code', 'message' ],
			array_keys( $entry )
		);
		$this->assertSame( 'test', $entry['kind'] );
		$this->assertSame( 0, $entry['periods'] );
		$this->assertSame( 403, $entry['http'] );
	}

	public function test_api_key_never_lands_in_log_even_if_message_contains_it(): void {
		update_option(
			'lusc_settings',
			[
				'ingest_url' => 'https://hub.example.test/ingest',
				'api_key'    => 'lu_sk_SECRETSECRETSECRET99',
			]
		);

		$store = $this->store();
		$store->recordAttempt( 'test', '—', 0, self::failResult( 'denied for key lu_sk_SECRETSECRETSECRET99 (revoked)' ) );
		$store->recordAttempt(
			'daily',
			'w',
			1,
			new PushResult( true, 200, 1, false, null, 'OK', [ 'echo of lu_sk_SECRETSECRETSECRET99' ] )
		);

		$status     = $store->read();
		$serialized = json_encode( $status );
		$this->assertStringNotContainsString( 'lu_sk_SECRETSECRETSECRET99', $serialized );
		$this->assertStringContainsString( '…ET99', $status['log'][1]['message'] );
		$this->assertStringContainsString( '…ET99', $status['log'][0]['message'] );
	}

	public function test_warnings_are_appended_prominently(): void {
		$store = $this->store();
		$store->recordAttempt(
			'daily',
			'w',
			2,
			new PushResult( true, 200, 2, false, null, 'OK', [ 'items_total mismatch in 2026-06-01' ] )
		);

		$this->assertStringContainsString( 'WARNINGS: items_total mismatch in 2026-06-01', $store->read()['log'][0]['message'] );
	}
}
