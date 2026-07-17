=== Launch Up Sales Connector ===
Contributors: launchup
Tags: woocommerce, sales, reporting
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.1
WC requires at least: 10.0
WC tested up to: 10.0
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Aggregates WooCommerce sales per month and pushes them to Launch Hub. No customer data ever leaves the shop.

== Description ==

Internal Launch Up plugin installed on every creator WooCommerce shop. It aggregates sales per month (per parent product, ex VAT, net of refunds) and pushes them to Launch Hub's sales-ingest endpoint. It deliberately knows nothing about profit prices, contracts, or creators, and no customer data ever leaves the shop — only per-product quantities, revenue totals, and the shop name.

== Changelog ==

= 0.1.0 =
* Phase 0 scaffold: plugin skeleton, HPOS compatibility declaration, WooCommerce-inactive guard, CI.
