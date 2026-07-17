# Launch Up Sales Connector

WordPress plugin (`launchup-sales-connector`) installed on every Launch Up creator WooCommerce shop. It aggregates sales **per month** (per parent product, ex VAT, net of refunds) and pushes them to Launch Hub's `sales-ingest` endpoint. It is deliberately a dumb data pump: it knows nothing about profit prices, contracts, or creators, and **no customer data ever leaves the shop**.

## Repo layout

| Path | What |
|---|---|
| `plugin/` | The WordPress plugin (source, tests, specs). Start at `plugin/CLAUDE.md`. |
| `plugin/specs/` | Authoritative feature specs (00-05). Build order: `plugin/specs/05-build-phases-gates.md`. |
| `hub-addendum/` | Spec addendum to apply to the separate **launch-hub** repo (profit prices, ingest v1.1, dashboards). |
| `.wp-env.json` | Local/CI WordPress + WooCommerce environment (WP 6.9, PHP 8.1). |

## Development

```
cd plugin
composer install
composer test      # phpunit (pure engine + unit)
composer lint      # phpcs (WordPress Coding Standards)
npx wp-env start   # local WP+WooCommerce (Docker) for integration phases
```

CI (GitHub Actions) runs phpcs + phpunit on PHP 8.1 and 8.3, plus the wp-env integration gates, on every push and PR.

## Status

Phase 0 (scaffold & CI) — in progress. Release flow and the per-shop install guide land in phase 6 (see `plugin/specs/04-updates-distribution.md`).
