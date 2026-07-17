<?php
/**
 * Period aggregate — one month of aggregated sales.
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector\Dto;

/**
 * One month's aggregate. Internal math keeps full float precision; rounding
 * to 2 decimals (half-up) happens only in toPayload() (specs/01 §2 rule 6).
 */
final class PeriodAggregate {

	/**
	 * First day of the month, 'Y-m-01'.
	 *
	 * @var string
	 */
	public string $periodStart;

	/**
	 * Last day of the month, 'Y-m-t'.
	 *
	 * @var string
	 */
	public string $periodEnd;

	/**
	 * Revenue ex tax, net of refunds, clamped ≥ 0 (a clamp adds a note).
	 *
	 * @var float
	 */
	public float $revenue;

	/**
	 * Number of counted-status orders created in the period.
	 *
	 * @var int
	 */
	public int $orders;

	/**
	 * Revenue / orders; null when orders = 0.
	 *
	 * @var float|null
	 */
	public ?float $avgOrderValue;

	/**
	 * Σ products[].quantity after clamping — invariant with the contract.
	 *
	 * @var int
	 */
	public int $itemsTotal;

	/**
	 * Product aggregates, sorted by revenue desc then name asc.
	 *
	 * @var ProductAggregate[]
	 */
	public array $products = array();

	/**
	 * Human-readable aggregation notes (unallocated refunds, clamps).
	 *
	 * @var string[]
	 */
	public array $notes = array();

	/**
	 * Serialize to the contract-v1.1 period shape, rounding to 2 decimals half-up.
	 *
	 * @return array<string, mixed>
	 */
	public function toPayload(): array {
		$products = array();
		foreach ( $this->products as $product ) {
			$products[] = array(
				'name'     => $product->name,
				'quantity' => $product->quantity,
				'revenue'  => round( $product->revenue, 2 ),
			);
		}

		return array(
			'period_start'    => $this->periodStart,
			'period_end'      => $this->periodEnd,
			'revenue'         => round( $this->revenue, 2 ),
			'orders'          => $this->orders,
			'avg_order_value' => null === $this->avgOrderValue ? null : round( $this->avgOrderValue, 2 ),
			'items_total'     => $this->itemsTotal,
			'products'        => $products,
		);
	}
}
