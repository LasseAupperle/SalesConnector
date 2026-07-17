<?php
/**
 * Order DTO — everything the aggregation engine needs from one order.
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector\Dto;

/**
 * A single order as plain data (no WC types).
 */
final class OrderData {

	/**
	 * Constructor.
	 *
	 * @param \DateTimeImmutable $createdAt              Creation time, already in shop timezone.
	 * @param string             $status                 'completed' | 'processing' | other.
	 * @param LineItem[]         $items                  Product lines.
	 * @param RefundLine[]       $refundLines            Refund product lines.
	 * @param float              $unallocatedRefundExTax Amount-only refunds (no line items), ≥ 0.
	 */
	public function __construct(
		public readonly \DateTimeImmutable $createdAt,
		public readonly string $status,
		public readonly array $items,
		public readonly array $refundLines,
		public readonly float $unallocatedRefundExTax,
	) {}
}
