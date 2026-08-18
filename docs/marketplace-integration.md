# Tourkokan Marketplace — Integration Guide (App + Admin)

One document for both frontends.

- **App** (`tourkokan-v2` React Native, `tourkokan` web) — Parts **2–4**
- **Admin panel** (`admin-panel` React) — Part **5**

**Backend status:** implemented, 309 tests passing. Every payload below was captured from the
running app, not written from memory.

---

# Part 1 — Common contract (read once, applies everywhere)

**Base URLs** — app `{API_PATH}/api/v2` · admin `{VITE_ADMIN_API_BASE}` → `/admin/v2`
**Auth** — JWT, `Authorization: Bearer <token>` on every request
**Method** — every endpoint is **POST**, including reads

### 1.1 A failure usually returns HTTP 200 — read `success`, never the status

```js
const { success, message, data } = res.data
if (!success) throw new Error(typeof message === 'string' ? message : 'Request failed')
```

Two exceptions that *are* real status codes: **401** (no/expired token) and **403** (admin
role missing — see §5.1). Those come from middleware and have no `success` key.

### 1.2 `message` is a string **or** a field-errors object

```json
{ "success": false, "message": "Only a product awaiting review can be approved." }
{ "success": false, "message": { "name": ["The name field is required."] } }
```
Business errors → string. Validation errors → `{ field: ["error"] }`. Render the object form
against the matching inputs.

### 1.3 Envelope + pagination

```json
{ "version": "1.4.2", "language": "en", "success": true, "message": "…",
  "data": { "current_page": 1, "last_page": 3, "total": 42, "data": [ … ] } }
```
Paginated **rows are at `data.data`**. Send `page` + `per_page`; **`per_page` caps at 30**
(larger is silently clamped).

### 1.4 Language & images

- Content carries `name`/`mr_name` and `description`/`mr_description`. Pick by user language,
  fall back to English when the Marathi field is empty. Dynamic form labels also carry
  `mr_label`.
- **Images are mixed** — do not blanket-prefix:

  | Field | Form |
  |---|---|
  | `gallery[].path`, `cover.path` | **relative** → prefix `AWS_URL` |
  | `gallery[].path_url` | **absolute** (use this to skip prefixing) |
  | `site.logo`, `site.image`, `product_category.icon` | **absolute already** |

  Prefixing `AWS_URL` onto `site.logo` double-URLs it. Simplest: use `path_url` for gallery
  and treat everything as absolute.

### 1.5 Price

Always from the **default variant**, never `base_price` (which is a "from" figure and can be
stale):
```js
const price = row.default_variant?.sale_price ?? row.default_variant?.price ?? row.base_price
```
`unit` (`per_night`, `per_plate`, `per_kg`…) is the suffix.

---

# Part 2 — App · Browse (every user)

Everything here is already visibility-filtered: a product appears only if it is approved, its
business approved and published, and inside its availability window. The app never checks
status itself.

### 2.1 Products

| Endpoint | Payload | Notes |
|---|---|---|
| `listProducts` | `category_code?` `site_id?` `min_price?` `max_price?` `search?` `is_featured?` `latitude?`+`longitude?` `radius_km?` `sort?` `page?` | filters all optional |
| `productDetail` | `id` **or** `slug` | + `variants`, `gallery`, `price`, `is_favourite`, `rating_avg_rate` |
| `productsBySite` | `site_id` | one business's catalog |
| `featuredProducts` | `page?` | home rail |

`sort` — `latest` (default) · `price_asc` · `price_desc` · `popular` · `nearest`.
`latitude`+`longitude` go **together**; each row then gains `distance_km`; `nearest` needs
them and silently falls back to `latest` without.

`listProducts` row:
```json
{
  "id": 12, "name": "Deluxe Sea View Room", "slug": "deluxe-sea-view-room",
  "base_price": "2400.00", "currency": "INR", "unit": "per_night",
  "is_featured": false, "views_count": 84, "leads_count": 6,
  "rating_avg_rate": 4.5, "rating_count": 12, "distance_km": 2.83,
  "product_category": { "id": 7, "name": "Room Night", "code": "room_night" },
  "site": { "id": 41, "name": "Sagar Resort", "logo": "https://…",
            "latitude": 16.05, "longitude": 73.46,
            "phone": "9876543210", "whatsapp": "9876543210" },
  "default_variant": { "id": 30, "price": "2400.00", "sale_price": null, "stock": null },
  "cover": { "id": 88, "path": "…", "path_url": "https://…" }
}
```
`site.phone` / `site.whatsapp` / `latitude` / `longitude` drive the Call / WhatsApp /
Directions actions — public business contact, never the owner's personal number.

### 2.2 Vendor profiles (one owner, several businesses)

| Endpoint | Payload |
|---|---|
| `listVendors` | `search?` `category_id?` `category_code?` `latitude?`+`longitude?` `radius_km?` `page?` |
| `vendorProfile` | `id` (vendor id from a list row), `latitude?`+`longitude?` |

`listVendors` = one card per **owner**: `id`, `business_name`, `tag_line`, `logo`,
`outlet_count`, `product_count`, `categories[]`, `distance_km` (to the *nearest* outlet).
`vendorProfile` → `{ vendor, outlets[], products{paginated} }`. Identity is the business,
never the person; a vendor with no live business is 404.

### 2.3 Engagement — please wire these up

| Endpoint | Payload | Fire when |
|---|---|---|
| `recordProductView` | `id`, `platform?` | detail screen opens |
| `recordProductLead` | `id`, `lead_type`, `message?`, `platform?` | user taps call / WhatsApp / directions, or enquires |

`lead_type` — `call` · `whatsapp` · `directions` · `enquiry`. **Fire the lead on the tap,
before opening the dialler** — it is the number the business runs on. Both are
fire-and-forget: ignore the response, never block the UI. (A lead now also notifies the
vendor — §3.6.)

### 2.4 Favourite / rate / comment

Products reuse the generic morph endpoints with the literal type `"Product"`:
```
addDeleteFavourite  { favouritable_id, favouritable_type: "Product" }   toggles
addUpdateRating     { rateable_id, rateable_type: "Product", rate: 1..5 }
comment             { commentable_id, commentable_type: "Product", comment: "…" }
comments            { commentable_id, commentable_type: "Product" }
favourites          { favouritable_type?: "Product" }                    the list
```
**`favourites`** returns the caller's favourites, each with the full product card under
`favouritable`. A favourite whose listing is no longer live returns `"favouritable": null`
with `"unavailable": true` — render "no longer available". Comments are **moderated** —
invisible until an admin approves; say so after posting. `productDetail` already returns
`is_favourite`.

---

# Part 3 — App · Sell (vendor role)

All of Part 3 requires the **`vendor` role**. Without it these 403 with a prompt to request
the role — show it as "become a vendor", not an error. Same login and token as Browse.

### 3.1 Becoming a vendor

```
requestRole → (admin approves) → addSite → (admin approves) → list products
```
| Endpoint | Payload |
|---|---|
| `requestRole` | `role_code: "vendor"`, `reason?` |
| `myRoleRequests` | — (poll `status`) |
| `addSite` | **multipart**: `name`, `description` (≥20 chars), `categories[]` (site-category ids), `latitude`, `longitude`, `phone?`, `whatsapp?`, `pin_code?`, `tag_line?`, `image?` `logo?`, `social_media?` (JSON string) |
| `mySubmissions` | — all submissions, any status |
| `mySites` | — approved **and** pending outlets (the picker); returns `submission_status`, `phone`, `whatsapp`, `is_primary` |
| `updateMySubmission` / `deleteMySubmission` | `id` (+ fields) |
| `setPrimarySite` | `id` |

Site-category ids come from `listcategories` / `businessCategories` (§3.2). A vendor can add
products while the site is still `pending` — gate the button on the site not being
`rejected`, not on approval.

### 3.2 The add-product flow (server-rendered form)

```
① mySites                   pick the business
② allowedProductCategories  { site_id }              what it may sell
③ categoryAttributeSchema   { product_category_id }  the fields to render
④ addProduct                → DRAFT
⑤ uploadProductMedia        one call per image
⑥ saveProductVariant        optional extra price points
⑦ submitProductForReview    DRAFT → PENDING → (admin) → live
```

**`businessCategories`** (or the app filtering `listcategories` on `is_business`) gives only
the categories a vendor may register a business under — the tourist-directory branches
(Destination, Emergency, Government…) are excluded.

**③ `categoryAttributeSchema`** — render one input per key, in order:
```json
{ "id": 1, "name": "Room Night", "code": "room_night", "booking_type": "date_range",
  "attribute_schema": {
    "ac":        { "type": "bool", "label": "Air conditioned", "mr_label": "वातानुकूलित" },
    "bed_type":  { "type": "enum", "label": "Bed type", "options": ["Single","Double","Queen","King"] },
    "check_in":  { "type": "time", "label": "Check-in time" },
    "occupancy": { "type": "int",  "label": "Max guests", "required": true, "min": 1, "max": 20 }
  } }
```
| type | widget | send as |
|---|---|---|
| string/text | text/textarea | string |
| int/decimal | number (honour min/max) | number or numeric string |
| bool | switch | `true`/`false` (or `"true"`/`"1"`/`"yes"`) |
| enum | single-select `options` | the exact option |
| multi | multi-select `options` | JSON-array string or comma-separated |
| date / time | pickers | `YYYY-MM-DD` / `HH:MM` |

`attribute_schema` may be `{}`. `booking_type` is informational (enquiry-only) — no booking
UI yet.

**④ `addProduct`** (multipart — strings coerced server-side):
```
site_id, product_category_id, name, description?, mr_name?, mr_description?,
base_price?, sale_price? (≤ base_price), unit?, hsn_code?, tax_rate? (0/5/12/18/28),
price_includes_tax?, attributes (JSON string), available_from?, available_to?
```
Returns a **draft** + auto-created default variant. Attribute errors come back keyed
`attributes.<field>` with the schema's `label`; unknown keys are rejected.
**Do not render controls for** `status`, `is_featured`, `is_bookable`, `fulfilment_type` —
posting them is ignored.

### 3.3 Managing the catalog

| Endpoint | Payload |
|---|---|
| `myProducts` | `site_id?` `status?` `search?` `page?` — own listings, any status |
| `getProduct` | `id` — full detail incl. `attribute_schema`, `gallery`, `variants` (edit prefill) |
| `updateProduct` | `id` + any add field — **editing an approved product returns it to `pending`** |
| `deleteProduct` | `id` |
| `submitProductForReview` | `id` |
| `toggleProductStatus` | `id` — approved ⇄ paused |
| `bulkProductStatus` | `ids[]` (≤200), `status: paused\|approved` → `{updated, skipped}` |

Lifecycle: `draft → pending → approved ⇄ paused`, `rejected` carries `rejection_reason`.

**Media:** `uploadProductMedia` (`id`, `image` ≤4 MB, `title?` — one per call, first becomes
cover) · `deleteProductMedia` (`id`, `media_id`) · `setProductCover` (`id`, `media_id`) ·
`reorderProductMedia` (`id`, `media_ids[]` — send **every** id in new order).

**Variants:** `saveProductVariant` (`id`, `variant_id?`, `name`, `price`, `sale_price?`,
`sku?`, `stock?`, `is_default?`) · `deleteProductVariant` (`id`, `variant_id` — last one
cannot be deleted).

### 3.4 Dashboard, leads, analytics

| Endpoint | Payload | Returns |
|---|---|---|
| `myUsageStats` | `from?` `to?` | nested `{ views:{total}, leads:{total,call,…}, listings:{total,live,…}, conversion_rate }` — **keep nesting** |
| `productAnalytics` | `id`, `from?` `to?` | `{ product, totals, conversion_rate, leads_by_type, daily[] }` |
| `myLeads` | `product_id?` `lead_type?` `unread_only?` `page?` | enquiries newest-first + **`data.unread_count`** for the badge |
| `markLeadRead` | `id` **or** `all: true` | clears a handled enquiry |

Both stats endpoints **include today** (topped up before the nightly rollup), so they never
read zero on launch day. Daily gaps are absent, not zero-filled.

### 3.5 Subscription & limits

| Endpoint | Payload |
|---|---|
| `mySubscription` | — plan, `days_remaining`, live usage vs every quota |
| `listPlans` | — active plans for an upgrade screen |

`limit: null` = **Unlimited** (not 0). Listing is free for the launch year; a quota refusal
arrives on the **create** call (`addProduct`/`addSite`/`uploadProductMedia`) with a human
`message`. There is **no in-app upgrade/payment** — show "Contact us to switch".

### 3.6 Notifications — no new screen needed

Five events write to the **existing inbox** (`myMessages` / `readMessage` /
`unreadMessageCount`) and push if a device token exists:

| `type` | Fired when | `meta_data` |
|---|---|---|
| `lead` | a customer enquires | `product_id`, `lead_id`, `lead_type` |
| `product_approved` / `product_rejected` | admin reviews a listing (reason in message) | `product_id` |
| `site_approved` / `site_rejected` | admin reviews a business | `site_id` |
| `vendor_approved` | vendor role granted | — |

`admin_id` is `null` (system-generated); `type` + `meta_data` let you deep-link — a `lead`
opens that product's leads screen. The inbox row is durable; push is best-effort.

---

# Part 4 — App checklist

**Browse** — read `success` not status · rows at `data.data`, `per_page`≤30 · handle
`message` as string **and** object · price from `default_variant` · lat+lng together ·
`recordProductView`/`recordProductLead` · `mr_*` for Marathi · comments are moderated ·
`favourites` may return `unavailable:true` rows.

**Sell** — 403 = prompt to become a vendor · render the form from `categoryAttributeSchema` ·
`attributes` is a JSON string · Add-Product enabled for `pending` outlets · warn before
editing an approved product · no controls for status/is_featured/is_bookable/fulfilment_type ·
`limit:null` = Unlimited · surface quota `message` from the create call · notifications land
in `myMessages`.

---

# Part 5 — Admin panel

**Scope:** only the marketplace delta. The panel is already integrated with the rest of the
admin API; these are the new/changed endpoints for vendor products.

### 5.1 ⚠️ Breaking — `/admin/v2/*` now requires the admin role

The group was missing its `admin` middleware, so it was open to any authenticated user.
Fixed. A token now gets **403** on every admin endpoint if the user lacks an
`admin`/`superadmin` row in `user_roles`:
```json
{ "message": "Access Forbidden" }
```
This is a real 403 with **no `success` key** — handle globally. Before deploying, find
affected accounts:
```sql
SELECT u.id, u.email, GROUP_CONCAT(r.code) roles FROM users u
LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id
GROUP BY u.id HAVING roles IS NULL OR roles NOT LIKE '%admin%';
```

### 5.2 Product moderation (the daily screen)

| Endpoint | Payload |
|---|---|
| `pendingProducts` | `page?` — review queue, **oldest first** |
| `listAllProducts` | `status?` `site_id?` `product_category_id?` `search?` |
| `getProductAdmin` | `id` — full detail incl. variants, gallery, attribute schema |
| `approveProduct` | `id` |
| `rejectProduct` | `id`, `rejection_reason` (required) |
| `featureProduct` | `id`, `is_featured` |

Two non-obvious refusals: **`approveProduct` fails if the product's *site* isn't approved
and live** (a vendor can build a catalog while pending — surface the message and link to
site review); **`featureProduct` only accepts approved products**. `rejection_reason` and
approve/reject are **shown to the vendor** (as an in-app notification) — treat as
user-facing copy.

`status` values: `draft` · `pending` · `approved` · `rejected` · `paused`.

### 5.3 Product taxonomy — ships new verticals

| Endpoint | Payload |
|---|---|
| `listProductCategories` | `search?` `booking_type?` `status?` |
| `getProductCategory` | `id` |
| `addProductCategory` | `name`, `code` (unique snake_case), `mr_name?`, `parent_id?`, `description?`, `icon?` (image → multipart), `attribute_schema?`, `booking_type?`, `status?`, `sort_order?` |
| `updateProductCategory` | `id` + any add field |
| `deleteProductCategory` | `id` (refused if it has children) |
| `setAllowedProductCategories` | `category_id`, `allowed[]` — **replaces the whole set** |

**`addProductCategory` with an `attribute_schema` is how a new vendor vertical ships** — no
migration, no app release, because the app renders its form from it. A **field-builder UI**
(add field → pick type → set label/options) is worth more here than a JSON textarea. Types:
`string text int decimal bool enum multi date time`; `enum`/`multi` need `options`; keys must
be snake_case; reserved keys (`price`, `stock`, …) are rejected.

**`setAllowedProductCategories`** is the whitelist that stops a hospital listing mangoes.
Send the complete list; `allowed: []` revokes everything:
```json
{ "category_id": 12, "allowed": [ { "product_category_id": 3, "max_products": 50 },
                                  { "product_category_id": 7 } ] }
```

### 5.4 Vendors

| Endpoint | Payload | Returns |
|---|---|---|
| `listVendors` | `search?` `plan_code?` `has_pending_products?` `has_no_sites?` | one row per vendor: sites/products counts by status, plan |
| `getVendor` | `id` (user id) | businesses w/ categories, catalog by status, plan, usage, engagement, recent leads |

### 5.5 Plans & subscriptions (not urgent — free launch year)

`listPlans` · `addPlan` · `updatePlan` · `listSubscriptions` · `assignPlan` (`user_id`,
`plan_id`, `months?` — closes the previous subscription) · `vendorUsageReport` (`user_id` —
"why can't this vendor add more"). `limits` keys: `max_sites`, `max_products`,
`max_images_per_product`, `featured_slots` (`null` = unlimited). Paid tiers ship inactive;
going paid = activate + `assignPlan`.

### 5.6 Site & vendor review (existing, two behaviour changes)

`pendingSites` · `allSubmissions` (`submission_status?` `search?`) · `approveSite` ·
`rejectSite` (`id`, `rejection_reason`) · `userRoleRequests` · `approveRoleRequest` ·
`rejectRoleRequest` · `sendMessage` (manual message to a user).

Two existing endpoints now do more — **payloads unchanged**:
- **`approveSite`** auto-marks a vendor's **first** approved site as primary, and notifies
  the vendor.
- **`approveRoleRequest`** for a `vendor` request also enrols them on the **free plan** and
  notifies them.

> **Site list gotcha (fixed):** `sites` with `global=1` now includes vendor businesses (they
> are parentless, like villages). Also accepts `category_id` (not only `category` code). If
> the panel filtered sites and vendor businesses were missing, this was why.

### 5.7 Notifications need no admin work

The five vendor notifications in §3.6 fire **automatically** inside `approveProduct` /
`rejectProduct` / `approveSite` / `rejectSite` / `approveRoleRequest`. Same request, same
response — nothing to build. For an ad-hoc message, `sendMessage` already exists.

---

# Part 6 — Deploy checklist (backend ops)

```bash
php artisan migrate --force
php artisan db:seed --class=PlanSeeder --force
php artisan db:seed --class=ProductCategorySeeder --force
php artisan db:seed --class=VendorCategorySeeder --force   # order matters
```
Plus the scheduler cron, or analytics read zero forever:
```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```
**Do not** run `demo:vendors` on shared envs — that is local fake data. Local test data:
`php artisan demo:vendors` then `php artisan products:rollup-stats --days=21`, remove with
`--purge`.

---

_Detailed captured admin payloads: `docs/admin-api-integration.md`. Full vendor contract:
`docs/vendor-products-api.md`. Live walkthrough: `php artisan vendor:walkthrough`._
