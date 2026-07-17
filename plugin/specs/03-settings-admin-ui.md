# 03 · Settings & admin UI

## 1. Page

Submenu **WooCommerce → Launch Up Connector** (capability `manage_woocommerce`). One screen, three cards:

**Verbinding**
- *Ingest URL* — text input, must be `https://` (inline validation); helper: "Kopieer deze uit Launch Hub → contact → Deal & contract."
- *API key* — password input with show-toggle. When constant `LUSC_API_KEY` is defined, the field is disabled and shows "Gedefinieerd in wp-config.php (aanbevolen)". Stored value never re-rendered in full (mask to last 4 chars after save).
- Save (nonce + capability), sanitized (`esc_url_raw`, trimmed key must match `/^lu_sk_[A-Za-z0-9]{20,}$/` else inline error).
- **Test verbinding** button — AJAX (`wp_ajax_lusc_test`) → `PushClient::test()` → inline result: green "Verbonden ✓" or the mapped LU-message in red.

**Acties**
- **Push nu** — enqueues `lusc_push_now`; inline "Gestart — resultaat verschijnt in de log."
- **Historie pushen** — confirm dialog ("Pusht alle maanden vanaf de eerste order; loopt op de achtergrond en is veilig opnieuw uit te voeren — dient ook als volledige her-sync"); enqueues the backfill chain; disabled until a successful test/push exists. Re-running it is the supported **full re-sync** path (refreshes months older than the rolling window, e.g. late refunds on old orders).
- After the **first** successful save+test, show a dismissible notice on this page suggesting the backfill (decision: history on activation, operationalized as one click here because the key can't exist before activation).

**Status**
- Indicator: 🟢 laatste push gelukt < 26 u geleden · 🟠 laatste poging mislukt · ⚪ nog nooit gepusht. Shows `last_success_at` / `last_attempt_at` (site timezone).
- Log table: the 10 ring-buffer entries (tijd, soort, venster, periodes, HTTP, LU-code, melding).

## 2. Global admin notice

When `consecutive_failures >= 3`: dismissible-per-episode error notice on all admin pages for `manage_woocommerce` users — "Launch Up Connector kan niet pushen naar Launch Hub ({laatste melding}). [Instellingen openen]". Clears automatically on next success.

## 3. Hardening & conventions

Nonces + capability checks on save and every AJAX action; all output escaped (`esc_html`, `esc_attr`, `esc_url`); no inline JS beyond a small enqueued `admin.js`; strings via `__()/_e()` with text domain, source EN, `languages/launchup-sales-connector-nl_NL.po/.mo` shipped covering every string; settings link added on the Plugins row. No REST routes, no frontend footprint (zero enqueues outside our admin page).

## 4. uninstall.php

Deletes `lusc_settings` + `lusc_status`, unschedules all Action Scheduler actions in group `lusc`. **Never** contacts Launch Hub or removes remote data (revoking the key is a conscious action in Launch Hub, not a side effect of uninstalling).

## 5. Acceptance criteria (phase 5 gate)

`composer lint` clean (WPCS) · unit tests for key-format validation, URL validation, log ring-buffer, notice trigger logic · manual checklist in wp-env: save/validate flows, wp-config override state, test button success + LU-SAL-002 path (wrong key), push nu lands in log, backfill button disabled→enabled behavior, dismiss/reappear of the failure notice, uninstall leaves no `lusc_*` options or scheduled actions, Dutch translation renders on a nl_NL site.
