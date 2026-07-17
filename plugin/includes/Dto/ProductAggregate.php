<?php
/**
 * Product aggregate — per-parent-product totals within one period.
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector\Dto;

/**
 * Mutable aggregation cell for one parent product in one month.
 */
final class ProductAggregate {

	/**
	 * Parent product name.
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * Net units sold (after refunds, clamped ≥ 0).
	 *
	 * @var int
	 */
	public int $quantity;

	/**
	 * Net revenue ex tax (after refunds, clamped ≥ 0). Full precision internally.
	 *
	 * @var float
	 */
	public float $revenue;
}
