# 05 · Build phases & gates

Same rules as the Launch Hub project: strict order, a phase is done only when its gate **ran and passed** (paste output in the PR), one tagged commit/PR per phase, ask Lasse on ambiguity.

> **Dependency callout:** the Launch Hub addendum (launch-hub repo, `specs/14`) phases **A0 + A1 must be live** (at least on a staging Supabase) before plugin **phase 7**; phases 0-6 run fully standalone against fixtures/mocks.

## Phase 0 · Scaffold & CI
Repo init, plugin skeleton (headers per specs/02 §5, HPOS declaration, WC-inactive guard, empty includes), composer (phpunit, wpcs/phpcs, dealerdirect installer), `.wp-env.json` (WP 6.9, latest WooCommerce, PHP 8.1), GitHub Actions `ci.yml` (phpcs + phpunit on PHP 8.1 and 8.3), GPL license, README stub.
**Gate 0**: CI green on a PR · `npx wp-env start` boots, plugin activates without notices/fatals with WooCommerce active · deactivating WooCommerce shows the guard notice instead of a fatal · HPOS shows the plugin as compatible (WooCommerce → Settings → Advanced → Features).

## Phase 1 · Aggregation engine (pure)
Everything in specs/01: DTOs, PeriodAggregator, PayloadBuilder, full fixture + property + snapshot test suite. No WordPress code touched.
**Gate 1**: `composer test` green including all specs/01 §4 cases; the canonical fixture snapshot committed.

## Phase 2 · OrderSource
specs/02 §1 + the wp-env fixture seeding script (WP-CLI: products incl. variable product, multi-month orders, partial refund, amount-only refund, one ignored `pending` order).
**Gate 2**: DTO dump for the seeded shop equals `tests/fixtures/wp-env-expected.json`; memory sanity on a 1k-order synthetic seed.

## Phase 3 · PushClient & status
specs/02 §3-§4 + a local mock ingest endpoint inside wp-env that records requests and can return each response shape.
**Gate 3**: success / LU-SAL-002 / -003 / -004 / transport-failure / warnings paths all asserted; `test()` sends `periods: []`; key masked in every log entry; StatusStore ring buffer unit-tested.

## Phase 4 · Scheduler & backfill
specs/02 §2: daily action (04:00 shop time), push-now, backfill chain, retry ladder, self-healing schedule check.
**Gate 4**: scheduled action visible in WooCommerce → Status → Scheduled Actions with correct next-run time · double-running one window yields byte-identical payloads · retry actions scheduled at +5m/+30m/+2h on forced failures, `consecutive_failures` counts and resets · backfill on the fixture shop generates exactly the expected oldest→window chunks and stops.

## Phase 5 · Settings UI, notices, uninstall, i18n
All of specs/03.
**Gate 5**: `composer lint` clean · specs/03 §5 unit tests green · the manual wp-env checklist walked and recorded in the PR (incl. nl_NL rendering and clean uninstall).

## Phase 6 · Updates & release pipeline
All of specs/04.
**Gate 6**: `release.yml` zip file-list assertion passes · `v0.9.0-test` pre-release visible as an update in wp-env and one-click update succeeds · readme header lint green.

## Phase 7 · End-to-end against Launch Hub & UAT
Point a wp-env shop at the real (staging) ingest: create the API key on a test contact in Launch Hub, Test verbinding, Historie pushen, then a manual daily-push run.
**Gate 7 (final)**: Hub Sales-dashboard shows exactly the fixture shop's expected numbers (revenue ex-BTW, orders, items, per-product rows, net of the refunds) with source chip *plugin* · plugin-data overschrijft een vooraf gedane CSV-import van dezelfde maand · stall-bewaking: met `ingest_stall_hours` tijdelijk laag krijgt de accountmanager **precies één** melding, en een nieuwe push reset de bewaking · UAT-checklist afgetekend door Lasse: installatiegids gevolgd op een verse shop, verdiensten/winst-kaarten kloppen met handmatig nagerekende bedragen (creator-prijs en Launch Up-prijs met ingangsdatum in het verleden), portal toont items + verdiensten en géén Launch Up-winst, update van v0.9.0-test naar v1.0.0 verschijnt en installeert. Tag `v1.0.0`.
