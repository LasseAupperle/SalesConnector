=== Launch Up Sales Connector ===
Contributors: launchup
Tags: woocommerce, sales, reporting
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 11.0
WC tested up to: 11.1
Stable tag: 1.2.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Aggregates WooCommerce sales per month and pushes them to Launch Hub. No customer data ever leaves the shop.

== Description ==

Internal Launch Up plugin installed on every creator WooCommerce shop. It aggregates sales per month (per parent product, ex VAT, net of refunds) and pushes them to Launch Hub's sales-ingest endpoint. It deliberately knows nothing about profit prices, contracts, or creators, and no customer data ever leaves the shop — only per-product quantities, revenue totals, and the shop name.

== Changelog ==

= 1.2.1 =
* The action buttons now say when you would press them. "Push now" had no explanation at all, and nothing on either button mentioned that the shop also sends its published products — which is what makes them appear in Launch Hub.
* New test: every string the plugin shows must have a Dutch translation. The previous gate checked three by hand, so any new string was guarded by nobody.

= 1.2.0 =
* Baseline moved to WordPress 7.0+ (tested 7.1) and WooCommerce 11.0+ (tested 11.1) — the versions a creator shop actually installs today. The WordPress floor is WooCommerce's own: the plugin uses no 7.x API.
* Every CLI gate now runs against WordPress 7.1 with WooCommerce 11.1.0, both pinned.

= 1.1.0 =
* Contract v1.2: every order line now carries the shop's own product id (`external_ref`, e.g. `wc:412`), so Launch Hub can hold a royalty against a product instead of against its name. A product renamed mid-month stays one product; two products sharing a name stay two.
* The push also sends the shop's **catalogue** — every published parent product — so a product can be priced in Launch Hub before its first sale, which is the order Launch Up actually works in: contract signed, shop built, then royalties filled in.
* Drafts, private and pending products are never sent: an unannounced design is not something to leak into a system other staff read.
* Tested against WooCommerce 10.9.

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
