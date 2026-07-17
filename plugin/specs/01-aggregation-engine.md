# 01 · Aggregation engine (pure PHP)

Everything here runs without WordPress loaded. This is the phase-1 deliverable and the most-tested code in the plugin.

## 1. DTO contracts (`includes/Dto/`)

```php
final class LineItem {
    public function __construct(
        public readonly int $parentProductId,   // variation resolved to parent; simple product = own id
        public readonly string $productName,    // parent product name
        public readonly int $quantity,          // ≥ 1
        public readonly float $lineTotalExTax,  // after discounts, ex tax
    ) {}
}

final class RefundLine {
    public function __construct(
        public readonly int $parentProductId,
        public readonly string $productName,
        public readonly int $quantity,          // ≥ 0 (refunded units)
        public readonly float $amountExTax,     // ≥ 0 (refunded amount ex tax for these lines)
    ) {}
}

final class OrderData {
    public function __construct(
        public readonly \DateTimeImmutable $createdAt,  // in shop timezone already
        public readonly string $status,                 // 'completed' | 'processing' | other
        /** @var LineItem[] */    public readonly array $items,
        /** @var RefundLine[] */  public readonly array $refundLines,
        public readonly float $unallocatedRefundExTax,  // amount-only refunds (no line items), ≥ 0
    ) {}
}

final class ProductAggregate { public string $name; public int $quantity; public float $revenue; }

final class PeriodAggregate {
    public string $periodStart;      // 'Y-m-01'
    public string $periodEnd;        // 'Y-m-t'
    public float $revenue;           // ex tax, net of refunds, ≥ can be negative only via unallocated refunds — clamp: never below 0, log if clamped
    public int $orders;
    public ?float $avgOrderValue;    // null when orders = 0
    public int $itemsTotal;          // Σ products[].quantity, never negative (clamp per product at 0, log)
    /** @var ProductAggregate[] */ public array $products;  // sorted by revenue desc, then name asc
    /** @var string[] */ public array $notes;               // e.g. 'unallocated refund €12.50 applied', 'clamped negative qty for X'
}
```

## 2. Aggregator contract

```php
final class PeriodAggregator {
    /** @param string[] $countedStatuses default ['completed','processing']
     *  @param iterable<OrderData> $orders (any order, any months)
     *  @return array<string, PeriodAggregate> keyed 'Y-m', only months that had ≥1 counted order or refund effect */
    public function aggregate(iterable $orders, array $countedStatuses = ['completed','processing']): array;
}
```

Rules (implementing specs/00 §4 exactly):

1. Skip orders whose status is not counted (their refunds are skipped too).
2. Bucket by `createdAt` month. Per bucket: `orders`++ per order; per line item add quantity + lineTotalExTax to the parent-product aggregate; per refund line subtract from the same parent product **in the order's bucket**; subtract `unallocatedRefundExTax` from bucket revenue and append a note.
3. Clamping: a product's quantity or revenue never goes below 0 (over-refund edge) — clamp at 0 and append a note; period revenue clamps at 0.00 with a note.
4. `itemsTotal` is derived as Σ product quantities **after** clamping — this keeps the contract invariant true by construction.
5. Products with quantity 0 **and** revenue 0.00 after netting are dropped from the list.
6. Round to 2 decimals half-up only when serializing (a `toPayload()` on PeriodAggregate); internal math stays float-full-precision (differences are cents-level; acceptable, asserted in tests with delta 0.005).
7. Deterministic output: stable sort (revenue desc, name asc); iteration order of input never matters.

## 3. Payload builder

`PayloadBuilder::build(string $shopIdentifier, string $shopName, string $apiKey, array $aggregates): array` → the exact contract-v1.1 array from specs/00 §2 (contract '1.1', dates as strings, floats rounded). Pure, snapshot-tested.

## 4. Tests (phase-1 gate)

- **Fixture table tests** (`tests/fixtures/*.php` returning OrderData sets + expected JSON): simple month; variations rolling up to one parent; multi-month set; partial refund (2 of 5 shirts); full refund (product drops out); amount-only refund; over-refund clamp; excluded statuses ignored; empty input → empty array; order on month boundary 23:59 shop time lands in correct month.
- **Property tests** (simple loop-based randomizer is fine, ~200 iterations): shuffling/splitting the same order list never changes output; duplicating the aggregate call is idempotent; invariant `itemsTotal == Σ quantities` holds on every generated case; no negative numbers ever serialize.
- **Snapshot**: PayloadBuilder output for the canonical fixture matches `tests/fixtures/expected-payload.json` byte-for-byte.

Gate: `composer test` green; mutation of any counting rule breaks at least one test (spot-check by temporarily flipping the status filter — then revert).
