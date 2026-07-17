<?php
/**
 * StatusStore — last push state + ring-buffer log in option lusc_status
 * (specs/02 §4). Pure array in/out; rendered by the settings page.
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector;

use LaunchUp\SalesConnector\Dto\PushResult;

/**
 * Persists push attempts. Never stores the API key: messages are
 * defensively masked before writing.
 */
final class StatusStore {

	private const OPTION  = 'lusc_status';
	private const MAX_LOG = 10;

	/**
	 * Clock, injectable for tests. Returns a unix timestamp.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param callable|null $clock Optional clock override returning a timestamp.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Read the status option merged over defaults.
	 *
	 * @return array{last_success_at: ?string, last_attempt_at: ?string, last_result: string, consecutive_failures: int, log: array<int, array<string, mixed>>}
	 */
	public function read(): array {
		$stored = (array) get_option( self::OPTION, array() );
		return array_merge(
			array(
				'last_success_at'      => null,
				'last_attempt_at'      => null,
				'last_result'          => 'never',
				'consecutive_failures' => 0,
				'log'                  => array(),
			),
			$stored
		);
	}

	/**
	 * Record one push attempt (specs/02 §4).
	 *
	 * @param string     $kind    'daily' | 'manual' | 'backfill' | 'test'.
	 * @param string     $window  Human-readable window, e.g. '2026-04…2026-06'.
	 * @param int        $periods Number of periods in the payload.
	 * @param PushResult $result  The push outcome.
	 */
	public function recordAttempt( string $kind, string $window, int $periods, PushResult $result ): void {
		$status = $this->read();
		$now    = gmdate( 'c', (int) call_user_func( $this->clock ) );

		$status['last_attempt_at'] = $now;
		if ( $result->ok ) {
			$status['last_success_at']      = $now;
			$status['last_result']          = 'ok';
			$status['consecutive_failures'] = 0;
		} else {
			$status['last_result'] = 'failed';
			++$status['consecutive_failures'];
		}

		$entry = array(
			'time'    => $now,
			'kind'    => $kind,
			'window'  => $window,
			'periods' => $periods,
			'http'    => $result->httpCode,
			'lu_code' => $result->luCode,
			'message' => $this->maskSecrets( $result->message ),
		);
		if ( array() !== $result->warnings ) {
			// Warnings mean a bug (specs/00 §2) — surface them prominently in the log.
			$entry['message'] .= ' | WARNINGS: ' . implode( '; ', array_map( array( $this, 'maskSecrets' ), $result->warnings ) );
		}

		array_unshift( $status['log'], $entry );
		$status['log'] = array_slice( $status['log'], 0, self::MAX_LOG );

		update_option( self::OPTION, $status, false );
	}

	/**
	 * Defensively mask the API key anywhere it might appear in a message
	 * (the key itself is never logged by design; this is insurance).
	 *
	 * @param string $text Message text.
	 */
	private function maskSecrets( string $text ): string {
		$key = PushClient::resolveApiKey();
		if ( '' !== $key && false !== strpos( $text, $key ) ) {
			$text = str_replace( $key, PushClient::maskKey( $key ), $text );
		}
		return $text;
	}
}
