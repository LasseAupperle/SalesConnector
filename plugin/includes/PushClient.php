<?php
/**
 * PushClient — the only class doing HTTP (specs/00 §1, specs/02 §3).
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector;

use LaunchUp\SalesConnector\Dto\PushResult;

/**
 * POSTs contract-v1.1 payloads to the Launch Hub sales-ingest endpoint and
 * maps every response shape to a PushResult.
 */
final class PushClient {

	private const TIMEOUT = 15;

	/**
	 * Ingest URL used for requests.
	 *
	 * @var string
	 */
	private string $ingestUrl;

	/**
	 * Constructor.
	 *
	 * @param string|null $ingestUrl Override for tests; defaults to the stored setting.
	 */
	public function __construct( ?string $ingestUrl = null ) {
		$this->ingestUrl = $ingestUrl ?? self::resolveIngestUrl();
	}

	/**
	 * Resolve the ingest URL from settings.
	 */
	public static function resolveIngestUrl(): string {
		$settings = (array) get_option( 'lusc_settings', array() );
		return (string) ( $settings['ingest_url'] ?? '' );
	}

	/**
	 * Resolve the API key: constant LUSC_API_KEY overrides the stored option
	 * (specs/02 §3; recommended for operators).
	 */
	public static function resolveApiKey(): string {
		if ( defined( 'LUSC_API_KEY' ) && is_string( LUSC_API_KEY ) && '' !== LUSC_API_KEY ) {
			return LUSC_API_KEY;
		}
		$settings = (array) get_option( 'lusc_settings', array() );
		return (string) ( $settings['api_key'] ?? '' );
	}

	/**
	 * Mask an API key for logs: last 4 chars only (specs/02 §3).
	 *
	 * @param string $key The full key.
	 */
	public static function maskKey( string $key ): string {
		if ( '' === $key ) {
			return '';
		}
		return '…' . substr( $key, -4 );
	}

	/**
	 * POST a payload to the ingest endpoint.
	 *
	 * @param array<string, mixed> $payload Contract-v1.1 body (from PayloadBuilder).
	 */
	public function push( array $payload ): PushResult {
		$response = wp_remote_post(
			$this->ingestUrl,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new PushResult( false, null, null, false, null, $response->get_error_message(), array() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$body = is_array( $body ) ? $body : array();

		if ( 200 === $code ) {
			/*
			 * A 200 is NOT success on its own. Launch Hub always answers an accepted push with
			 * an `imported` count; anything else returning 200 — a shop's own homepage, a
			 * captive portal, a proxy, a mistyped URL that happens to resolve — is not our
			 * endpoint agreeing, and treating it as agreement is how a connector goes green
			 * while a creator's revenue quietly goes nowhere. That happened in the first live
			 * install: a truncated URL earned a "laatste succes" timestamp and an OK in the log
			 * while Launch Hub had never heard of the shop.
			 */
			if ( ! array_key_exists( 'imported', $body ) ) {
				return new PushResult(
					false,
					200,
					null,
					false,
					null,
					__( 'Unexpected answer from this address — is the ingest URL correct?', 'launchup-sales-connector' ),
					array()
				);
			}

			return new PushResult(
				true,
				200,
				(int) $body['imported'],
				(bool) ( $body['test'] ?? false ),
				null,
				__( 'OK', 'launchup-sales-connector' ),
				array_map( 'strval', (array) ( $body['warnings'] ?? array() ) )
			);
		}

		$luCode = isset( $body['code'] ) ? (string) $body['code'] : null;
		/* translators: %d: HTTP status code. */
		$fallback = sprintf( __( 'Unexpected response (HTTP %d)', 'launchup-sales-connector' ), $code );
		$message  = self::humanMessage( $luCode, (string) ( $body['message'] ?? $fallback ) );

		return new PushResult( false, $code, null, false, $luCode, $message, array() );
	}

	/**
	 * Connectivity test: same POST with an empty periods array (specs/00 §2).
	 */
	public function test(): PushResult {
		$payload = PayloadBuilder::build( site_url(), get_bloginfo( 'name' ), self::resolveApiKey(), array() );
		return $this->push( $payload );
	}

	/**
	 * Map known LU-SAL-* codes to human messages (specs/02 §3; nl_NL
	 * translations shipped in languages/).
	 *
	 * @param string|null $luCode   The LU code, if any.
	 * @param string      $fallback Message when the code is unknown.
	 */
	private static function humanMessage( ?string $luCode, string $fallback ): string {
		switch ( $luCode ) {
			case 'LU-SAL-002':
				return __( 'API key invalid or revoked — create a new one in Launch Hub', 'launchup-sales-connector' );
			case 'LU-SAL-003':
				return __( 'This key belongs to a different shop URL', 'launchup-sales-connector' );
			case 'LU-SAL-004':
				return __( 'Payload rejected — plugin update needed?', 'launchup-sales-connector' );
			default:
				return $fallback;
		}
	}
}
