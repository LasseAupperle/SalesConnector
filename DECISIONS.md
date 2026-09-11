# Decisions ledger

Append-only record of decisions taken when a spec was silent, ambiguous, self-contradictory, or
contradicted by reality. **Never edit or delete an entry** — supersede it with a new one that
references the old by date.

Each entry:

```
## YYYY-MM-DD · <short title>
- **Type:** contradiction | ambiguity | gap
- **Trigger:** what forced a decision (the spec text, the ambiguity, or the reality that broke it)
- **Decision:** what we chose to do
- **Rationale:** why
- **Touches:** files/specs affected
```

Trigger types:

- **contradiction** — the spec, a doc, or a prior decision disagrees with reality or with itself.
- **ambiguity** — the spec permits more than one reasonable reading and one had to be picked.
- **gap** — the spec is silent on something a build had to decide.

---

## 2026-09-11 · The declared baseline had fallen behind what a shop actually installs

- **Type:** contradiction
- **Trigger:** `CLAUDE.md` pins the baseline at WordPress **6.9+**, WooCommerce **10.x**, and
  specs/02 §5, specs/04 §3 and specs/05 phase 0 repeat it in the plugin headers, `readme.txt` and
  `.wp-env.json`. Reality moved past it: WooCommerce **11.1.0** is stable, requires WordPress
  **7.0**, and is tested to **7.1** — which is the current WordPress release. A creator shop set up
  today therefore runs a combination the plugin neither declares nor tests, and the CI wp-env job
  only stayed green because WooCommerce was pinned back to 10.9.4 on 2026-09-11 to stop it failing
  to activate at all.
- **Decision:** move the baseline to WordPress **7.0+** (tested 7.1), WooCommerce **11.0+** (tested
  11.1), PHP **8.1+** unchanged. Pin `.wp-env.json` and the gate-6 workflow to WordPress 7.1 and
  WooCommerce 11.1.0 — both sides pinned, as the 2026-09-11 breakage taught — and run every CLI
  gate against that pair before releasing.
- **Rationale:** the floor is set by WooCommerce, not by us: the plugin uses no 7.x API, so 7.0 is
  simply the lowest WordPress that can run the WooCommerce we now require. Declaring less than we
  test is a promise we cannot keep; declaring more than a shop can install is a shop that cannot
  activate the plugin. Lasse asked for the newest versions to be the ones that are working and
  tested, which makes the pinned pair the release gate rather than a convenience.
- **Touches:** `plugin/CLAUDE.md`, `plugin/launchup-sales-connector.php`, `plugin/readme.txt`,
  `.wp-env.json`, `.github/workflows/ci.yml`, `.github/workflows/gate6.yml`, `README.md`,
  `plugin/specs/02-push-scheduler-backfill.md`, `plugin/specs/04-updates-distribution.md`,
  `plugin/specs/05-build-phases-gates.md`
