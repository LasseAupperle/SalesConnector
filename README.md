# Launch Up Sales Connector

WordPress plugin (`launchup-sales-connector`) installed on every Launch Up creator WooCommerce shop. It aggregates sales **per month** (per parent product, ex VAT, net of refunds) and pushes them to Launch Hub's `sales-ingest` endpoint. It is deliberately a dumb data pump: it knows nothing about profit prices, contracts, or creators, and **no customer data ever leaves the shop**.

## Repo layout

| Path | What |
|---|---|
| `plugin/` | The WordPress plugin (source, tests, specs). Start at `plugin/CLAUDE.md`. |
| `plugin/specs/` | Authoritative feature specs (00-05). Build order: `plugin/specs/05-build-phases-gates.md`. |
| `.wp-env.json` | Local/CI WordPress + WooCommerce environment (WP 7.1, WooCommerce 11.1.0, PHP 8.1). |

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

**v1.0.0 — in production.** All phases 0-7 done. Verified on a live creator shop (2026-07-25): the
plugin pushed real WooCommerce months to Launch Hub, and the revenue, order count, item count and
per-product breakdown matched the shop's own figures.

Two things that first install taught us, both fixed in 1.0.0:

- **A HTTP 200 is not agreement.** A truncated ingest URL answered 200, and the connector showed a
  green "laatste succes" while Launch Hub had never heard of the shop. A 200 without an `imported`
  count is now a failure that names the likely cause.
- **A trailing slash is not a different shop.** `site_url()` omits it, operators habitually type
  it, and the mismatch surfaced as `LU-SAL-003` ("this key belongs to a different shop URL") —
  true, and pointing at the wrong thing. Launch Hub now ignores a trailing slash on either side;
  everything else still has to match exactly.
