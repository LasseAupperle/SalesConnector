# 06 · Product ids & catalogue sync (contract v1.2)

**Status:** specified 2026-08-31, not built. Launch Hub counterpart: `specs/16-per-product-royalties.md`
in the launch-hub repo. **Launch Hub phase C2 must be deployed before this plugin release ships** —
the app has to understand the new fields before a shop starts sending them.

## 0. Why

Launch Hub is moving royalties from one flat amount per creator to **a price per product**. Two
things block that, and both are on this side of the wire.

1. **A product has no identity here.** We send a free-text `name`. Rename a product in WooCommerce
   and Launch Hub sees a different product, which silently loses its royalty and its cost. Every
   month after that reads "—" and nobody is told.
2. **A product only becomes visible once it sells.** Launch Up's actual sequence is: contracts
   signed → shop built → *then* someone fills in the royalties. Without a catalogue, month one is
   always unpriced, because the products do not exist in Launch Hub until an order arrives.

Neither needs new data collection. The id is already computed and thrown away; the catalogue is
already sitting in WooCommerce.

## 1. `external_ref` on every product line

Each `products[]` entry gains `external_ref`: the string `wc:` + the **parent** product id.

```jsonc
{ "name": "Hoodie zwart", "quantity": 10, "revenue": 349.50, "external_ref": "wc:1042" }
```

- The value already exists as `LineItem::$parentProductId` (`OrderSource::resolveParent`) and is
  discarded when `PeriodAggregate::toPayload()` builds the payload. Carrying it through is the whole
  change: `ProductAggregate` gains the field, `toPayload()` emits it.
- **Parent, not variation** — unchanged from §4 of specs/00. Sizes and colours of one design are one
  product and share one royalty.
- The deleted-product bucket keeps its existing id `0` and is therefore sent as `wc:0`. Launch Hub
  treats that as a real row so its revenue is not lost, but refuses to let anyone price it: it is
  not one product, it is whatever used to be there.

**Not sent: `unit_price`.** It was in the first draft of this change and was dropped. This plugin
aggregates per product per month, so any unit price it could send would itself be
`revenue ÷ quantity` — the number Launch Hub can compute for free. Same weighted average, one more
field to keep correct. Price context is derived on the Launch Hub side and labelled as an average.

## 2. Catalogue sync

A new **optional top-level** array, sent with the existing scheduled push. Not a new job, not a new
schedule — the catalogue changes far more slowly than sales do, and a second timer is a second thing
that can silently stop.

```jsonc
"catalogue": [
  { "external_ref": "wc:1042", "name": "Hoodie zwart", "status": "publish", "price": 34.95 }
]
```

- Source: `wc_get_products()` with `status => 'publish'`, parent-level types only
  (`simple`, `variable`), `limit => -1` read in batches.
- `price` is the product's regular price, **context only**. Launch Hub never uses it in a money
  calculation; what a thing is listed at is not what it sold for.
- **Drafts, private and pending products are excluded.** They are unannounced designs — sending them
  leaks a creator's plans into a system other staff can read, and clutters the pricing table with
  products that may never exist.
- **Cap at 500 products per push**, newest first, with a `notes[]` entry when the cap is hit. A shop
  that large is a conversation, not a silent truncation.
- Absence is not deletion. Launch Hub never removes or deactivates a product because it was missing
  from a push; only an explicit non-`publish` `status` deactivates one. A partial push is far more
  likely than a withdrawn product, and the wrong guess destroys pricing history.

## 3. Contract v1.2

`contract` becomes `"1.2"`. Additive only — no field is removed or renamed, per the standing rule in
CLAUDE.md. A Launch Hub that understands 1.1 ignores the new fields and behaves exactly as today,
which is what makes the deploy ordering safe rather than merely lucky.

Keep `specs/00` §2's copy of the contract in sync by hand, as that section already instructs.

## 4. Privacy

Unchanged in kind: still no customer data, no order ids, no PII. What is added is product names,
ids and list prices — shop-public information, with the deliberate exception of unpublished products
(§2), which are excluded precisely because they are not public yet.

## 5. Out of scope

Stock levels · images · categories and tags · variation-level rows · cost prices (the printer's
cost is not in WooCommerce and is typed into Launch Hub by hand) · any write back into the shop.

## 6. Phases & gates

**P8 · `external_ref` on order lines** — `ProductAggregate` + `toPayload()` + `PayloadBuilder`
contract bump. *Gate*: `composer test` green, with new unit tests asserting a variation order line
is reported under its parent's `wc:<parentId>`, a deleted product under `wc:0`, and that a payload
built from the same fixture is byte-identical to the 1.1 payload apart from the added fields.

**P9 · Catalogue** — §2. *Gate*: integration test against wp-env — a shop with published, draft and
private products sends **only** the published ones; a variable product appears once, as its parent;
the 500 cap emits a note; `composer lint` clean.

**P10 · End to end** — against a Launch Hub with C2 deployed. *Gate*: a push from a fresh shop
creates products in Launch Hub **before any order exists**; an order for one of them lands on the
same product row rather than creating a second; renaming the product in WooCommerce and pushing
again updates the name and keeps the royalty. Bump the plugin to **1.1.0**, tag, release, and update
the Esoo shop.
