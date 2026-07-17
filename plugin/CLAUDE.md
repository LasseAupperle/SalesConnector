# Launch Up Sales Connector

WordPress plugin (`launchup-sales-connector`) installed on **every creator WooCommerce shop** of Launch Up. It aggregates sales per month and pushes them to Launch Hub's existing `sales-ingest` Edge Function. It is deliberately a **dumb data pump**: it knows nothing about profit prices, contracts, or creators, and **no customer data ever leaves the shop** — only per-product quantities, revenue totals, and the shop name.

**This file is the hub.** Read the spec for a component before touching it. Build order and gates: `specs/05-build-phases-gates.md`. The Launch Hub side of this project lives in the (private) launch-hub repo as `specs/14-sales-winst-extension.md` and must be built there (phases A0+A1) **before** plugin phase 7 can run end-to-end.

## Spec index

| File | Owns |
|---|---|
| specs/00-architecture.md | Components, data flow, ingest contract v1.1, counting definitions (canonical) |
| specs/01-aggregation-engine.md | Pure aggregation logic, DTOs, rounding, refund rules, tests |
| specs/02-push-scheduler-backfill.md | WooCommerce order source (HPOS), Action Scheduler jobs, push client, backfill, status/log |
| specs/03-settings-admin-ui.md | Settings page, test/push/backfill buttons, notices, i18n, uninstall |
| specs/04-updates-distribution.md | Public GitHub repo, releases, plugin-update-checker, per-shop install guide |
| specs/05-build-phases-gates.md | Phases 0-7 with runnable pass/fail gates |

## Baseline (pinned)

- WordPress **6.9+**, WooCommerce **10.x**, PHP **8.1+** (declared in plugin headers and readme.txt)
- **HPOS-only mindset**: all order access via WooCommerce CRUD APIs (`wc_get_orders`, `WC_Order`, `$order->get_refunds()`). Never query `posts`/`postmeta` for orders. Declare compatibility on `before_woocommerce_init` via `FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)`.
- Scheduling via **Action Scheduler** (bundled with WooCommerce), never raw WP-Cron events.
- Updates via **plugin-update-checker v5** against the public GitHub repo's releases.
- No build step, vanilla PHP. Composer for **dev** dependencies only (phpunit, phpcs with WordPress Coding Standards, wp-env for local integration).

## Conventions

- Namespace `LaunchUp\SalesConnector`, prefix `lusc_` for options/hooks/actions, text domain `launchup-sales-connector`.
- Source strings **English**, ship `nl_NL` translation (all creator shops are Dutch; WP convention keeps source EN).
- Files: `launchup-sales-connector.php` (bootstrap only), `includes/` one class per file (Aggregator, OrderSource, PushClient, Scheduler, StatusStore, SettingsPage, Updater), `uninstall.php`, `languages/`, `lib/plugin-update-checker/`.
- Options: `lusc_settings` (ingest_url, api_key), `lusc_status` (ring-buffer log + last push state). Constant `LUSC_API_KEY` in wp-config.php **overrides** the stored key (recommended for operators; settings page shows "defined in wp-config" state).
- Errors from the ingest endpoint carry Launch Hub `LU-SAL-*` codes; map them to human messages in the log (see specs/02 §5).
- Engine before UI; pure logic classes have **zero** WordPress dependencies so PHPUnit runs without WP.

## Commands

```
composer install
composer test      # phpunit (pure engine + unit)
composer lint      # phpcs (WordPress standards)
npx wp-env start   # local WP+WooCommerce for integration/e2e phases
```

## Ground rules

- The counting definitions in specs/00 §4 are **canonical and shared** with Launch Hub's CSV import — never change them unilaterally. The ingest contract may only change **additively** (never remove/rename fields).
- Privacy: never include customer names, emails, addresses, order notes, coupon codes, or order ids in payloads or logs.
- Respect the out-of-scope list in specs/00 §6. When ambiguous: **stop and ask Lasse** (multiple choice + recommendation).
- Every phase ends with its gate actually run and passing; paste output in the phase commit/PR.
