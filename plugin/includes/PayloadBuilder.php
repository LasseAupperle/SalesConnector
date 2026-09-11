<?php
/**
 * PayloadBuilder — serializes aggregates into the ingest contract v1.1 body.
 *
 * Pure (no WordPress imports); snapshot-tested (specs/01 §3).
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector;

use LaunchUp\SalesConnector\Dto\PeriodAggregate;

/**
 * Builds the exact contract-v1.1 array from specs/00 §2.
 */
final class PayloadBuilder {

	/**
	 * Build the ingest request body.
	 *
	 * @param string                           $shopIdentifier Exact site_url().
	 * @param string                           $shopName       get_bloginfo('name').
	 * @param string                           $apiKey         Launch Hub API key (lu_sk_…).
	 * @param array<string, PeriodAggregate>   $aggregates     Keyed 'Y-m' (any order; sorted here).
	 * @param array<int, array<string, mixed>> $catalogue Published products (specs/06 §2), or empty.
	 * @return array<string, mixed>
	 */
	public static function build(
		string $shopIdentifier,
		string $shopName,
		string $apiKey,
		array $aggregates,
		array $catalogue = array()
	): array {
		ksort( $aggregates, SORT_STRING );

		$periods = array();
		foreach ( $aggregates as $aggregate ) {
			$periods[] = $aggregate->toPayload();
		}

		$payload = array(
			'contract'        => '1.2',
			'shop_identifier' => $shopIdentifier,
			'shop_name'       => $shopName,
			'api_key'         => $apiKey,
			'periods'         => $periods,
		);

		/*
		 * OMITTED rather than sent empty when there is nothing to say (specs/06 §2).
		 *
		 * An empty array and "I did not look" are different claims, and Launch Hub treats a
		 * catalogue-bearing payload as a real push rather than a connectivity test. Sending
		 * `catalogue: []` from the Test-connection button would turn a probe into an import.
		 */
		if ( array() !== $catalogue ) {
			$payload['catalogue'] = $catalogue;
		}

		return $payload;
	}
}
