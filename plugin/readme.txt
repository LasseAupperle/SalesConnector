=== Launch Up Sales Connector ===
Contributors: launchup
Tags: woocommerce, sales, reporting
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.1
WC requires at least: 10.0
WC tested up to: 10.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Aggregates WooCommerce sales per month and pushes them to Launch Hub. No customer data ever leaves the shop.

== Description ==

Internal Launch Up plugin installed on every creator WooCommerce shop. It aggregates sales per month (per parent product, ex VAT, net of refunds) and pushes them to Launch Hub's sales-ingest endpoint. It deliberately knows nothing about profit prices, contracts, or creators, and no customer data ever leaves the shop — only per-product quantities, revenue totals, and the shop name.

== Changelog ==

= 1.0.0 =
* First production release. Verified end to end against Launch Hub on a live creator shop.
* A HTTP 200 without an `imported` count is no longer treated as success: a mistyped ingest URL that happens to answer 200 now fails loudly instead of showing a green "laatste succes".

= 0.7.0 =
* Phase 6: auto-updates from GitHub releases (plugin-update-checker v5), release pipeline, readme lint.

= 0.6.0 =
* Phase 5: settings screen (connection, actions, status log), global failure notice, Dutch translation, clean uninstall.

= 0.5.0 =
* Phase 4: daily 04:00 push, manual push, backfill chain with 6-month chunks, retry ladder (+5m/+30m/+2h), activation/deactivation scheduling.

= 0.4.0 =
* Phase 3: PushClient (contract v1.1 POST, LU-code mapping, connectivity test) and StatusStore (ring-buffer log, key masking).

= 0.3.0 =
* Phase 2: OrderSource (HPOS-safe WooCommerce CRUD streaming, variation rollup, refund mapping) with wp-env fixture seed and DTO-dump + memory gates.

= 0.2.0 =
* Phase 1: pure aggregation engine (DTOs, PeriodAggregator, PayloadBuilder) with fixture, property, and snapshot test suite.

= 0.1.0 =
* Phase 0 scaffold: plugin skeleton, HPOS compatibility declaration, WooCommerce-inactive guard, CI.
