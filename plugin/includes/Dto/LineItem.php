<?php
/**
 * Line item DTO — one counted product line of an order.
 *
 * Pure data, no WordPress/WooCommerce imports (specs/00 §1 separation rule).
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector\Dto;

/**
 * One order line, with variations already resolved to their parent product.
 */
final class LineItem {

	/**
	 * Constructor.
	 *
	 * @param int    $parentProductId Variation resolved to parent; simple product = own id.
	 * @param string $productName     Parent product name.
	 * @param int    $quantity        Units sold, ≥ 1.
	 * @param float  $lineTotalExTax  Line total after discounts, ex tax.
	 */
	public function __construct(
		public readonly int $parentProductId,
		public readonly string $productName,
		public readonly int $quantity,
		public readonly float $lineTotalExTax,
	) {}
}
