# Launch Up Sales Connector

WordPress plugin (`launchup-sales-connector`) installed on every Launch Up creator WooCommerce shop. It aggregates sales **per month** (per parent product, ex VAT, net of refunds) and pushes them to Launch Hub's `sales-ingest` endpoint. It is deliberately a dumb data pump: it knows nothing about profit prices, contracts, or creators, and **no customer data ever leaves the shop**.

## Repo layout

| Path | What |
|---|---|
| `plugin/` | The WordPress plugin (source, tests, specs). Start at `plugin/CLAUDE.md`. |
| `plugin/specs/` | Authoritative feature specs (00-05). Build order: `plugin/specs/05-build-phases-gates.md`. |
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

## Release flow

Shipping a new version to every creator shop:

1. Bump `Version:` in the plugin header (`plugin/launchup-sales-connector.php`) **and** `Stable tag:` in `plugin/readme.txt`. Add a changelog entry and update `WC tested up to`.
2. Pick the version number with semver:
   - **minor** (`0.X.0`) — additive payload fields or new features.
   - **patch** (`0.0.X`) — bug fixes.
   - **major** (`X.0.0`) — a change to a counting definition (what/how sales are counted). Requires a Launch Hub decision **first** (see the CLAUDE ground rules), because it changes the numbers Launch Hub receives.
3. Tag `vX.Y.Z` and push the tag (e.g. `git tag v0.7.0 && git push origin v0.7.0`).
4. `release.yml` fires on the tag: it builds `launchup-sales-connector.zip` (runtime files only, allowlist asserted in the workflow), runs the `readme.txt` header lint, and attaches the zip to the GitHub release.
5. Each creator shop then sees the update in **wp-admin → Plugins** as a normal one-click update — plugin-update-checker v5 polls the GitHub release assets, so there is no update server to host and no tokens on creator sites.

## Installatie per shop (voor Lasse)

1. Launch Hub → contact → **Deal & contract**: vul `shop_identifier` (exacte site-URL) → **API-key aanmaken** → kopieer key + ingest-URL.
2. Shop wp-admin → Plugins → Nieuwe plugin → upload de release-zip → activeer.
3. WooCommerce → Launch Up Connector → plak ingest-URL + key → **Test verbinding** (moet groen).
4. Klik **Historie pushen** → controleer na een paar minuten het Launch Hub Sales-dashboard.
5. Klaar — dagelijkse push loopt vanzelf om 04:00; updates verschijnen voortaan gewoon onder Plugins.

## Status

Phases 0-6 built. Phase 7 (E2E + UAT against Launch Hub staging) pending.
