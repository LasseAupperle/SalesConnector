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
	 * WooCommerce parent product id — the shop's own identity for this product.
	 *
	 * Sent as `wc:<id>` so Launch Hub can keep a royalty attached to a product across a rename
	 * (its specs/16 §1.1). 0 is the bucket for order lines whose product has been deleted from
	 * the shop; Launch Hub keeps its revenue but refuses to price it, because it is not one
	 * product.
	 *
	 * @var int
	 */
	public int $parentProductId;

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
