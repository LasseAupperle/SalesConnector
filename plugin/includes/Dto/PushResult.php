<?php
/**
 * PushResult DTO — outcome of one ingest POST (specs/02 §3).
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector\Dto;

/**
 * Plain result data; rendered by the status log and admin notices.
 */
final class PushResult {

	/**
	 * Constructor.
	 *
	 * @param bool        $ok       Whether the push succeeded (HTTP 200).
	 * @param int|null    $httpCode HTTP status, null on transport failure.
	 * @param int|null    $imported Imported period count on success.
	 * @param bool        $test     True when the endpoint flagged a connectivity test.
	 * @param string|null $luCode   LU-SAL-* error code, if any.
	 * @param string      $message  Human-readable outcome message (never contains the API key).
	 * @param string[]    $warnings Endpoint warnings — any warning means a bug (specs/00 §2).
	 */
	public function __construct(
		public readonly bool $ok,
		public readonly ?int $httpCode,
		public readonly ?int $imported,
		public readonly bool $test,
		public readonly ?string $luCode,
		public readonly string $message,
		public readonly array $warnings,
	) {}
}
