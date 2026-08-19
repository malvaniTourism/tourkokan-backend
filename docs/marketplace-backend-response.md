# Marketplace — Backend Response

**From:** Backend (tourkokan-backend)
**To:** App (tourkokan-v2) · marketplace
**Date:** 2026-08-18
**Re:** "Marketplace — Everything Needed from Backend" (2026-08-18)
**Basis:** Every item below was verified against the running application and the database,
not read from source. Response shapes quoted are actual output.

---

## TL;DR

| Item | Verdict |
|---|---|
| **A1** seed api-test | ⚠️ **Ops task — commands below.** Correct diagnosis |
| **B1** `custom_specs` | 🔵 **Your decision.** Recommend ship schema-only for v1 |
| **B2** self-serve upgrade | 🔵 **Your decision.** Recommend keep admin-assigns |
| **C1** edit product | ✅ **Already worked** — verified all 9 prefill fields |
| **C2** per-product analytics | ✅ **Implemented** — added `conversion_rate` + `leads_by_type` |
| **C3** gallery media array | ✅ **Already worked** — `gallery[]` was always there |
| **C4** favourites list | ✅ **Implemented** — endpoint existed but returned unusable rows |
| **C5** buyer enquiry history | ⛔ **Not built.** Small, say the word |
| **D** shape contracts | ✅ **All 7 confirmed + pinned by tests** |
| **E** wired-but-unused | ✅ **All confirmed working** |

**Two code changes shipped (C2, C4). Two were already done (C1, C3). One ops task (A1),
two decisions (B1, B2), one optional (C5).**

299 tests passing.

---

## A1 — Seed api-test ⚠️ ops

Your diagnosis is exactly right. The endpoints return empty because the taxonomy tables are
unseeded, not because of a code fault. Run **in this order** — the third depends on the
second:

```bash
php artisan migrate --force
php artisan db:seed --class=PlanSeeder --force
php artisan db:seed --class=ProductCategorySeeder --force
php artisan db:seed --class=VendorCategorySeeder --force
```

That produces 20 product categories with `attribute_schema`, the site-category tree, 163
`AllowedProductCategory` rows, and the `is_business` flags `businessCategories` filters on.

**Also required on api-test** — the scheduler cron, or every analytics screen reads zero
forever with no error:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Verify afterwards:
```bash
php artisan tinker --execute="printf('product categories: %d | whitelist rows: %d | business cats: %d'.PHP_EOL, App\Models\ProductCategory::count(), App\Models\AllowedProductCategory::count(), App\Models\Category::where('is_business',true)->count());"
```
Expect roughly `20 | 163 | 27`. **Do not** run `demo:vendors` on api-test — that is local
fake data.

---

## B1 — `custom_specs` 🔵 your call

Confirmed: `ProductAttributeValidator` rejects unknown keys exactly as you quoted, and no
`custom_specs` column exists.

**Recommendation: ship schema-only for v1 (your option C).** Reasoning, so you can overrule
it knowingly:

- Schema attributes are the thing that stays filterable and comparable. Free-form specs
  never can be — "Floor: 3" from one vendor and "floor no: third" from another are different
  strings forever.
- The escape hatch already exists: a vendor writes extras into `description`.
- If a property matters enough to be structured, the better fix is **adding it to the
  category schema** — which is a single admin action, no migration, no app release. That is
  what the schema system is for.

If you want it anyway, option **A** (separate `custom_specs` JSON, validator ignores it) is
the right shape — about an hour. Say the word.

---

## B2 — Self-serve subscription upgrade 🔵 your call

Confirmed: only admin `assignPlan` exists; there is no user-facing `subscribe`.

**Recommendation: keep admin-assigns for now.** A self-serve upgrade needs a payment
gateway, order/invoice records, webhook handling, refunds and dunning — that is the commerce
layer, deliberately deferred (design doc §3b). Listing is free for the launch year, so
nothing is being lost meanwhile, and the honest "Contact us to switch" the app already shows
is the right interim.

Worth revisiting once there is lead data to price against — that is what the metering
pipeline is collecting now.

---

## C1 — Edit a product ✅ already worked

Verified live: `getProduct` returns every prefill field you listed.

```
name ✓   description ✓   base_price ✓   sale_price ✓   unit ✓
attributes ✓ (as stored)   product_category_id ✓   gallery ✓   variants ✓
```

`sale_price` is present as `null` when unset — a key, not an omission.

`updateProduct` accepts the same multipart shape as `addProduct` (shared validation rules)
and **does** re-enter review: editing an `approved` product sets it back to `pending`. Warn
the vendor before saving — *"Saving will send this listing for review again."*

Pinned by `MarketplaceAsksTest::test_get_product_returns_all_editable_fields`.

---

## C2 — Per-product analytics ✅ implemented

`productAnalytics` had the daily series but not the two aggregates your screen reads. Both
added rather than making you sum the series client-side:

```json
{
  "product": { "id": 6, "name": "Sea View Room", "status": "approved",
               "views_count": 3, "leads_count": 3 },
  "from": "2026-07-20", "to": "2026-08-18",
  "totals": { "views": 3, "unique_views": 3, "leads": 3 },
  "conversion_rate": 100,
  "leads_by_type": { "call": 2, "whatsapp": 1, "directions": 0, "enquiry": 0 },
  "daily": [
    { "date": "2026-08-08", "views": 3, "unique_views": 3, "leads": 3,
      "leads_call": 2, "leads_whatsapp": 1, "leads_directions": 0, "leads_enquiry": 0 }
  ]
}
```

Mapping to your expected shape:

| You asked for | Returned as |
|---|---|
| `name`, `views_count`, `leads_count` | nested under **`product`** |
| `conversion_rate` | ✅ top level (new) |
| `leads_by_type` | ✅ top level (new) |
| `series` | **`daily`** — you said "series (or daily)" |

**Days with no activity are absent, not zero-filled** — zero-fill client-side if the chart
needs a continuous axis. Today's activity **is** included (topped up from raw before the
nightly rollup), so it agrees with `myLeads` and never reads zero on launch day.

---

## C3 — Product image gallery ✅ already worked

`productDetail` and `getProduct` already return the full ordered array as **`gallery`** —
this was in place, not a gap. Real output:

```json
"gallery": [
  {
    "id": 4,
    "title": "Sea View Room",
    "path": "local/products/6a76e08f19d25.png",
    "path_url": "https://tourkokan-…s3.eu-north-1.amazonaws.com/local/products/6a76e08f19d25.png",
    "is_cover": 1,
    "sort_order": 1,
    "galleryable_type": "App\\Models\\Product",
    "galleryable_id": 6
  }
]
```

Field name is **`gallery`**, not `media`. Ordered by `sort_order` ascending.

**Note both path fields.** `path` is relative (prefix `AWS_URL` as you do today);
**`path_url` is already absolute** — using it means no client-side prefixing for product
images. Your call which to use; `path` keeps one convention across the payload, `path_url`
saves a step. `is_cover` is `1`/`0`, not `true`/`false`.

Pinned by `MarketplaceAsksTest::test_product_detail_returns_the_full_ordered_gallery`.

---

## C4 — Buyer's favourites list ✅ implemented

You were right that it was a dead end, though not for the reason listed. `POST
/api/v2/favourites` **did** exist — but it returned bare morph pointers:

```json
{ "id": 12, "user_id": 9, "favouritable_type": "App\\Models\\Product", "favouritable_id": 6 }
```

No name, no price, no image, and no way to filter to products — unusable for a card.

Now it takes an optional `favouritable_type` and eager-loads the favourited record. Products
carry the same pieces a product card needs elsewhere:

```
POST /api/v2/favourites   { "favouritable_type": "Product", "page": 1 }
```
```json
{
  "data": { "current_page": 1, "total": 1, "data": [
    { "id": 12, "favouritable_type": "App\\Models\\Product", "favouritable_id": 6,
      "favouritable": {
        "id": 6, "name": "Sea View Room", "slug": "sea-view-room",
        "base_price": "2400.00", "currency": "INR", "unit": "per_night",
        "product_category": { "id": 1, "name": "Room Night", "code": "room_night" },
        "site": { "id": 41, "name": "Sagar Resort", "logo": null,
                  "latitude": "16.05", "longitude": "73.46",
                  "phone": "9876543210", "whatsapp": "9876543210" },
        "default_variant": { "id": 30, "price": "2400.00", "sale_price": null },
        "cover": { "id": 4, "path": "…", "path_url": "…" }
      } }
  ] }
}
```

Same envelope and same price resolution as `listProducts` — reuse your existing card
component.

**One behaviour worth handling:** if a favourited product is later paused, rejected or
deleted, the row returns `"favouritable": null` with **`"unavailable": true`** rather than a
broken card. Render it as "no longer available" with an unfavourite action.

Omit `favouritable_type` to get everything the user has favourited across all types.

---

## C5 — Buyer's enquiry history ⛔ not built

Correct — `myLeads` is vendor-side (scoped to products the caller *owns*). There is no
buyer-side view.

The data exists: `product_leads` has `user_id`, `product_id`, `lead_type`, `message`,
`created_at`. A `myEnquiries` endpoint is roughly 30 lines plus a test — the mirror of
`myLeads` scoped by `user_id` instead of ownership.

Not built, because it was marked optional and I would rather not add an endpoint no screen
calls yet. **Say the word and it ships.**

---

## D — Response-shape contracts ✅ all confirmed

All seven verified against live output, and now **pinned by
`tests/Feature/MarketplaceAsksTest.php`** so a rename fails the build instead of your app.

| Contract | Status |
|---|---|
| `myUsageStats` nested `{leads:{total}, views:{total}, listings:{total}, conversion_rate}` | ✅ confirmed + test |
| Pagination envelope `{success, message, data:{current_page, last_page, data:[…]}}` | ✅ confirmed |
| Price from `default_variant` → `base_price`/`sale_price`, `currency`, `unit` | ✅ confirmed |
| Images relative, prefix `AWS_URL` | ✅ **with a caveat — see below** |
| `mySites` rows: `is_primary`, `phone`, `whatsapp`, `logo`, `parent_id` | ✅ confirmed |
| `productDetail`/`vendorProfile` public `phone`, `whatsapp`, `latitude`, `longitude` | ✅ confirmed + test |
| `myLeads` rows: `lead_type`, `message`, `product.name`, `created_at` | ✅ confirmed |

**⚠️ The image caveat — please read.** The convention is *not* uniformly relative:

| Field | Form |
|---|---|
| `product.cover.path`, `gallery[].path` | **relative** — needs `AWS_URL` |
| `gallery[].path_url` | **absolute** |
| `site.logo`, `site.image`, `product_category.icon` | **absolute already** |

Site and category images run through a trait that URL-ifies them; gallery `path` does not.
**Prefixing `AWS_URL` onto `site.logo` will produce a broken double URL.** Either branch per
field, or use `path_url` for gallery and treat everything as absolute — I'd suggest the
latter. I did not normalise this because changing `site.logo` to relative would break the
admin panel and web frontend, which already consume it absolute.

---

## E — Wired but unused ✅ all confirmed working

| Group | Status |
|---|---|
| `addUpdateRating`, `comment`, `comments` with `type="Product"` | ✅ work — see note |
| `saveProductVariant`, `deleteProductVariant` | ✅ work; last variant cannot be deleted (422) |
| `deleteProductMedia`, `setProductCover`, `reorderProductMedia` | ✅ work; reorder needs the **complete** id list |
| `setPrimarySite`, `productsBySite`, `featuredProducts` | ✅ work |

**Two things about reviews before you build that UI:**

1. **Comments are moderated.** A new comment is stored but invisible until an admin approves
   it. Tell the user, or it looks like the post failed.
2. **The response is not `{rate, avg, count}`.** `addUpdateRating` returns the saved rating
   record. Aggregates come from `productDetail` as `rating_avg_rate` and `rating_count`.
   Comment lists include the commenter and timestamp. If you want a combined
   `{rate, avg, count}` on write, ask — small change.

---

## F — Confirmed ready, no action

Browse/detail/vendors · `addProduct` schema-driven validation with errors keyed
`attributes.<field>` · `addSite` + `businessCategories` + `is_business` filtering ·
`requestRole`/`myRoleRequests` · enquiry-only booking for v1 · attribute filtering out of
scope for v1. All match what the app expects.

---

## What I need back

1. **B1** — schema-only, or add `custom_specs`?
2. **B2** — keep admin-assigns, or scope in-app upgrades?
3. **C5** — want `myEnquiries` now?
4. **A1** — confirm the seeders + cron have run on api-test.

Everything else is done. Contract: `docs/app-api-integration.md` ·
walkthrough: `php artisan vendor:walkthrough`.

---

# Addendum — confirmations for `marketplace-backend-needs.md` (2026-08-19)

Shapes below captured live from the running app. **Everything is present; the only deltas
are field names**, which you asked to confirm. Nothing needs a backend change.

### C1 · getProduct edit-prefill — ✅ all fields present

`getProduct` returns every prefill field:
```
name · description · base_price · sale_price (null when unset, not absent) · unit ·
attributes (as stored) · product_category {id,name,code,…} · product_category_id (scalar) ·
variants[] · gallery[]
```
`updateProduct` takes the same multipart shape as `addProduct` and **re-enters review** on an
approved product (status → pending).
**Delta:** the image array is **`gallery`**, not `media` (see C3).

### C2 · productAnalytics — ✅ present, note the nesting

```json
{
  "product": { "id": 6, "name": "…", "status": "approved", "views_count": 3, "leads_count": 3 },
  "totals": { "views": 3, "unique_views": 3, "leads": 3 },
  "conversion_rate": 100,
  "leads_by_type": { "call": 2, "whatsapp": 1, "directions": 0, "enquiry": 0 },
  "daily": [ { "date": "…", "views": 3, "unique_views": 3, "leads": 3,
              "leads_call": 2, "leads_whatsapp": 1, "leads_directions": 0, "leads_enquiry": 0 } ]
}
```
Mapping to what you expected: `name` / `views_count` / `leads_count` are **under `product`**;
the series key is **`daily`** (you allowed "series or daily"); `conversion_rate` and
`leads_by_type` are top-level as requested. Days with no activity are **absent, not
zero-filled** — zero-fill client-side for a continuous axis. Today is included (topped up
before the nightly rollup).

### C3 · gallery — ✅ full ordered array, but named `gallery`

`productDetail` and `getProduct` already return the whole ordered array, not just the cover.
**Field name is `gallery`, not `media`.** Item:
```json
{ "id": 4, "path": "local/products/x.png", "path_url": "https://…s3…/local/products/x.png",
  "is_cover": 1, "sort_order": 1, "title": "…", "galleryable_type": "…", "galleryable_id": 6 }
```
Two naming notes for your mapper:
- image field is **`path`** (relative — prefix `AWS_URL`) and **`path_url`** (already
  absolute — use this to skip prefixing). There is no `url` key.
- `is_cover` is **`1`/`0`** (int), not `true`/`false`.

Ordered by `sort_order` ascending.

### C4 · favourites list — ✅ shipped

`POST /api/v2/favourites { favouritable_type: "Product" }` returns the caller's favourites,
each with the full product card under `favouritable`; a listing that has gone offline returns
`favouritable: null` + `unavailable: true`. (Details in §C4 above.)

### A1 · seed api-test — commands unchanged

```bash
php artisan migrate --force
php artisan db:seed --class=PlanSeeder --force
php artisan db:seed --class=ProductCategorySeeder --force
php artisan db:seed --class=VendorCategorySeeder --force
# + cron:  * * * * * php artisan schedule:run
```
Verify: `ProductCategory` ≈ 20, `AllowedProductCategory` ≈ 163, `Category::where('is_business',true)` ≈ 27.

### B1 / B2 — still your call

B1 `custom_specs`: recommend schema-only for v1 (zero work). B2 self-serve upgrade: recommend
keep admin-assigns until the commerce layer exists. Neither blocks the current slice.

### D · contract confirmations

`addSite` **persists `parent_id`** (city link) — verified live: sent `parent_id`, stored and
returned. `mySites` rows carry `is_primary`, `phone`, `whatsapp`, `logo`, `parent_id`. All
seven D contracts hold and are pinned by `tests/Feature/MarketplaceAsksTest`.
