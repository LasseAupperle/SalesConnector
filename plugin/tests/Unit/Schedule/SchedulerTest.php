<?php
/**
 * Scheduler unit tests (specs/02 §2, phase-4 gate): pure window/chunk
 * math, daily scheduling, retry ladder, backfill chaining — against the
 * Action Scheduler stubs in the bootstrap.
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector\Tests\Unit\Schedule;

use LaunchUp\SalesConnector\Scheduler;
use LaunchUp\SalesConnector\StatusStore;
use PHPUnit\Framework\TestCase;

final class SchedulerTest extends TestCase {

	private const TZ = 'Europe/Amsterdam';

	private int $now;

	protected function setUp(): void {
		lusc_test_reset_options();
		lusc_test_reset_actions();
		unset( $GLOBALS['lusc_http_response'], $GLOBALS['lusc_http_request'] );
		// 2026-07-15 10:00 Europe/Amsterdam.
		$this->now = ( new \DateTimeImmutable( '2026-07-15 10:00:00', new \DateTimeZone( self::TZ ) ) )->getTimestamp();
		update_option(
			'lusc_settings',
			[
				'ingest_url' => 'https://hub.example.test/ingest',
				'api_key'    => 'lu_sk_SCHEDULERTEST000001',
			]
		);
	}

	private function scheduler(): Scheduler {
		return new Scheduler( fn (): int => $this->now );
	}

	private static function at( string $datetime ): \DateTimeImmutable {
		return new \DateTimeImmutable( $datetime, new \DateTimeZone( self::TZ ) );
	}

	// --- Pure time math ----------------------------------------------------

	public function test_rolling_window_start_is_first_of_current_month_minus_two(): void {
		$this->assertSame(
			'2026-05-01 00:00:00',
			Scheduler::rollingWindowStart( self::at( '2026-07-15 10:00:00' ) )->format( 'Y-m-d H:i:s' )
		);
		// Month-length edge: March 31 → January 1 window start (not a skipped month).
		$this->assertSame(
			'2026-01-01 00:00:00',
			Scheduler::rollingWindowStart( self::at( '2026-03-31 23:59:59' ) )->format( 'Y-m-d H:i:s' )
		);
	}

	public function test_next_four_am_is_strictly_in_the_future(): void {
		$this->assertSame(
			'2026-07-15 04:00:00',
			Scheduler::nextFourAm( self::at( '2026-07-15 03:59:00' ) )->format( 'Y-m-d H:i:s' )
		);
		$this->assertSame(
			'2026-07-16 04:00:00',
			Scheduler::nextFourAm( self::at( '2026-07-15 04:00:00' ) )->format( 'Y-m-d H:i:s' )
		);
		$this->assertSame(
			'2026-07-16 04:00:00',
			Scheduler::nextFourAm( self::at( '2026-07-15 10:00:00' ) )->format( 'Y-m-d H:i:s' )
		);
	}

	public function test_compute_chunk_walks_oldest_to_window_and_stops(): void {
		$oldest  = self::at( '2025-01-01 00:00:00' );
		$rolling = self::at( '2026-05-01 00:00:00' );

		$first = Scheduler::computeChunk( $oldest, $rolling, 0, 6 );
		$this->assertSame( [ '2025-01-01', '2025-07-01' ], [ $first[0]->format( 'Y-m-d' ), $first[1]->format( 'Y-m-d' ) ] );

		$second = Scheduler::computeChunk( $oldest, $rolling, 6, 6 );
		$this->assertSame( [ '2025-07-01', '2026-01-01' ], [ $second[0]->format( 'Y-m-d' ), $second[1]->format( 'Y-m-d' ) ] );

		// Last chunk clamps at the rolling window start.
		$third = Scheduler::computeChunk( $oldest, $rolling, 12, 6 );
		$this->assertSame( [ '2026-01-01', '2026-05-01' ], [ $third[0]->format( 'Y-m-d' ), $third[1]->format( 'Y-m-d' ) ] );

		$this->assertNull( Scheduler::computeChunk( $oldest, $rolling, 18, 6 ) );
		// A shop younger than the rolling window has nothing to backfill.
		$this->assertNull( Scheduler::computeChunk( $rolling, $rolling, 0, 6 ) );
	}

	// --- Daily scheduling ---------------------------------------------------

	public function test_ensure_scheduled_registers_daily_at_next_four_am_once(): void {
		$scheduler = $this->scheduler();
		$scheduler->ensureScheduled();
		$scheduler->ensureScheduled();

		$daily = array_values( array_filter( $GLOBALS['lusc_test_actions'], fn ( $a ) => Scheduler::HOOK_DAILY === $a['hook'] ) );
		$this->assertCount( 1, $daily, 'self-healing check must not double-schedule' );
		$this->assertSame( 'recurring', $daily[0]['type'] );
		$this->assertSame( DAY_IN_SECONDS, $daily[0]['interval'] );
		$this->assertSame( 'lusc', $daily[0]['group'] );
		$this->assertSame(
			self::at( '2026-07-16 04:00:00' )->getTimestamp(),
			$daily[0]['timestamp'],
			'first run must be the next 04:00 shop time'
		);
	}

	public function test_unschedule_all_clears_the_group(): void {
		$this->scheduler()->ensureScheduled();
		Scheduler::enqueuePushNow();
		$this->assertNotEmpty( $GLOBALS['lusc_test_actions'] );

		Scheduler::unscheduleAll();
		$this->assertSame( [], $GLOBALS['lusc_test_actions'] );
	}

	// --- Retry ladder -------------------------------------------------------

	public function test_retry_ladder_schedules_5m_30m_2h_then_stops(): void {
		$GLOBALS['lusc_http_response'] = new \LuscTestError( 'connection refused' );
		$scheduler                     = $this->scheduler();

		$scheduler->handleDaily();
		$retries = array_values( array_filter( $GLOBALS['lusc_test_actions'], fn ( $a ) => Scheduler::HOOK_RETRY === $a['hook'] ) );
		$this->assertCount( 1, $retries );
		$this->assertSame( $this->now + 300, $retries[0]['timestamp'] );
		$this->assertSame( [ 'kind' => 'daily', 'attempt' => 2 ], $retries[0]['args'] );

		$scheduler->handleRetry( 'daily', 2 );
		$scheduler->handleRetry( 'daily', 3 );
		$retries = array_values( array_filter( $GLOBALS['lusc_test_actions'], fn ( $a ) => Scheduler::HOOK_RETRY === $a['hook'] ) );
		$this->assertCount( 3, $retries );
		$this->assertSame( $this->now + 1800, $retries[1]['timestamp'] );
		$this->assertSame( $this->now + 7200, $retries[2]['timestamp'] );

		// 4th attempt (3rd retry) failing must NOT schedule another retry.
		$scheduler->handleRetry( 'daily', 4 );
		$retries = array_filter( $GLOBALS['lusc_test_actions'], fn ( $a ) => Scheduler::HOOK_RETRY === $a['hook'] );
		$this->assertCount( 3, $retries, 'ladder must stop after the 3rd retry' );

		$status = ( new StatusStore() )->read();
		$this->assertSame( 4, $status['consecutive_failures'] );
		$this->assertSame( 'failed', $status['last_result'] );
	}

	public function test_success_schedules_no_retry_and_records_ok(): void {
		$GLOBALS['lusc_http_response'] = [
			'response' => [ 'code' => 200 ],
			'body'     => '{"imported":0}',
		];

		$this->scheduler()->handleDaily();

		$this->assertSame( [], array_filter( $GLOBALS['lusc_test_actions'], fn ( $a ) => Scheduler::HOOK_RETRY === $a['hook'] ) );
		$status = ( new StatusStore() )->read();
		$this->assertSame( 'ok', $status['last_result'] );
		$this->assertSame( 'daily', $status['log'][0]['kind'] );
		$this->assertSame( '2026-05…2026-07', $status['log'][0]['window'] );
	}

	// --- Backfill chain -----------------------------------------------------

	/**
	 * Fake just enough of an order for the oldest-month lookup.
	 */
	private function seedOldestOrder( string $datetime ): void {
		$createdAt                   = self::at( $datetime );
		$GLOBALS['lusc_test_orders'] = [
			new class( $createdAt ) {
				public function __construct( private \DateTimeImmutable $at ) {}

				public function get_date_created(): \DateTimeImmutable {
					return $this->at;
				}
			},
		];
	}

	public function test_backfill_chunk_pushes_and_schedules_next_chunk(): void {
		$GLOBALS['lusc_http_response'] = [
			'response' => [ 'code' => 200 ],
			'body'     => '{"imported":0}',
		];
		$this->seedOldestOrder( '2025-01-15 12:00:00' ); // 16 months before rolling start.

		$this->scheduler()->handleBackfillChunk( 0, 6 );

		$backfills = array_values( array_filter( $GLOBALS['lusc_test_actions'], fn ( $a ) => Scheduler::HOOK_BACKFILL === $a['hook'] ) );
		$this->assertCount( 1, $backfills, 'next chunk must be scheduled' );
		$this->assertSame(
			[ 'offset_months' => 6, 'chunk_size' => 6 ],
			$backfills[0]['args']
		);

		$status = ( new StatusStore() )->read();
		$this->assertSame( 'backfill', $status['log'][0]['kind'] );
		$this->assertSame( '2025-01…2025-06', $status['log'][0]['window'] );
	}

	public function test_backfill_final_chunk_enqueues_rolling_window_push_and_stops(): void {
		$GLOBALS['lusc_http_response'] = [
			'response' => [ 'code' => 200 ],
			'body'     => '{"imported":0}',
		];
		$this->seedOldestOrder( '2025-11-03 09:00:00' ); // Exactly one 6-month chunk before 2026-05.

		$this->scheduler()->handleBackfillChunk( 0, 6 );

		$this->assertSame(
			[],
			array_filter( $GLOBALS['lusc_test_actions'], fn ( $a ) => Scheduler::HOOK_BACKFILL === $a['hook'] ),
			'no further chunk after reaching the rolling window'
		);
		$pushNow = array_values( array_filter( $GLOBALS['lusc_test_actions'], fn ( $a ) => Scheduler::HOOK_PUSH_NOW === $a['hook'] ) );
		$this->assertCount( 1, $pushNow, 'chain completion refreshes the rolling window' );

		$this->assertSame( '2025-11…2026-04', ( new StatusStore() )->read()['log'][0]['window'] );
	}

	public function test_backfill_with_no_orders_does_nothing(): void {
		$GLOBALS['lusc_test_orders'] = [];

		$this->scheduler()->handleBackfillChunk( 0, 6 );

		$this->assertSame( [], $GLOBALS['lusc_test_actions'] );
	}
}
