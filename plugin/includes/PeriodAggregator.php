<?php
/**
 * PeriodAggregator — pure aggregation of orders into monthly aggregates.
 *
 * Implements the canonical counting definitions of specs/00 §4 exactly.
 * Imports nothing from WordPress/WooCommerce; fully unit-tested (specs/01).
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector;

use LaunchUp\SalesConnector\Dto\OrderData;
use LaunchUp\SalesConnector\Dto\PeriodAggregate;
use LaunchUp\SalesConnector\Dto\ProductAggregate;

/**
 * Aggregates OrderData into one PeriodAggregate per month.
 *
 * Deterministic: iteration order of the input never changes the output
 * (notes included — all notes are generated in the finalize step, never
 * during input iteration).
 */
final class PeriodAggregator {

	/**
	 * Aggregate orders into monthly buckets.
	 *
	 * @param iterable $orders          OrderData items, in any order, any months.
	 * @param string[] $countedStatuses Statuses that count (specs/00 §4.1).
	 * @return array<string, PeriodAggregate> Keyed 'Y-m' ascending; only months with ≥ 1 counted order.
	 */
	public function aggregate( iterable $orders, array $countedStatuses = array( 'completed', 'processing' ) ): array {
		/*
		 * Working buckets, keyed 'Y-m'. Product cells keyed "id|name" so a
		 * deleted-product fallback (id 0, order-item name) never merges
		 * distinct products, and a mid-month rename yields two honest rows.
		 */
		$buckets = array();

		foreach ( $orders as $order ) {
			if ( ! in_array( $order->status, $countedStatuses, true ) ) {
				continue; // Skipped status: its refunds are skipped too (specs/01 §2 rule 1).
			}

			$ym = $order->createdAt->format( 'Y-m' );
			if ( ! isset( $buckets[ $ym ] ) ) {
				$buckets[ $ym ] = array(
					'orders'      => 0,
					'products'    => array(),
					'unallocated' => 0.0,
				);
			}
			$bucket = &$buckets[ $ym ];

			++$bucket['orders'];

			foreach ( $order->items as $item ) {
				/*
				 * Keyed on the parent id ALONE, not id|name.
				 *
				 * It used to include the name, which split a product renamed mid-month into two
				 * cells. That was harmless while a product WAS its name — but both cells now
				 * carry the same external_ref, and Launch Hub joins per-product prices on that:
				 * two entries with one id means the royalty is counted twice, in money, silently.
				 * One product, one cell. The name below is whichever the shop reported last.
				 */
				$key = (string) $item->parentProductId;
				if ( ! isset( $bucket['products'][ $key ] ) ) {
					$bucket['products'][ $key ] = array(
						'parent_id' => $item->parentProductId,
						'name'      => $item->productName,
						'quantity'  => 0,
						'revenue'   => 0.0,
					);
				}
				$bucket['products'][ $key ]['name']      = $item->productName;
				$bucket['products'][ $key ]['quantity'] += $item->quantity;
				$bucket['products'][ $key ]['revenue']  += $item->lineTotalExTax;
			}

			foreach ( $order->refundLines as $refund ) {
				$key = (string) $refund->parentProductId;
				if ( ! isset( $bucket['products'][ $key ] ) ) {
					$bucket['products'][ $key ] = array(
						'parent_id' => $refund->parentProductId,
						'name'      => $refund->productName,
						'quantity'  => 0,
						'revenue'   => 0.0,
					);
				}
				$bucket['products'][ $key ]['quantity'] -= $refund->quantity;
				$bucket['products'][ $key ]['revenue']  -= $refund->amountExTax;
			}

			$bucket['unallocated'] += $order->unallocatedRefundExTax;
			unset( $bucket );
		}

		ksort( $buckets, SORT_STRING );

		$result = array();
		foreach ( $buckets as $ym => $bucket ) {
			$result[ $ym ] = $this->finalize( (string) $ym, $bucket );
		}

		return $result;
	}

	/**
	 * Turn a working bucket into a PeriodAggregate: clamp, drop empty rows,
	 * derive totals, sort, and generate all notes deterministically.
	 *
	 * @param string               $ym     Month key 'Y-m'.
	 * @param array<string, mixed> $bucket Working bucket.
	 */
	private function finalize( string $ym, array $bucket ): PeriodAggregate {
		$notes = array();

		// Deterministic note/clamp order: process product cells sorted by key.
		ksort( $bucket['products'], SORT_STRING );

		$products = array();
		foreach ( $bucket['products'] as $cell ) {
			if ( $cell['quantity'] < 0 ) {
				$notes[]          = sprintf( 'clamped negative quantity for %s', $cell['name'] );
				$cell['quantity'] = 0;
			}
			if ( $cell['revenue'] < 0 ) {
				$notes[]         = sprintf( 'clamped negative revenue for %s', $cell['name'] );
				$cell['revenue'] = 0.0;
			}
			// Fully netted-out products (e.g. a full refund) drop from the list (rule 5).
			if ( 0 === $cell['quantity'] && abs( $cell['revenue'] ) < 0.005 ) {
				continue;
			}
			$product                  = new ProductAggregate();
			$product->parentProductId = (int) $cell['parent_id'];
			$product->name            = $cell['name'];
			$product->quantity        = $cell['quantity'];
			$product->revenue         = $cell['revenue'];
			$products[]               = $product;
		}

		// Stable sort: revenue desc, then name asc (rule 7).
		usort(
			$products,
			static function ( ProductAggregate $a, ProductAggregate $b ): int {
				$by_revenue = $b->revenue <=> $a->revenue;
				return 0 !== $by_revenue ? $by_revenue : strcmp( $a->name, $b->name );
			}
		);

		$revenue = 0.0;
		$items   = 0;
		foreach ( $products as $product ) {
			$revenue += $product->revenue;
			$items   += $product->quantity;
		}

		if ( $bucket['unallocated'] > 0 ) {
			$notes[]  = sprintf( 'unallocated refund €%.2f applied', $bucket['unallocated'] );
			$revenue -= $bucket['unallocated'];
		}

		// Period revenue can go negative only via unallocated refunds; clamp at 0.00 (rule 3).
		if ( $revenue < 0 ) {
			$notes[] = 'clamped negative period revenue';
			$revenue = 0.0;
		}

		$aggregate                = new PeriodAggregate();
		$aggregate->periodStart   = $ym . '-01';
		$aggregate->periodEnd     = ( new \DateTimeImmutable( $ym . '-01' ) )->format( 'Y-m-t' );
		$aggregate->revenue       = $revenue;
		$aggregate->orders        = $bucket['orders'];
		$aggregate->avgOrderValue = $bucket['orders'] > 0 ? $revenue / $bucket['orders'] : null;
		$aggregate->itemsTotal    = $items;
		$aggregate->products      = $products;
		$aggregate->notes         = $notes;

		return $aggregate;
	}
}
