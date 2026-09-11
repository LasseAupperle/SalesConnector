# 04 · Updates & distribution

## 1. Repository

Public GitHub repo `launchup-sales-connector` (owner: Lasse's account or a Launch Up org). License **GPL-2.0-or-later** (required for WordPress-plugin compatibility; the code contains zero secrets — API keys live per-site in settings/wp-config, so public is safe and avoids distributing GitHub tokens to creator sites). Repo contains the plugin source, tests, wp-env config, CI; releases carry an installable zip asset.

## 2. Auto-updates (plugin-update-checker v5)

Bundle the library in `lib/plugin-update-checker/` (pin the release used in composer docs). Bootstrap:

```php
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$lusc_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/<owner>/launchup-sales-connector/',
    __FILE__,
    'launchup-sales-connector'
);
$lusc_update_checker->getVcsApi()->enableReleaseAssets();
```

With release assets enabled, WordPress on every creator shop sees a new tagged GitHub release as a normal plugin update (one click in wp-admin). No tokens, no update server to host.

## 3. Release flow (document in the repo README)

1. Bump `Version:` in the plugin header **and** `Stable tag:` in `readme.txt`; update `WC tested up to`; add a changelog entry.
2. Tag `vX.Y.Z` (semver: additive payload fields or features = minor; fixes = patch; counting-definition changes would be major and require a Launch Hub decision first — see CLAUDE ground rules).
3. GitHub Action `release.yml` (on tag): `composer install --no-dev`, build `launchup-sales-connector.zip` containing only runtime files (plugin php, includes/, lib/, languages/, readme.txt, uninstall.php — excludes tests, wp-env, CI, composer dev artifacts), attach as the release asset.

`readme.txt` headers: Requires at least **7.0** · Tested up to (current WP) · Requires PHP **8.1** · WC requires at least **11.0** · WC tested up to (fill per release) · License GPL-2.0-or-later.

## 4. Per-shop install guide (goes in README, written for Lasse)

1. Launch Hub → contact → **Deal & contract**: vul `shop_identifier` (exacte site-URL) → **API-key aanmaken** → kopieer key + ingest-URL.
2. Shop wp-admin → Plugins → Nieuwe plugin → upload de release-zip → activeer.
3. WooCommerce → Launch Up Connector → plak ingest-URL + key → **Test verbinding** (moet groen).
4. Klik **Historie pushen** → controleer na een paar minuten het Launch Hub Sales-dashboard.
5. Klaar — dagelijkse push loopt vanzelf om 04:00; updates verschijnen voortaan gewoon onder Plugins.

## 5. Acceptance criteria (phase 6 gate)

CI release workflow produces a zip whose file list matches the allowlist exactly (asserted in the workflow) · in wp-env, with the checker pointed at the public repo and a pre-release tag `v0.9.0-test` published, the Plugins screen shows the available update and one-click updating succeeds · `readme.txt` header lint (`scripts/check-readme.php`) passes — it asserts the required headers exist AND that the baseline headers AGREE with the plugin header, rather than matching literals typed into the linter (which made a baseline move fail the lint and point at the linter; DECISIONS 2026-09-11) · uninstall/reinstall via the built zip leaves a working configuration flow.
