<?php
/**
 * SettingsPage pure-logic unit tests (specs/03 §5, phase gate).
 *
 * Only the WordPress-free static methods are exercised here: validation,
 * key masking, the status indicator, and the failure-notice / backfill
 * rules. The AJAX and rendering seams need a real WordPress and live in
 * the integration suite.
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector\Tests\Unit\Settings;

use LaunchUp\SalesConnector\SettingsPage;
use PHPUnit\Framework\TestCase;

final class SettingsPageTest extends TestCase {

	/**
	 * Fixed base timestamp for building deterministic ISO strings.
	 */
	private const BASE = 1_780_000_000;

	/**
	 * Build a StatusStore::read()-shaped array with sensible defaults.
	 *
	 * @param array<string, mixed> $overrides Keys to replace.
	 * @return array<string, mixed>
	 */
	private static function makeStatus( array $overrides = [] ): array {
		return array_merge(
			[
				'last_success_at'      => null,
				'last_attempt_at'      => null,
				'last_result'          => 'never',
				'consecutive_failures' => 0,
				'log'                  => [],
			],
			$overrides
		);
	}

	// -----------------------------------------------------------------------
	// isValidIngestUrl()
	// -----------------------------------------------------------------------

	/**
	 * @return iterable<string, array{string, bool}>
	 */
	public static function ingestUrlProvider(): iterable {
		yield 'https with path'   => [ 'https://hub.example.test/ingest', true ];
		yield 'https bare host'   => [ 'https://example.com', true ];
		yield 'http rejected'     => [ 'http://example.com', false ];
		yield 'empty rejected'    => [ '', false ];
		yield 'scheme only'       => [ 'https://', false ];
		yield 'space in url'      => [ 'https://ex ample.com', false ];
		yield 'trailing space'    => [ 'https://example.com ', false ];
		yield 'javascript scheme' => [ 'javascript:alert(1)', false ];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'ingestUrlProvider' )]
	public function test_is_valid_ingest_url( string $url, bool $expected ): void {
		$this->assertSame( $expected, SettingsPage::isValidIngestUrl( $url ) );
	}

	// -----------------------------------------------------------------------
	// isValidApiKey()
	// -----------------------------------------------------------------------

	/**
	 * @return iterable<string, array{string, bool}>
	 */
	public static function apiKeyProvider(): iterable {
		yield 'exactly 20 char suffix' => [ 'lu_sk_ABCDEFGHIJKLMNOPQRST', true ];
		yield 'longer suffix'          => [ 'lu_sk_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', true ];
		yield '19 char suffix'         => [ 'lu_sk_ABCDEFGHIJKLMNOPQRS', false ];
		yield 'wrong prefix'           => [ 'lu_pk_ABCDEFGHIJKLMNOPQRST', false ];
		yield 'no prefix'              => [ 'ABCDEFGHIJKLMNOPQRSTUVWX', false ];
		yield 'dash in suffix'         => [ 'lu_sk_ABCDEFGHIJKLMNOPQR-T', false ];
		yield 'leading whitespace'     => [ ' lu_sk_ABCDEFGHIJKLMNOPQRST', false ];
		yield 'trailing whitespace'    => [ 'lu_sk_ABCDEFGHIJKLMNOPQRST ', false ];
		yield 'empty'                  => [ '', false ];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'apiKeyProvider' )]
	public function test_is_valid_api_key( string $key, bool $expected ): void {
		$this->assertSame( $expected, SettingsPage::isValidApiKey( $key ) );
	}

	// -----------------------------------------------------------------------
	// displayKey()
	// -----------------------------------------------------------------------

	public function test_display_key_shows_last_four(): void {
		$this->assertSame( '…QRST', SettingsPage::displayKey( 'lu_sk_ABCDEFGHIJKLMNOPQRST' ) );
	}

	public function test_display_key_empty_stays_empty(): void {
		$this->assertSame( '', SettingsPage::displayKey( '' ) );
	}

	// -----------------------------------------------------------------------
	// indicator()
	// -----------------------------------------------------------------------

	public function test_indicator_is_orange_when_last_result_failed(): void {
		$status = self::makeStatus(
			[
				'last_result'     => 'failed',
				'last_success_at' => gmdate( 'c', self::BASE ),
			]
		);
		// Even a fresh success cannot beat a failed last result.
		$this->assertSame( 'orange', SettingsPage::indicator( $status, self::BASE ) );
	}

	public function test_indicator_is_green_within_26_hours(): void {
		$status = self::makeStatus(
			[
				'last_result'     => 'ok',
				'last_success_at' => gmdate( 'c', self::BASE ),
			]
		);
		$this->assertSame( 'green', SettingsPage::indicator( $status, self::BASE + 25 * 3600 ) );
	}

	public function test_indicator_is_green_just_under_26_hours(): void {
		$status = self::makeStatus(
			[
				'last_result'     => 'ok',
				'last_success_at' => gmdate( 'c', self::BASE ),
			]
		);
		$this->assertSame( 'green', SettingsPage::indicator( $status, self::BASE + 26 * 3600 - 1 ) );
	}

	public function test_indicator_is_orange_at_exactly_26_hours(): void {
		$status = self::makeStatus(
			[
				'last_result'     => 'ok',
				'last_success_at' => gmdate( 'c', self::BASE ),
			]
		);
		// Code uses strict <, so age === 26h flips to stale.
		$this->assertSame( 'orange', SettingsPage::indicator( $status, self::BASE + 26 * 3600 ) );
	}

	public function test_indicator_is_orange_when_success_is_stale(): void {
		$status = self::makeStatus(
			[
				'last_result'     => 'ok',
				'last_success_at' => gmdate( 'c', self::BASE ),
			]
		);
		$this->assertSame( 'orange', SettingsPage::indicator( $status, self::BASE + 27 * 3600 ) );
	}

	public function test_indicator_is_grey_when_never_succeeded(): void {
		$status = self::makeStatus( [ 'last_result' => 'never' ] );
		$this->assertSame( 'grey', SettingsPage::indicator( $status, self::BASE ) );
	}

	// -----------------------------------------------------------------------
	// failureNoticeVisible()
	// -----------------------------------------------------------------------

	/**
	 * @return iterable<string, array{int}>
	 */
	public static function belowThresholdProvider(): iterable {
		yield 'no failures'  => [ 0 ];
		yield 'two failures' => [ 2 ];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'belowThresholdProvider' )]
	public function test_failure_notice_hidden_below_three_failures( int $failures ): void {
		$status = self::makeStatus( [ 'consecutive_failures' => $failures ] );
		$this->assertFalse( SettingsPage::failureNoticeVisible( $status, null ) );
	}

	public function test_failure_notice_visible_when_not_dismissed(): void {
		$status = self::makeStatus( [ 'consecutive_failures' => 3 ] );
		$this->assertTrue( SettingsPage::failureNoticeVisible( $status, null ) );
	}

	public function test_failure_notice_hidden_when_dismissed_for_episode(): void {
		$status = self::makeStatus(
			[
				'consecutive_failures' => 4,
				'last_success_at'      => gmdate( 'c', self::BASE ),
			]
		);
		$this->assertFalse(
			SettingsPage::failureNoticeVisible( $status, SettingsPage::episodeKey( $status ) )
		);
	}

	public function test_failure_notice_visible_again_for_new_episode(): void {
		$dismissed = 'episode-' . gmdate( 'c', self::BASE );
		$status     = self::makeStatus(
			[
				'consecutive_failures' => 3,
				// A later success started a fresh failure episode.
				'last_success_at'      => gmdate( 'c', self::BASE + 3600 ),
			]
		);
		$this->assertTrue( SettingsPage::failureNoticeVisible( $status, $dismissed ) );
	}

	// -----------------------------------------------------------------------
	// episodeKey()
	// -----------------------------------------------------------------------

	public function test_episode_key_uses_last_success_at(): void {
		$iso    = gmdate( 'c', self::BASE );
		$status = self::makeStatus( [ 'last_success_at' => $iso ] );
		$this->assertSame( 'episode-' . $iso, SettingsPage::episodeKey( $status ) );
	}

	public function test_episode_key_is_never_when_null(): void {
		$this->assertSame( 'episode-never', SettingsPage::episodeKey( self::makeStatus() ) );
	}

	// -----------------------------------------------------------------------
	// backfillEnabled()
	// -----------------------------------------------------------------------

	public function test_backfill_enabled_when_a_success_exists(): void {
		$status = self::makeStatus( [ 'last_success_at' => gmdate( 'c', self::BASE ) ] );
		$this->assertTrue( SettingsPage::backfillEnabled( $status ) );
	}

	public function test_backfill_disabled_when_never_succeeded(): void {
		$this->assertFalse( SettingsPage::backfillEnabled( self::makeStatus() ) );
	}
}
