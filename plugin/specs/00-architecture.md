# 00 · Architecture, contract v1.1 & counting definitions

## 1. Components & data flow

```
Action Scheduler ──▶ Scheduler
                      │  window = current month + 2 previous (rolling)
                      ▼
                  OrderSource (WooCommerce CRUD, HPOS-safe)
                      │  iterable<OrderData> (plain DTOs, no WC types)
                      ▼
                  PeriodAggregator (pure PHP, unit-tested)
                      │  PeriodAggregate[] (one per month)
                      ▼
                  PushClient ──POST──▶ Launch Hub sales-ingest (Edge Function)
                      │
                      ▼
                  StatusStore (last push, ring-buffer log) ──▶ Settings page panel
```

Separation rule: `PeriodAggregator` and its DTOs import **nothing** from WordPress/WooCommerce. `OrderSource` is the only class touching WC. `PushClient` is the only class doing HTTP. Adding a metric later = extend DTO + aggregator + payload additively.

## 2. Ingest contract v1.1 (single source of truth: launch-hub repo specs/14 §3 — keep this copy in sync manually)

> **Amended by `specs/06`:** contract **v1.2** adds `external_ref` to every `products[]` entry and an optional top-level `catalogue[]`. Additive only — a 1.1 payload stays valid and a Launch Hub that predates the change ignores the new fields. The Launch Hub side is `specs/16` in the launch-hub repo and must be deployed first.

`POST {ingest_url}` with JSON:

```json
{
  "contract": "1.1",
  "shop_identifier": "https://shopnaam.nl",
  "shop_name": "esoo Merch",
  "api_key": "lu_sk_…",
  "periods": [
    {
      "period_start": "2026-06-01",
      "period_end": "2026-06-30",
      "revenue": 1234.56,
      "orders": 42,
      "avg_order_value": 29.39,
      "items_total": 87,
      "products": [
        { "name": "esoo Shirt zwart", "quantity": 51, "revenue": 765.00 },
        { "name": "esoo Hoodie", "quantity": 36, "revenue": 469.56 }
      ]
    }
  ]
}
```

- `shop_identifier` = exact `site_url()`; `shop_name` = `get_bloginfo('name')`.
- All numeric fields nullable per contract v1; this plugin always fills them all.
- **`periods: []` (empty array) = connectivity test**: the endpoint validates key + shop match and returns `200 {"imported": 0, "test": true}`.
- Invariant: `items_total` == Σ `products[].quantity` (endpoint returns a `warnings[]` entry on mismatch; treat any warning as a bug).
- Success: `200 {"imported": n}`. Errors: `{code, message}` with `LU-SAL-002` (key invalid/revoked), `LU-SAL-003` (shop mismatch), `LU-SAL-004` (schema invalid). Limits: ≤100 periods/request, ≤60 requests/hour/key.
- Upsert key on the Hub side: `(contact, period_start, period_end, source='plugin')`; plugin data always overrides CSV data for the same period.

## 3. Granularity

**Month periods only** (first…last day of month, shop timezone). The daily job re-upserts the **current + two previous months** so late refunds keep closed months truthful. No day rows exist (mixed granularity would double-count on the Hub dashboard). Day granularity is a possible future additive change, not now.

## 4. Canonical counting definitions (identical to Launch Hub's CSV import — never diverge)

1. **Counted statuses**: `completed` + `processing` only.
2. **Per parent product**: variations roll up into their parent product (parent id + parent name). Simple products count as themselves.
3. **Revenue excl. BTW**: line item totals **ex tax, after discounts** (`$item->get_total()`); order-level revenue = Σ counted line totals ex tax.
4. **Net of refunds, allocated to the original order's month**: refund line items subtract quantity + amount from the matching parent product in the order's period; amount-only refunds (no line items) subtract from period revenue only, quantities untouched, and are noted in the log.
5. **Orders count** = number of counted-status orders created in the period (refunds never reduce the order count). `avg_order_value = revenue / orders`, null when orders = 0.
6. **Currency**: EUR assumed (shops are Dutch); non-EUR is out of scope and logged as a config warning.
7. **Timezone**: month boundaries in the shop's `wp_timezone()`; operating assumption Europe/Amsterdam.
8. Rounding: keep full precision during aggregation, round to 2 decimals (half-up) only in the final payload.
9. **Hub CSV imports are an approximation** (order-total columns often include shipping/fees; refunds are unreliable in exports). This plugin's line-item-based numbers are the source of truth and override CSV periods on the Hub.

## 5. What the plugin never does

Compute or know profit/earnings (that is Launch Hub, using staff-set prices) · send customer PII, order ids, coupon codes, notes · write anything into Launch Hub except via the ingest contract · delete or mutate shop data.

## 6. Out of scope

Realtime per-order push (future additive) · multisite · currencies other than EUR · visitor/traffic stats · creator payouts or invoicing · day-level granularity · pulling data *from* Launch Hub.
