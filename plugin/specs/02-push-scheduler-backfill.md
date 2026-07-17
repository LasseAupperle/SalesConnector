# 02 · Order source, scheduler, backfill & push

## 1. OrderSource (`includes/OrderSource.php`) — the only WooCommerce-touching class

`public function ordersForWindow(\DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): \Generator // yields OrderData`

- Query via `wc_get_orders([ 'status' => ['completed','processing'], 'date_created' => $start->getTimestamp() . '...' . $end->getTimestamp(), 'limit' => 100, 'paged' => $n, 'orderby' => 'ID', 'order' => 'ASC' ])`, paging until exhausted. HPOS-safe by definition (CRUD layer); never `WP_Query`/`get_posts` for orders.
- Mapping to DTOs: `createdAt` = `$order->get_date_created()` converted to `wp_timezone()`. Line items: resolve variation → parent (`$product->get_parent_id() ?: $product->get_id()`, name via parent product; if the product was deleted, fall back to the order item name and id 0 with a log note). `lineTotalExTax = (float) $item->get_total()` (WooCommerce stores line totals ex tax, after discounts).
- Refunds: `$order->get_refunds()`; per refund, line items with product references become `RefundLine` (quantity = abs, amount = abs ex tax); refund remainder without line items sums into `unallocatedRefundExTax`.
- Fees/shipping are **not** revenue: only product line items count (state this in a code comment; matches "omzet per product" intent). Shipping refunds land in unallocated only if they arrive without product lines — acceptable, noted.
- Memory: generator + `$order = null` per iteration; target: 10k orders under 256MB.

## 2. Scheduler (`includes/Scheduler.php`, Action Scheduler)

- **Recurring daily push** `lusc_daily_push`: registered on activation (and self-healing check on `admin_init` if missing), next run at 04:00 in `wp_timezone()`, interval DAY_IN_SECONDS, group `lusc`. DST note: a fixed day interval drifts ±1 h across DST switches — acceptable (the job is idempotent and time-uncritical); do not over-engineer this.
  Handler: window = first day of (current month − 2) 00:00 → now; run OrderSource → PeriodAggregator → PushClient with the resulting ≤3 month periods; store result.
- **Manual push** `lusc_push_now`: single async action enqueued by the settings button; same handler (window-only by design; full history = re-run the backfill).
- **Backfill** `lusc_backfill_chunk(offsetMonths, chunkSize=6)`: chain of single actions. Start point: settings button (specs/03) determines the oldest counted order via `wc_get_orders(['limit'=>1,'orderby'=>'date','order'=>'ASC'])`; chunks of 6 months are scheduled oldest→newest, each chunk aggregates+pushes its months, then schedules the next chunk until reaching the rolling window (which the daily job owns). Re-running backfill is safe (idempotent upserts on the Hub) and doubles as the **full re-sync** for months outside the rolling window (e.g. a refund on an order older than two months).
- **Retries**: on push failure the handler schedules a retry single action (+5 min, then +30 min, then +2 h; attempt counter in args). After the 3rd failed retry, mark run failed in StatusStore (feeds the admin notice in specs/03) and stop — the next daily run tries fresh.
- Timeouts: chunk work stays under typical PHP limits by paging; if a single month exceeds 5k orders, aggregate in-place per page (aggregator accepts iterables, so this is free).

## 3. PushClient (`includes/PushClient.php`)

`public function push(array $payload): PushResult` using `wp_remote_post($url, ['timeout' => 15, 'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($payload)])`.

`PushResult`: `ok bool`, `httpCode ?int`, `imported ?int`, `test bool`, `luCode ?string`, `message string`, `warnings string[]`. Mapping: transport error → ok=false, message from WP_Error; 200 → parse imported/test/warnings; 4xx/5xx → parse `{code,message}` into luCode/message. **Human messages for known codes** (translated): LU-SAL-002 "API-key ongeldig of ingetrokken — maak een nieuwe aan in Launch Hub", LU-SAL-003 "Deze key hoort bij een andere shop-URL", LU-SAL-004 "Payload afgekeurd — plugin-update nodig?". Warnings (e.g. items invariant) are logged prominently: they mean a bug.

`public function test(): PushResult` — same POST with `periods: []`.

API key resolution order: constant `LUSC_API_KEY` → option. Never log the key; log its last 4 chars only.

## 4. StatusStore (`includes/StatusStore.php`)

Option `lusc_status`: `{ last_success_at, last_attempt_at, last_result: 'ok'|'failed'|'never', consecutive_failures, log: [ {time, kind: daily|manual|backfill|test, window, periods, http, lu_code, message} ] }` — ring buffer, max 10 entries, newest first. Pure array in/out; rendered by settings page.

## 5. Bootstrap requirements (`launchup-sales-connector.php`)

Plugin headers: Requires at least 6.9 · Requires PHP 8.1 · WC requires at least 10.0 · WC tested up to (fill at release). On `before_woocommerce_init`: `FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)`. Hard guard: if WooCommerce inactive → admin notice + no-op (never fatal). Activation hook: schedule daily push; deactivation: unschedule all `lusc` group actions.

## 6. Acceptance criteria (phases 2-4 gates)

- **Phase 2 (source)**: wp-env seeded via a fixture script (WP-CLI: 1 simple + 1 variable product with 2 variations; orders across 3 months incl. one partial refund and one amount-only refund; one `pending` order that must be ignored) → `ordersForWindow` DTO dump matches `tests/fixtures/wp-env-expected.json`; HPOS enabled in wp-env and compatibility declared (no incompatibility notice).
- **Phase 3 (push)**: against a local mock endpoint (tiny PHP script in wp-env asserting received JSON): success, each LU-code path, transport failure, warning path; key masking in logs verified; `test()` sends empty periods.
- **Phase 4 (scheduler)**: daily action visible under WooCommerce → Status → Scheduled Actions; simulated double run of the same window produces byte-identical payloads (idempotent); retry ladder fires at +5/+30/+120 (assert scheduled timestamps); backfill on the fixture shop produces exactly the chunks oldest→window and stops; consecutive_failures increments and resets correctly.
