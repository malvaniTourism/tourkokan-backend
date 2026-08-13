# Vendor Products — API Contract

For the app developer building the vendor and catalog screens in `tourkokan-v2`
(React Native) and, later, the web frontend.

**Base URLs** — user API `{API_PATH}/api/v2`, admin API `{API_PATH_ADMIN}/admin/v2`
**Postman** — `docs/tourkokan-vendor-products.postman_collection.json` (65 requests, import and go)
**Auth** — JWT, `Authorization: Bearer <token>` on every request below
**Method** — every endpoint is `POST`, including reads (platform convention)
**Backend status** — implemented and covered by 260 tests. Design rationale lives in
`docs/VENDOR_PRODUCTS_DESIGN.md`; this file is the wire format.

---

## 1. Things that will bite you first

### 1.1 A failed request usually returns HTTP 200

`sendResponse` and most validation paths answer **200** with `success: false`.
**Never branch on the status code alone** — always read `success`.

```json
{ "success": false, "message": "Unauthorised Access" }
```

That particular message means the server could not resolve an app version (an
`app_versions` row is missing) — it is a server-side setup problem, not something the app
sends. Report it rather than retrying.

### 1.2 Two response shapes

Success:
```json
{
  "version": "1.4.2",
  "language": "en",
  "success": true,
  "message": "Products fetched.",
  "data": { }
}
```

Failure (`sendError`) — status may be 400, 403, 404 or 422:
```json
{
  "success": false,
  "message": { "name": ["The name field is required."] }
}
```

`message` is a **string for business errors and an object of field → errors for validation
failures**. Handle both: if it is an object, surface the first message per field against
the matching form input.

### 1.3 Paginated lists

Anything returning a list wraps a standard Laravel paginator inside `data`:

```json
{ "success": true, "data": { "current_page": 1, "data": [ ... ], "last_page": 3, "total": 42 } }
```

So the rows are at **`data.data`**. Send `page` and `per_page` (default 15, **hard max 30** —
larger values are silently clamped, not rejected).

### 1.4 Language

Content carries `name` / `mr_name` and `description` / `mr_description`. The server also
echoes the caller's `language`. Marathi labels for dynamic form fields arrive as `mr_label`
inside the attribute schema — see §4.

### 1.5 Rate limits

`throttle:writes` on mutations, `throttle:uploads` on image upload. A 429 returns:
```json
{ "success": false, "message": "Too many requests. Please slow down and try again later." }
```
Respect the `Retry-After` header.

---

## 2. Becoming a vendor

```
register / login
   └── POST requestRole      { role_code: "vendor", reason }
          ↓  admin approves (no app action)
       POST addSite          the business, with its categories and location
          ↓  admin approves (no app action)
       vendor endpoints unlock
```

Until the `vendor` role is granted, every vendor endpoint answers **403** with a message
telling the user to request the role. Show that as a prompt, not an error.

| Endpoint | Payload | Notes |
|---|---|---|
| `requestRole` | `role_code` (`"vendor"`), `reason?` | 422 if a request is already pending |
| `myRoleRequests` | — | poll for `status` |
| `addSite` | see below | vendor-gated |
| `mySubmissions` | — | all submissions with `submission_status` |
| `mySites` | — | **the outlet picker**, §3 |
| `setPrimarySite` | `id` | marks the head location |

`addSite` (multipart):
```
name, description (min 20 chars), categories[] (site category ids),
latitude, longitude, tag_line?, domain_name?, pin_code?,
image? logo? (jpeg/jpg/png/webp), social_media? speciality? rules? (JSON strings)
```

The first site an admin approves automatically becomes the vendor's **primary** location.

---

## 3. Adding a product — the screen flow

This is the part worth reading twice. The form is **rendered from the server**, so a new
product vertical never needs an app release.

```
 ① mySites                    → vendor picks an outlet
 ② allowedProductCategories   → { site_id }  → what this outlet may sell
 ③ categoryAttributeSchema    → { product_category_id } → the fields to render
 ④ addProduct                 → returns a DRAFT
 ⑤ uploadProductMedia         → one call per image
 ⑥ saveProductVariant         → optional, for multiple price points
 ⑦ submitProductForReview     → DRAFT → PENDING
       ↓  admin approves
    live in the public catalog
```

**A vendor may add products while their business is still `pending`.** Do not gate the
Add-Product button on site approval — gate it on the site not being `rejected`. `mySites`
returns `submission_status`; badge pending outlets as "under review" and let the vendor
carry on.

### Status lifecycle

```
draft ──submitProductForReview──▶ pending ──admin──▶ approved ⇄ paused
  ▲                                   │                (toggleProductStatus)
  └───────── rejected ◀────admin──────┘
```

- `draft` — vendor-only, editable freely. Use it as an autosave target on a patchy network.
- Editing an **approved** product returns it to `pending` automatically. Warn the vendor:
  *"Saving will send this listing for review again."*
- `rejected` carries `rejection_reason` — show it, and allow resubmission.
- `paused` is vendor-controlled; `approved ⇄ paused` via `toggleProductStatus`.

---

## 4. The dynamic form (`categoryAttributeSchema`)

Response `data`:

```json
{
  "id": 7,
  "name": "Room Night",
  "mr_name": "रूम प्रति रात्र",
  "code": "room_night",
  "booking_type": "date_range",
  "attribute_schema": {
    "occupancy": { "type": "int",  "label": "Max guests", "mr_label": "जास्तीत जास्त पाहुणे",
                   "required": true, "min": 1, "max": 20 },
    "ac":        { "type": "bool", "label": "Air conditioned" },
    "meal_plan": { "type": "enum", "label": "Meal plan", "options": ["EP","CP","MAP","AP"] },
    "check_in":  { "type": "time", "label": "Check-in time" }
  }
}
```

Render one input per key, in object order. `attribute_schema` may be `{}` — then the
category has no extra fields.

| `type` | Render as | Send as |
|---|---|---|
| `string` | single-line text (`max`, default 255) | string |
| `text` | multi-line (`max`, default 5000) | string |
| `int` | number input, honour `min`/`max` | number or numeric string |
| `decimal` | number input | number or numeric string |
| `bool` | switch | `true`/`false`/`"true"`/`"1"`/`"yes"` — all accepted |
| `enum` | single-select from `options` | the option string exactly |
| `multi` | multi-select from `options` | JSON array string, or comma-separated |
| `date` | date picker | `YYYY-MM-DD` |
| `time` | time picker | `HH:MM` (24-hour) |

Use `mr_label` when the user's language is Marathi, falling back to `label`.

**Send `attributes` as a JSON string** when posting multipart:

```
attributes = {"occupancy":"3","ac":"true","meal_plan":"CP","check_in":"12:00"}
```

The server normalises multipart strings, so `"3"` → `3` and `"true"` → `true`. Validation
errors come back keyed `attributes.<field>`, using the schema's `label`:

```json
{ "success": false, "message": { "attributes.occupancy": ["The Max guests must not be greater than 20."] } }
```

Map those directly onto the rendered inputs. **Unknown keys are rejected** — send only what
the schema declares.

`booking_type` is informational today (everything is enquiry-only). Do not build booking UI
against it yet.

---

## 5. Vendor endpoints

### Catalog

| Endpoint | Payload |
|---|---|
| `myProducts` | `site_id?`, `status?`, `search?`, `page?`, `per_page?` |
| `getProduct` | `id` — full detail including `attribute_schema`, for editing |
| `addProduct` | see below |
| `updateProduct` | `id` + any of the add fields |
| `deleteProduct` | `id` (soft delete) |
| `submitProductForReview` | `id` |
| `toggleProductStatus` | `id` — approved ⇄ paused |

`addProduct` fields:

```
site_id                 required
product_category_id     required, must come from allowedProductCategories
name                    required, 2–150
mr_name?                description?  mr_description?
attributes?             JSON string, validated against the category schema
base_price?             the headline figure shown as "from ₹…"
sale_price?             must be ≤ base_price
unit?                   per_night | per_person | per_plate | per_kg |
                        per_hour | per_piece | per_package
hsn_code?               numeric, ≤ 12 chars — GST HSN (goods) or SAC (services)
tax_rate?               one of 0, 5, 12, 18, 28
price_includes_tax?     default true
available_from?         available_to?   YYYY-MM-DD
sort_order?
```

Collect `hsn_code` and `tax_rate` if the vendor knows them — the fields are optional now and
become required when selling through the platform goes live. A rate outside the GST slabs
is rejected.

**Not settable by a vendor** — `status`, `is_featured`, `is_bookable`, `fulfilment_type`.
Posting them is ignored silently, so do not render controls for them.

### Media

| Endpoint | Payload | Notes |
|---|---|---|
| `uploadProductMedia` | `id`, `image` (jpeg/jpg/png/webp, ≤ 4 MB), `title?` | one image per call, `throttle:uploads` |
| `deleteProductMedia` | `id`, `media_id` | deleting the cover promotes the next image |
| `setProductCover` | `id`, `media_id` | |
| `reorderProductMedia` | `id`, `media_ids[]` | **send every image id, in the new order** — a partial list is rejected |

The first image uploaded becomes the cover automatically. `title` defaults to the product
name if omitted.

### Variants

A variant is a price point — "Deluxe AC Room", "1 kg box", "Half plate". Every product
always has at least one; if the vendor enters a single price, the server creates a
`Standard` variant for it. **`data.price` on a product comes from the default variant**, not
from `base_price`.

| Endpoint | Payload |
|---|---|
| `saveProductVariant` | `id`, `variant_id?` (omit to create), `name`, `price`, `sale_price?`, `sku?`, `stock?`, `min_order_qty?`, `max_order_qty?`, `is_default?`, `sort_order?` |
| `deleteProductVariant` | `id`, `variant_id` — **the last variant cannot be deleted** (422) |

Setting `is_default: true` clears the flag on the others.

---

## 5b. Vendor analytics and plan

### Analytics

| Endpoint | Payload | Returns |
|---|---|---|
| `myUsageStats` | `from?`, `to?` (YYYY-MM-DD, default last 30 days, max 365) | account totals |
| `productAnalytics` | `id`, `from?`, `to?` | one listing plus a daily series |
| `myLeads` | `product_id?`, `lead_type?`, `page?` | the actual enquiries, newest first |

`myUsageStats` returns:
```json
{
  "from": "2026-07-08", "to": "2026-08-06",
  "listings": { "total": 12, "live": 9, "draft": 1, "pending": 2, "rejected": 0, "paused": 0 },
  "views":    { "total": 840, "unique": 612 },
  "leads":    { "total": 47, "call": 21, "whatsapp": 18, "directions": 6, "enquiry": 2 },
  "conversion_rate": 5.6
}
```

> These read `product_daily_stats`, topped up with anything the nightly rollup has not
> reached yet — so **today's activity is included** and the totals agree with `myLeads`.
> A missed nightly run self-corrects the same way.

`productAnalytics.daily` is an array of `{date, views, unique_views, leads, leads_call,
leads_whatsapp, leads_directions, leads_enquiry}`. **Days with no activity are absent, not
zero-filled** — zero-fill client-side if the chart needs a continuous axis.

`unique_views` counts distinct sessions. Show it alongside `views`, not instead of it.

Frame leads as the headline number and views as supporting context — leads are what the
vendor is being delivered, and eventually what they pay for.

### Plan and quota

| Endpoint | Payload | Returns |
|---|---|---|
| `mySubscription` | — | current plan, end date, and live usage against every quota |
| `listPlans` | — | active plans, for an upgrade screen |

```json
{
  "plan": { "code": "free", "name": "Free", "price": "0.00", "billing_period": "free" },
  "subscription": { "starts_at": "…", "ends_at": "…", "days_remaining": 284, "status": "active" },
  "usage": {
    "max_sites":              { "limit": 5,   "used": 2,  "remaining": 3,  "exceeded": false },
    "max_products":           { "limit": 100, "used": 12, "remaining": 88, "exceeded": false },
    "max_images_per_product": { "limit": 10 },
    "featured_slots":         { "limit": 0,   "used": 0,  "remaining": 0,  "exceeded": true }
  }
}
```

`limit: null` means **unlimited** — render it as "Unlimited", not as zero.

**Quota refusals arrive on the create endpoints**, not here. `addProduct`, `addSite` and
`uploadProductMedia` answer `success: false` with a human message and a `data.limit` block:

```json
{
  "success": false,
  "message": "Your Free plan allows 100 products and you have 100. Upgrade to add more.",
  "data": { "limit": { "limit": 100, "used": 100, "remaining": 0, "exceeded": true } }
}
```

Show `message` directly — it already names the plan and the number. Listing is free for the
launch year with limits set high enough that a real vendor will not meet them, so treat this
as a rare path, not a common one.

---

## 6. Public catalog (any logged-in user)

Only listings that are approved, on an approved and published site, and inside their
availability window are ever returned.

### `listProducts`

```
product_category_id?  category_code?  site_id?
min_price?  max_price?        max alone is fine
booking_type?                 none | date_range | slot | quantity
search?                       matches name and mr_name
is_featured?
latitude? longitude?          must be sent together
radius_km?                    0.1–500, needs latitude+longitude
sort?                         latest (default) | price_asc | price_desc | popular | nearest
page?  per_page?
```

When `latitude`+`longitude` are sent, each row gains **`distance_km`**. `sort: nearest`
falls back to `latest` if no location was supplied.

Row shape:
```json
{
  "id": 12, "name": "Deluxe Sea View Room", "slug": "deluxe-sea-view-room",
  "base_price": "2400.00", "currency": "INR", "unit": "per_night",
  "is_featured": false, "fulfilment_type": "enquiry",
  "views_count": 84, "leads_count": 6,
  "rating_avg_rate": 4.5, "rating_count": 12,
  "distance_km": 2.83,
  "product_category": { "id": 7, "name": "Room Night", "code": "room_night", "booking_type": "date_range" },
  "site": { "id": 41, "name": "Sagar Resort", "logo": "https://…", "latitude": 16.05, "longitude": 73.46 },
  "default_variant": { "id": 30, "price": "2400.00", "sale_price": null, "stock": null },
  "cover": { "id": 88, "path": "https://…" }
}
```

Price to display: `default_variant.sale_price ?? default_variant.price`.

### Others

| Endpoint | Payload | Returns |
|---|---|---|
| `productDetail` | `id` **or** `slug` | full detail + `variants`, `gallery`, `price`, `is_favourite`, `rating_avg_rate` |
| `productsBySite` | `site_id` | one business's catalog |
| `featuredProducts` | `page?` | home rail |

### Vendor directory — one owner, several businesses

A vendor can run more than one outlet, so these browse by **business owner** rather than by
site. Only vendors with at least one live business appear, and only their live outlets and
products are exposed. Identity is the vendor's **primary business**, never the owner's
personal name/email — those are encrypted and never returned.

#### `listVendors`

```
search?  category_id?  category_code?
latitude? longitude?   sent together
radius_km?             needs latitude+longitude; distance is to the nearest outlet
page?  per_page?
```

Card shape (rows at `data.data`):
```json
{
  "id": 51,
  "business_name": "Sai Electric Works",
  "mr_name": null,
  "tag_line": "Trusted local service",
  "logo": null,
  "outlet_count": 3,
  "product_count": 3,
  "distance_km": 4.2,
  "categories": [ { "id": 91, "name": "Electrician", "code": "electrician" } ]
}
```

`id` is the vendor id — pass it to `vendorProfile`. `distance_km` is present only when a
location was sent.

#### `vendorProfile`  `{ id, latitude?, longitude? }`

One vendor with **all** their live businesses and their combined catalog. `data` has three
keys — `vendor`, `outlets`, `products` (paginated):

```json
{
  "vendor": {
    "id": 9,
    "business_name": "Sagar Resort Group",
    "tag_line": "Family run since 1994",
    "logo": null, "image": null,
    "description": "…",
    "social_media": null, "domain_name": null,
    "member_since": "2026-08-08T07:53:15Z",
    "outlet_count": 3,
    "product_count": 27,
    "categories": [ { "id": 11, "name": "Hotel Rooms", "code": "hotel_rooms" } ]
  },
  "outlets":  [ /* each site: name, tag_line, logo, lat/lng, categories, products_count, rating_avg_rate, distance_km? */ ],
  "products": { "current_page": 1, "data": [ /* same row shape as listProducts */ ], "total": 27 }
}
```

404 if the id is not a vendor, or the vendor has no live business yet.

### Engagement — please wire these up

| Endpoint | Payload | When |
|---|---|---|
| `recordProductView` | `id`, `platform?`, `referrer?` | product detail screen opens |
| `recordProductLead` | `id`, `lead_type`, `message?`, `platform?` | user taps call / WhatsApp / directions, or submits an enquiry |

`lead_type` — `call` · `whatsapp` · `directions` · `enquiry`

**Leads are the metric the business runs on.** Fire `recordProductLead` on the tap, before
opening the dialler or WhatsApp — do not wait for the call to connect. Both endpoints are
fire-and-forget: ignore the response, never block the UI, never retry on failure.

### Favourite / rate / comment

Products use the platform's existing generic endpoints. Pass the literal string `"Product"`
as the type:

```
addDeleteFavourite   { favouritable_id: <product id>, favouritable_type: "Product" }
addUpdateRating      { rateable_id: <product id>, rateable_type: "Product", rate: 1..5 }
comment              { commentable_id: <product id>, commentable_type: "Product", comment: "…" }
comments             { commentable_id, commentable_type: "Product" }
```

Comments are moderated — a new one is stored but **not visible until an admin approves it**.
Tell the user so, or it looks like the post failed.

---

## 7. Admin endpoints (`/admin/v2`, admin role required)

For the admin panel, not the app.

```
pendingProducts   listAllProducts   getProductAdmin
approveProduct    rejectProduct { id, rejection_reason }   featureProduct { id, is_featured }

listProductCategories  getProductCategory  addProductCategory
updateProductCategory  deleteProductCategory  setAllowedProductCategories

listPlans   addPlan   updatePlan
listSubscriptions   assignPlan   vendorUsageReport
```

Creating a product category with an `attribute_schema` is what ships a new vendor vertical —
no migration and no app release. Attribute keys must be `snake_case`, `enum`/`multi` must
declare `options`, and keys such as `price` or `stock` are refused: anything varying by date
belongs to pricing, not attributes.

**Plans.** Quotas live in `plans.limits` as `{key: int|null}`; `null` means unlimited, and an
absent key is treated as unlimited too, so an older plan never becomes retroactively
restrictive. Valid keys are `max_sites`, `max_products`, `max_images_per_product`,
`featured_slots` — anything else is refused, because a typo would silently stop being
enforced.

Paid tiers ship seeded but inactive, so they are neither advertised by the user-facing
`listPlans` nor assignable. **Going paid is a data change**: activate the tier, then
`assignPlan {user_id, plan_id, months?}`, which closes the vendor's previous subscription
rather than leaving two live.

`vendorUsageReport {user_id}` answers "why can this vendor not add more?" — their plan,
subscription dates, and usage against every quota.

---

## 8. Checklist for the app build

- [ ] Read `success`, never the status code alone
- [ ] Rows are at `data.data`; `per_page` caps at 30
- [ ] `message` may be a string **or** a field→errors object
- [ ] Render the Add-Product form from `categoryAttributeSchema` — no hardcoded per-category screens
- [ ] Send `attributes` as a JSON string; expect errors keyed `attributes.<field>`
- [ ] Show the Add-Product button for `pending` outlets too, badged "under review"
- [ ] Warn before saving an approved product — it returns to review
- [ ] Surface `rejection_reason` on rejected listings
- [ ] Display price from the default variant, not `base_price`
- [ ] `reorderProductMedia` needs the complete id list
- [ ] Fire `recordProductView` on detail open and `recordProductLead` on every contact tap
- [ ] Tell users their comment awaits moderation
- [ ] `limit: null` renders as "Unlimited", never as 0
- [ ] Show the quota refusal `message` directly; it already names the plan and the number
