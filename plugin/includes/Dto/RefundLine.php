<?php
/**
 * Refund line DTO — refunded units/amount for one parent product.
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector\Dto;

/**
 * A refund's product line, attributed to the original order's month.
 */
final class RefundLine {

	/**
	 * Constructor.
	 *
	 * @param int    $parentProductId Variation resolved to parent; simple product = own id.
	 * @param string $productName     Parent product name.
	 * @param int    $quantity        Refunded units, ≥ 0.
	 * @param float  $amountExTax     Refunded amount ex tax for these lines, ≥ 0.
	 */
	public function __construct(
		public readonly int $parentProductId,
		public readonly string $productName,
		public readonly int $quantity,
		public readonly float $amountExTax,
	) {}
}
