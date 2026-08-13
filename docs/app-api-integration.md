# Tourkokan App API — Integration Guide

For `tourkokan-v2` (React Native) and the `tourkokan` web frontend.

The app has **two surfaces**, both in the same build:

- **Browse** (§1–6) — what every user sees: products, vendors, engagement. Tourist-facing.
- **Sell** (§9–13) — what a user who has become a *vendor* sees: their businesses, their
  catalog, their analytics.

A person is both. They browse as a tourist and, once they take the vendor role, also get the
vendor screens. Same login, same token — the vendor endpoints are simply gated on the role.

**Base URL** — `{API_PATH}/api/v2`
**Auth** — JWT. `Authorization: Bearer <token>` on every request below; the app requires
login before anything.
**Method** — every endpoint is `POST`, including reads.

> This file is the practical integration guide. For exhaustive field lists and every edge
> case, `docs/vendor-products-api.md` is the full contract.

---

## 1. Four things to get right first

**A failed request usually returns HTTP 200.** Read `success`, never the status code.

```js
const { success, message, data } = res.data
if (!success) throw new Error(typeof message === 'string' ? message : 'Request failed')
```

**`message` is a string or a field-errors object.** Business errors give a string;
validation failures give `{ field: ["error"] }`.

**Paginated rows are at `data.data`**, not `data`. `per_page` is capped at 30.

**Everything returned is already filtered for visibility.** A product only appears if it is
approved, its business is approved and published, and it is inside its availability window.
The app never has to check status itself.

---

## 2. Response envelope

```json
{
  "version": "1.4.2",
  "language": "en",
  "success": true,
  "message": "Products fetched.",
  "data": { "current_page": 1, "data": [ ... ], "last_page": 3, "total": 42 }
}
```

Content carries both `name` / `mr_name` and `description` / `mr_description`. Pick by the
user's language, falling back to the English field when the Marathi one is empty.

---

## 3. Browsing products

### `listProducts`

Every filter is optional; send only what the screen uses.

```json
{
  "category_code": "room_night",
  "site_id": 41,
  "min_price": 500,
  "max_price": 5000,
  "search": "sea view",
  "is_featured": false,
  "latitude": 16.0512,
  "longitude": 73.4680,
  "radius_km": 25,
  "sort": "nearest",
  "page": 1,
  "per_page": 15
}
```

- `sort` — `latest` (default) · `price_asc` · `price_desc` · `popular` · `nearest`
- `latitude` and `longitude` must be sent **together**. When present, every row gains
  `distance_km`; `sort: nearest` needs them, and silently falls back to `latest` without them.
- `max_price` on its own is fine.

Row:

```json
{
  "id": 12,
  "name": "Deluxe Sea View Room",
  "slug": "deluxe-sea-view-room",
  "base_price": "2400.00",
  "currency": "INR",
  "unit": "per_night",
  "is_featured": false,
  "views_count": 84,
  "leads_count": 6,
  "rating_avg_rate": 4.5,
  "rating_count": 12,
  "distance_km": 2.83,
  "product_category": { "id": 7, "name": "Room Night", "code": "room_night" },
  "site":             { "id": 41, "name": "Sagar Resort", "logo": "https://…",
                        "latitude": 16.05, "longitude": 73.46 },
  "default_variant":  { "id": 30, "price": "2400.00", "sale_price": null, "stock": null },
  "cover":            { "id": 88, "path": "https://…" }
}
```

**Display the price from `default_variant`, not from `base_price`:**

```js
const price = row.default_variant?.sale_price ?? row.default_variant?.price ?? row.base_price
```

`base_price` is a "starting from" figure and can be out of date. The variant is what the
customer actually pays. `unit` (`per_night`, `per_plate`, `per_kg`, …) is the suffix —
"₹2,400 per night".

### The rest

| Endpoint | Payload | Use |
|---|---|---|
| `productDetail` | `id` **or** `slug` | detail screen — adds `variants`, `gallery`, `price`, `is_favourite`, `rating_avg_rate` |
| `productsBySite` | `site_id` | "more from this business" |
| `featuredProducts` | `page?` | home rail |

---

## 3b. Vendor profiles

One owner may run several businesses, so vendors are a first-class thing a tourist can
browse — not just a name attached to a listing.

| Endpoint | Payload |
|---|---|
| `listVendors` | `search?`, `category_id?`, `category_code?`, `latitude?`+`longitude?`, `radius_km?`, `page?` |
| `vendorProfile` | `id` (the vendor id from a list row), `latitude?`+`longitude?` |

`listVendors` gives **one card per owner**, not per business:

```json
{
  "id": 9,
  "business_name": "Sagar Resort Tarkarli",
  "tag_line": "Sea view stays",
  "logo": "https://…",
  "outlet_count": 2,
  "product_count": 7,
  "categories": [ { "id": 11, "name": "Hotel Rooms", "code": "hotel_rooms" } ],
  "distance_km": 0.4
}
```

`distance_km` is to the **nearest** of that vendor's outlets, not the primary one — so a
chain with a branch next to the user sorts as close, which is what a tourist means.

`vendorProfile` returns the vendor, all their live outlets (each with categories, location
and rating), and their catalog **across all outlets** in one paginated list.

Two things to rely on:

- **The identity is the business, never the person.** `business_name`, `logo` and
  `description` come from the vendor's primary business. The owner's name, email and mobile
  are encrypted personal data and are never returned — do not build UI expecting them.
- **Only live data appears.** A pending or rejected business is absent, as are its products,
  and a vendor with no live business has no profile at all (404). Nothing needs filtering
  client-side.

---

## 4. Engagement — please wire these up

| Endpoint | Payload | Fire when |
|---|---|---|
| `recordProductView` | `id`, `platform?` | the detail screen opens |
| `recordProductLead` | `id`, `lead_type`, `message?`, `platform?` | the user taps call / WhatsApp / directions, or sends an enquiry |

`lead_type` — `call` · `whatsapp` · `directions` · `enquiry`

**Fire `recordProductLead` on the tap, before opening the dialler or WhatsApp** — not after,
and not on return. Leads are the number the business runs on and the one vendors are shown
as proof the platform works.

Both are fire-and-forget: ignore the response, never block the UI, never retry on failure.

---

## 5. Favourite, rate, comment

Products reuse the platform's existing generic endpoints. Pass the literal string
`"Product"` as the type.

```
addDeleteFavourite   { favouritable_id, favouritable_type: "Product" }   toggles
addUpdateRating      { rateable_id, rateable_type: "Product", rate: 1..5 }
comment              { commentable_id, commentable_type: "Product", comment: "…" }
comments             { commentable_id, commentable_type: "Product" }
```

**Comments are moderated** — a new one is saved but invisible until an admin approves it.
Say so after posting, or it looks like the post failed.

`productDetail` returns `is_favourite` for the calling user, so the heart icon needs no
extra call.

---

## 6. Where products come from

Useful context when something does not appear:

```
vendor adds a listing  →  admin approves it  →  it appears here
```

A listing is invisible until **both** its business and the listing itself are approved, and
an admin unpublishing the business hides all of its listings immediately. If a vendor says
"my product isn't showing", it is almost always one of those two, not a caching problem.

---

# Vendor side — selling from the app

Everything below requires the **`vendor` role**. Without it these endpoints return **403**
with a message telling the user to request the role — show that as a prompt to become a
vendor, not an error. Same token as the browse side; the role is the only gate.

## 9. Becoming a vendor

```
requestRole            user asks for the vendor role
   ⏸ admin approves (nothing the app does)
addSite                register the first business
   ⏸ admin approves
   → vendor can list products (and can start adding them while the site is still pending)
```

| Endpoint | Payload | Notes |
|---|---|---|
| `requestRole` | `role_code: "vendor"`, `reason?` | 422 if a request is already pending |
| `myRoleRequests` | — | poll `status`: `pending` / `approved` / `rejected` |
| `addSite` | see below | **multipart** — vendor-gated |
| `mySites` | — | the outlet picker, §11 |
| `setPrimarySite` | `id` | mark the head location |

`addSite` (multipart/form-data):
```
name, description (min 20 chars), categories[] (site-category ids), latitude, longitude,
tag_line?, domain_name?, pin_code?, image? logo? (jpeg/jpg/png/webp),
social_media? speciality? rules?  (JSON strings)
```

Site-category ids come from `listcategories`. The first site an admin approves is
auto-marked the vendor's **primary** business.

## 10. The add-product flow

The Add-Product form is **rendered from the server** — the app never hardcodes fields per
category, so a new product type ships with no app release. The sequence:

```
① mySites                   → vendor picks which business
② allowedProductCategories  → { site_id }              what this outlet may sell
③ categoryAttributeSchema   → { product_category_id }  the fields to render
④ addProduct                → saved as a DRAFT
⑤ uploadProductMedia        → one call per image
⑥ saveProductVariant        → optional extra price points
⑦ submitProductForReview    → DRAFT → PENDING → (admin) → live
```

**A vendor can add products while their business is still `pending`.** Gate the Add-Product
button on the site not being `rejected`, **not** on it being approved — `mySites` returns
`submission_status` so you can badge pending outlets "under review" and let them carry on.
Nothing goes public until both the site and the product are approved.

### ② `allowedProductCategories` `{ site_id }`

Returns the product categories this outlet is allowed to list — a hospital cannot list
mangoes. Each entry has `id`, `name`, `mr_name`, `code`, `booking_type`, `icon`,
`has_attributes`. Use these to populate the category picker.

### ③ `categoryAttributeSchema` `{ product_category_id }`

The form definition. `attribute_schema` may be `{}` (no extra fields).

```json
{
  "id": 1, "name": "Room Night", "mr_name": "रूम प्रति रात्र",
  "code": "room_night", "booking_type": "date_range",
  "attribute_schema": {
    "ac":        { "type": "bool", "label": "Air conditioned", "mr_label": "वातानुकूलित" },
    "bed_type":  { "type": "enum", "label": "Bed type", "options": ["Single","Double","Twin","Queen","King"] },
    "check_in":  { "type": "time", "label": "Check-in time" },
    "occupancy": { "type": "int",  "label": "Max guests", "required": true, "min": 1, "max": 20 }
  }
}
```

Render one input per key, in order. Type → widget:

| `type` | Widget | Send as |
|---|---|---|
| `string` / `text` | text / textarea (`max`) | string |
| `int` / `decimal` | number (honour `min`/`max`) | number or numeric string |
| `bool` | switch | `true`/`false` — `"true"`/`"1"`/`"yes"` also accepted |
| `enum` | single-select from `options` | the option string exactly |
| `multi` | multi-select from `options` | JSON array string, or comma-separated |
| `date` | date picker | `YYYY-MM-DD` |
| `time` | time picker | `HH:MM` |

Use `mr_label` when the user's language is Marathi, falling back to `label`.
`booking_type` is informational for now (everything is enquiry-only) — do **not** build
booking UI on it yet.

### ④ `addProduct`

Multipart, because the app sends everything as strings — the server coerces `"3"`→3 and
`"true"`→true.

```
site_id, product_category_id, name,
description?, mr_name?, mr_description?,
base_price?, sale_price?  (≤ base_price),
unit?           per_night | per_person | per_plate | per_kg | per_hour | per_piece | per_package
hsn_code?, tax_rate?  (0/5/12/18/28), price_includes_tax?
attributes      JSON string, validated against the category schema
available_from?, available_to?  (YYYY-MM-DD)
```

Returns a **draft** with its auto-created default variant. Attribute errors come back keyed
`attributes.<field>` using the schema's `label` — map them straight onto the rendered inputs.
**Unknown attribute keys are rejected** — send only what the schema declares.

**Do not render controls for** `status`, `is_featured`, `is_bookable`, `fulfilment_type` —
posting them is silently ignored. A vendor cannot approve or feature their own listing.

## 11. Managing the catalog

| Endpoint | Payload |
|---|---|
| `myProducts` | `site_id?`, `status?`, `search?`, `page?` — a vendor's own listings, **any status** |
| `getProduct` | `id` — full detail incl. `attribute_schema`, for the edit screen |
| `updateProduct` | `id` + any add field |
| `deleteProduct` | `id` (soft delete) |
| `submitProductForReview` | `id` — draft/rejected → pending |
| `toggleProductStatus` | `id` — approved ⇄ paused |

Status lifecycle:
```
draft ──submit──▶ pending ──admin──▶ approved ⇄ paused
  ▲                  │                (toggleProductStatus)
  └──── rejected ◀───┘   (carries rejection_reason — show it)
```

**Editing an approved product returns it to `pending`.** Warn the vendor: *"Saving will send
this listing for review again."* A `myProducts` row is the full product object (`id`,
`name`, `status`, `attributes`, `base_price`, `default_variant`, `cover`, …) — the same
shape as the browse rows plus the non-public fields.

### Media

| Endpoint | Payload | Notes |
|---|---|---|
| `uploadProductMedia` | `id`, `image` (jpeg/jpg/png/webp ≤4 MB), `title?` | one per call; first upload becomes the cover |
| `deleteProductMedia` | `id`, `media_id` | deleting the cover promotes the next image |
| `setProductCover` | `id`, `media_id` | |
| `reorderProductMedia` | `id`, `media_ids[]` | **send every image id in the new order** — a partial list is rejected |

### Variants (price points)

Every product has at least one; if the vendor entered a single price the server created a
`Standard` variant for it. The displayed price comes from the **default variant**, not
`base_price`.

| Endpoint | Payload |
|---|---|
| `saveProductVariant` | `id`, `variant_id?` (omit to create), `name`, `price`, `sale_price?`, `sku?`, `stock?`, `min_order_qty?`, `max_order_qty?`, `is_default?` |
| `deleteProductVariant` | `id`, `variant_id` — **the last variant cannot be deleted** (422) |

## 12. Vendor dashboard

| Endpoint | Payload | Returns |
|---|---|---|
| `myUsageStats` | `from?`, `to?` (default last 30 days) | account totals — views, leads by type, listing counts, conversion rate |
| `productAnalytics` | `id`, `from?`, `to?` | one listing + a daily series for charting |
| `myLeads` | `product_id?`, `lead_type?`, `page?` | the actual enquiries, newest first |

`myUsageStats` includes **today** — the counts are topped up with activity the nightly
rollup has not reached yet, so they agree with `myLeads` and never read zero on launch day.
Frame **leads** as the headline number and views as supporting context.

## 13. Subscription and limits

| Endpoint | Payload | Returns |
|---|---|---|
| `mySubscription` | — | current plan, end date, and live usage against every quota |
| `listPlans` | — | active plans, for an upgrade screen |

```json
{
  "plan": { "code": "free", "name": "Free", "price": "0.00", "billing_period": "free" },
  "subscription": { "starts_at": "…", "ends_at": "…", "days_remaining": 360, "status": "active" },
  "usage": {
    "max_sites":    { "limit": 5,   "used": 1, "remaining": 4,  "exceeded": false },
    "max_products": { "limit": 100, "used": 1, "remaining": 99, "exceeded": false }
  }
}
```

`limit: null` means **unlimited** — render "Unlimited", not `0`. Listing is free for the
launch year with generous limits, so a quota refusal is a rare path. When it does happen it
arrives on the **create** call (`addProduct`, `addSite`, `uploadProductMedia`) as
`success: false` with a human `message` naming the plan and the number — show it directly.

---

## 7. Walking the flow by hand

Before wiring screens, it helps to see the whole journey run. There is an interactive
command that drives **only the app-side calls** over real HTTP and stops wherever an admin
has to act, so you approve each step yourself in the admin panel:

```bash
php artisan serve                 # in one terminal
php artisan vendor:walkthrough    # in another
```

If another project is already on port 8000, `artisan serve` **silently binds the next free
port** — so check the port it prints and pass it through:

```bash
php artisan vendor:walkthrough --url=http://127.0.0.1:8003
```

The script verifies the URL is actually this API before doing anything, so pointing it at
the wrong app fails immediately with a clear message rather than part-way through.

```
[1]  seed a verified dummy user + login
[2]  requestRole (vendor)
     ⏸  approve in Admin → Role Requests, then confirm
[4]  addSite
     ⏸  approve in Admin → Pending Sites, then confirm
[6]  allowedProductCategories → categoryAttributeSchema
[7]  addProduct   (attributes generated from the fetched schema)
[8]  uploadProductMedia
[9]  submitProductForReview
     ⏸  approve in Admin → Pending Products, then confirm
[11] listProducts / productDetail / recordProductView / recordProductLead
```

At each pause it **re-checks the database** rather than trusting the confirmation — if the
approval has not actually landed it says so and waits again, so a mis-click cannot make the
run look successful.

Options: `--url=` (default `http://127.0.0.1:8000`) · `--email=` to reuse an account ·
`--keep` to leave the created data behind. It offers to delete what it made when finished.

The account is the one thing not created through the API: a **verified dummy user is seeded
directly** each run, because registration needs an OTP round trip the script cannot
complete. It prints the credentials, mirrors what registration assigns (`tourist` role), and
hard-deletes the account at the end. Everything after login is the real API.

A fresh account per run also keeps re-runs clean — an account that already holds the vendor
role cannot request it again. Pass `--email=` to reuse one instead, and the script skips
straight past the role steps if that account is already a vendor.

---

## 7b. Test data

To develop against something realistic rather than an empty catalogue:

```bash
php artisan demo:vendors                      # 6 vendors, ~10 businesses, ~30 products
php artisan products:rollup-stats --days=21   # so the analytics screens have a curve
```

```
Vendors   6      Sites  10      Products  32
Views     2249   Leads  189
```

Every listing is generated from the **real taxonomy** — attributes come from each category's
own `attribute_schema` — so the rows exercise the same validation the app does. Businesses
hang off real Kokan villages with scattered coordinates, so geo search returns a spread;
products span all five statuses; some have two variants and some carry a sale price.

Demo vendors log in with their seeded email and `secret123`.

Remove all of it again — nothing else is touched:

```bash
php artisan demo:vendors --purge
```

Two caveats: gallery rows point at a placeholder path, so **images will 404** (enough to wire
list/detail, not to check how a photo renders); and `--count` above ~50 makes the engagement
history slow to generate, since it writes a row per view.

---

## 8. Checklist

**Browse (every user)**
- [ ] Read `success`, never the status code
- [ ] Rows are at `data.data`; `per_page` caps at 30
- [ ] Handle `message` as string **and** as field-errors object
- [ ] Price comes from `default_variant`, with `unit` as the suffix
- [ ] Send `latitude` and `longitude` together, or neither
- [ ] Fire `recordProductView` on detail open, `recordProductLead` on every contact tap
- [ ] Use `mr_name` / `mr_description` when the user's language is Marathi
- [ ] Tell users their comment is awaiting moderation

**Sell (vendor role)**
- [ ] Vendor endpoints 403 without the role — prompt to become a vendor, not an error
- [ ] Render the Add-Product form from `categoryAttributeSchema`, never hardcoded
- [ ] Send `attributes` as a JSON string; expect errors keyed `attributes.<field>`
- [ ] Show the Add-Product button for `pending` outlets too, badged "under review"
- [ ] Warn before saving an approved product — it returns to review
- [ ] Don't render controls for `status` / `is_featured` / `is_bookable` / `fulfilment_type`
- [ ] `reorderProductMedia` needs the complete id list
- [ ] `limit: null` renders as "Unlimited"; surface a quota `message` from the create call

---

Full wire format and every edge case: `docs/vendor-products-api.md`.
Postman collection: `docs/tourkokan-vendor-products.postman_collection.json`.
See the whole vendor journey run live: `php artisan vendor:walkthrough` (§7).
