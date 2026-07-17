<?php
/**
 * Scheduler — daily push, manual push, backfill chain, retry ladder
 * (specs/02 §2). All scheduling via Action Scheduler, group 'lusc'.
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector;

/**
 * Owns every background job. Time-dependent logic is pure and clock-injected
 * so the window/chunk math is unit-testable without WordPress.
 */
final class Scheduler {

	public const GROUP         = 'lusc';
	public const HOOK_DAILY    = 'lusc_daily_push';
	public const HOOK_PUSH_NOW = 'lusc_push_now';
	public const HOOK_BACKFILL = 'lusc_backfill_chunk';
	public const HOOK_RETRY    = 'lusc_retry_push';

	public const CHUNK_SIZE = 6;

	/**
	 * Retry delays in seconds: +5 min, +30 min, +2 h (specs/02 §2).
	 */
	public const RETRY_DELAYS = array(
		1 => 300,
		2 => 1800,
		3 => 7200,
	);

	/**
	 * Clock returning a unix timestamp; injectable for tests.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param callable|null $clock Optional clock override.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Hook the Action Scheduler handlers. Called from lusc_boot().
	 */
	public function register(): void {
		add_action( self::HOOK_DAILY, array( $this, 'handleDaily' ) );
		add_action( self::HOOK_PUSH_NOW, array( $this, 'handlePushNow' ) );
		add_action( self::HOOK_RETRY, array( $this, 'handleRetry' ), 10, 2 );
		add_action( self::HOOK_BACKFILL, array( $this, 'handleBackfillChunk' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'ensureScheduled' ) );
	}

	/**
	 * Schedule the recurring daily push at 04:00 shop time if missing
	 * (activation + self-healing check, specs/02 §2).
	 */
	public function ensureScheduled(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return; // Action Scheduler not loaded (WooCommerce inactive).
		}
		if ( false !== as_next_scheduled_action( self::HOOK_DAILY, null, self::GROUP ) ) {
			return;
		}
		as_schedule_recurring_action(
			self::nextFourAm( $this->now() )->getTimestamp(),
			DAY_IN_SECONDS,
			self::HOOK_DAILY,
			array(),
			self::GROUP
		);
	}

	/**
	 * Remove every scheduled lusc action (deactivation, specs/02 §5).
	 */
	public static function unscheduleAll(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}

	/**
	 * Enqueue a manual push (settings button).
	 */
	public static function enqueuePushNow(): void {
		as_enqueue_async_action( self::HOOK_PUSH_NOW, array(), self::GROUP );
	}

	/**
	 * Start the backfill chain at the oldest counted order (settings button).
	 * Safe to re-run: idempotent upserts on the Hub (full re-sync path).
	 */
	public function startBackfill(): void {
		$oldest = $this->oldestCountedMonth();
		if ( null === $oldest ) {
			return; // No counted orders at all.
		}
		as_enqueue_async_action(
			self::HOOK_BACKFILL,
			array(
				'offset_months' => 0,
				'chunk_size'    => self::CHUNK_SIZE,
			),
			self::GROUP
		);
	}

	/**
	 * Daily handler: rolling window = first day of (current month − 2) → now.
	 */
	public function handleDaily(): void {
		$this->runWindow( 'daily', 1 );
	}

	/**
	 * Manual handler: same window as daily by design (specs/02 §2).
	 */
	public function handlePushNow(): void {
		$this->runWindow( 'manual', 1 );
	}

	/**
	 * Retry handler.
	 *
	 * @param string $kind    Original kind ('daily' | 'manual').
	 * @param int    $attempt Attempt number about to run (2..4).
	 */
	public function handleRetry( string $kind = 'daily', int $attempt = 2 ): void {
		$this->runWindow( $kind, $attempt );
	}

	/**
	 * Backfill chunk handler: aggregate + push one chunk of months, then
	 * schedule the next chunk until reaching the rolling window.
	 *
	 * @param int $offset_months Months from the oldest counted month.
	 * @param int $chunk_size    Chunk size in months.
	 */
	public function handleBackfillChunk( int $offset_months = 0, int $chunk_size = self::CHUNK_SIZE ): void {
		$oldest = $this->oldestCountedMonth();
		if ( null === $oldest ) {
			return;
		}
		$rollingStart = self::rollingWindowStart( $this->now() );
		$chunk        = self::computeChunk( $oldest, $rollingStart, $offset_months, $chunk_size );
		if ( null === $chunk ) {
			return; // Reached the rolling window: the daily job owns it from here.
		}
		list( $start, $endExclusive ) = $chunk;

		$this->pushRange( $start, $endExclusive, 'backfill' );

		if ( null !== self::computeChunk( $oldest, $rollingStart, $offset_months + $chunk_size, $chunk_size ) ) {
			as_enqueue_async_action(
				self::HOOK_BACKFILL,
				array(
					'offset_months' => $offset_months + $chunk_size,
					'chunk_size'    => $chunk_size,
				),
				self::GROUP
			);
		} else {
			// Chain done: refresh the rolling window too, so "Historie
			// pushen" leaves the dashboard fully current.
			self::enqueuePushNow();
		}
	}

	/**
	 * Run the rolling-window push and drive the retry ladder (specs/02 §2).
	 *
	 * @param string $kind    'daily' | 'manual'.
	 * @param int    $attempt This attempt number (1 = first try).
	 */
	public function runWindow( string $kind, int $attempt ): void {
		$now    = $this->now();
		$start  = self::rollingWindowStart( $now );
		$result = $this->pushRange( $start, $now, $kind );

		if ( $result || ! isset( self::RETRY_DELAYS[ $attempt ] ) ) {
			// Success — or the 3rd retry just failed: stop, the next daily
			// run tries fresh (StatusStore already recorded the failure).
			return;
		}

		as_schedule_single_action(
			$now->getTimestamp() + self::RETRY_DELAYS[ $attempt ],
			self::HOOK_RETRY,
			array(
				'kind'    => $kind,
				'attempt' => $attempt + 1,
			),
			self::GROUP
		);
	}

	/**
	 * Aggregate and push all months intersecting [start, endExclusive).
	 *
	 * @param \DateTimeImmutable $start        Window start (inclusive).
	 * @param \DateTimeImmutable $endExclusive Window end (exclusive).
	 * @param string             $kind         Status-log kind.
	 * @return bool Whether the push succeeded.
	 */
	private function pushRange( \DateTimeImmutable $start, \DateTimeImmutable $endExclusive, string $kind ): bool {
		$store  = new StatusStore();
		$source = new OrderSource();

		$end        = $endExclusive->modify( '-1 second' );
		$aggregates = ( new PeriodAggregator() )->aggregate( $source->ordersForWindow( $start, $end ) );
		$payload    = PayloadBuilder::build( site_url(), get_bloginfo( 'name' ), PushClient::resolveApiKey(), $aggregates );

		$result = ( new PushClient() )->push( $payload );
		$store->recordAttempt( $kind, $start->format( 'Y-m' ) . '…' . $end->format( 'Y-m' ), count( $aggregates ), $result );

		return $result->ok;
	}

	/**
	 * First day of (month of $now − 2), 00:00, shop timezone (specs/02 §2).
	 *
	 * @param \DateTimeImmutable $now Current time in shop timezone.
	 */
	public static function rollingWindowStart( \DateTimeImmutable $now ): \DateTimeImmutable {
		return $now->modify( 'first day of this month' )->setTime( 0, 0 )->modify( '-2 months' );
	}

	/**
	 * Next 04:00 in shop time, strictly in the future (specs/02 §2).
	 *
	 * @param \DateTimeImmutable $now Current time in shop timezone.
	 */
	public static function nextFourAm( \DateTimeImmutable $now ): \DateTimeImmutable {
		$today = $now->setTime( 4, 0 );
		return $today > $now ? $today : $today->modify( '+1 day' );
	}

	/**
	 * Compute backfill chunk [start, endExclusive) or null when the offset
	 * reaches the rolling window. Pure (specs/02 §2 backfill).
	 *
	 * @param \DateTimeImmutable $oldest       First day of the oldest counted month.
	 * @param \DateTimeImmutable $rollingStart First day of the rolling window.
	 * @param int                $offsetMonths Months from $oldest.
	 * @param int                $chunkSize    Chunk size in months.
	 * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
	 */
	public static function computeChunk( \DateTimeImmutable $oldest, \DateTimeImmutable $rollingStart, int $offsetMonths, int $chunkSize ): ?array {
		$start = $oldest->modify( sprintf( '+%d months', $offsetMonths ) );
		if ( $start >= $rollingStart ) {
			return null;
		}
		$endExclusive = $start->modify( sprintf( '+%d months', $chunkSize ) );
		if ( $endExclusive > $rollingStart ) {
			$endExclusive = $rollingStart;
		}
		return array( $start, $endExclusive );
	}

	/**
	 * First day of the month of the oldest counted order, 00:00 shop time.
	 */
	private function oldestCountedMonth(): ?\DateTimeImmutable {
		$orders = wc_get_orders(
			array(
				'status'  => array( 'completed', 'processing' ),
				'limit'   => 1,
				'orderby' => 'date',
				'order'   => 'ASC',
			)
		);
		if ( array() === $orders ) {
			return null;
		}
		$created = ( new \DateTimeImmutable( '@' . $orders[0]->get_date_created()->getTimestamp() ) )
			->setTimezone( wp_timezone() );
		return $created->modify( 'first day of this month' )->setTime( 0, 0 );
	}

	/**
	 * Current time in shop timezone.
	 */
	private function now(): \DateTimeImmutable {
		return ( new \DateTimeImmutable( '@' . (int) call_user_func( $this->clock ) ) )
			->setTimezone( wp_timezone() );
	}
}
