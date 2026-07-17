<?php
/**
 * OrderSource — the only WooCommerce-touching class (specs/02 §1).
 *
 * Streams orders for a window as plain OrderData DTOs via the WooCommerce
 * CRUD APIs (HPOS-safe by definition). Never queries posts/postmeta.
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector;

use LaunchUp\SalesConnector\Dto\LineItem;
use LaunchUp\SalesConnector\Dto\OrderData;
use LaunchUp\SalesConnector\Dto\RefundLine;

/**
 * Maps WooCommerce orders to DTOs, resolving variations to parent products.
 */
final class OrderSource {

	private const PAGE_SIZE = 100;

	/**
	 * Optional logger for data-quality notes (deleted products etc.).
	 *
	 * @var callable|null
	 */
	private $log;

	/**
	 * Cache of resolved parent names, keyed by product id.
	 *
	 * @var array<int, string>
	 */
	private array $parentNames = array();

	/**
	 * Constructor.
	 *
	 * @param callable|null $log Optional callable( string $message ): void.
	 */
	public function __construct( ?callable $log = null ) {
		$this->log = $log;
	}

	/**
	 * Stream counted-status orders created inside the window as DTOs.
	 *
	 * @param \DateTimeImmutable $windowStart Inclusive start.
	 * @param \DateTimeImmutable $windowEnd   Inclusive end.
	 * @return \Generator Yields OrderData.
	 */
	public function ordersForWindow( \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd ): \Generator {
		$paged = 1;
		do {
			$orders = wc_get_orders(
				array(
					'status'       => array( 'completed', 'processing' ),
					'date_created' => $windowStart->getTimestamp() . '...' . $windowEnd->getTimestamp(),
					'limit'        => self::PAGE_SIZE,
					'paged'        => $paged,
					'orderby'      => 'ID',
					'order'        => 'ASC',
				)
			);

			foreach ( $orders as $order ) {
				yield $this->mapOrder( $order );
			}

			$count  = count( $orders );
			$orders = null;
			++$paged;
		} while ( self::PAGE_SIZE === $count );
	}

	/**
	 * Map one WC_Order to an OrderData DTO.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function mapOrder( \WC_Order $order ): OrderData {
		$createdAt = ( new \DateTimeImmutable( '@' . $order->get_date_created()->getTimestamp() ) )
			->setTimezone( wp_timezone() );

		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			list( $parentId, $name ) = $this->resolveParent( $item );

			/*
			 * Fees and shipping are NOT revenue: only product line items count
			 * ("omzet per product", specs/02 §1). get_total() is ex tax, after
			 * discounts.
			 */
			$items[] = new LineItem( $parentId, $name, (int) $item->get_quantity(), (float) $item->get_total() );
		}

		$refundLines = array();
		$unallocated = 0.0;
		foreach ( $order->get_refunds() as $refund ) {
			$refundTotalExTax = abs( (float) $refund->get_total() ) - abs( (float) $refund->get_total_tax() );
			$allocated        = 0.0;

			foreach ( $refund->get_items() as $refundItem ) {
				if ( ! $refundItem instanceof \WC_Order_Item_Product ) {
					continue;
				}
				list( $parentId, $name ) = $this->resolveParent( $refundItem );

				$amount        = abs( (float) $refundItem->get_total() );
				$allocated    += $amount;
				$refundLines[] = new RefundLine( $parentId, $name, abs( (int) $refundItem->get_quantity() ), $amount );
			}

			// Remainder without product lines (incl. shipping-only refunds) is unallocated.
			$remainder = $refundTotalExTax - $allocated;
			if ( $remainder > 0.005 ) {
				$unallocated += $remainder;
			}
		}

		return new OrderData( $createdAt, $order->get_status(), $items, $refundLines, $unallocated );
	}

	/**
	 * Resolve an order item to (parent product id, parent product name).
	 *
	 * Variations roll up to their parent; a deleted product falls back to the
	 * order item name with id 0 and a log note (specs/02 §1).
	 *
	 * @param \WC_Order_Item_Product $item Order (or refund) line item.
	 * @return array{0:int, 1:string}
	 */
	private function resolveParent( \WC_Order_Item_Product $item ): array {
		$product = $item->get_product();
		if ( ! $product ) {
			$this->note( sprintf( 'product for order item "%s" no longer exists; counted under id 0', $item->get_name() ) );
			return array( 0, $item->get_name() );
		}

		$parentId = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();

		if ( ! isset( $this->parentNames[ $parentId ] ) ) {
			if ( $parentId === $product->get_id() ) {
				$this->parentNames[ $parentId ] = $product->get_name();
			} else {
				$parent = wc_get_product( $parentId );
				if ( ! $parent ) {
					$this->note( sprintf( 'parent product of order item "%s" no longer exists; counted under id 0', $item->get_name() ) );
					return array( 0, $item->get_name() );
				}
				$this->parentNames[ $parentId ] = $parent->get_name();
			}
		}

		return array( $parentId, $this->parentNames[ $parentId ] );
	}

	/**
	 * Emit a data-quality note when a logger is attached.
	 *
	 * @param string $message The note.
	 */
	private function note( string $message ): void {
		if ( null !== $this->log ) {
			( $this->log )( $message );
		}
	}
}
