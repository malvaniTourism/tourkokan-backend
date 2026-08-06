# Vendor Product Listing — Design & Implementation Plan

**Status:** Phases 1–3 implemented · Phase 4 (products) next
**Date:** 2026-08-05
**Branch:** `feature/vendor-products`
**Client:** Tourkokan mobile app (`tourkokan-v2`, React Native) — vendors add products from the app
**Backend:** `tourkokan-backend` (Laravel 12)

---

## Table of contents

1. [What already exists](#1-what-already-exists--do-not-rebuild)
2. [Core model decisions](#2-core-model-decisions)
3. [Booking-ready design](#3-booking-ready-design--read-before-writing-any-pricing-code)
4. [Phase 1 — Remove `projects`](#4-phase-1--remove-projects-entirely)
5. [Schema](#5-schema)
6. [Attribute-schema engine](#6-attribute-schema-engine--app-driven-forms)
7. [API surface](#7-api-surface)
8. [Mobile app flow](#8-mobile-app-flow-tourkokan-v2)
9. [Monetization](#9-monetization)
10. [Phases](#10-implementation-phases)

---

## 1. What already exists — do NOT rebuild

The vendor onboarding path is **complete and live**. Only the catalog layer is missing.

| Piece | Where | State |
|---|---|---|
| `vendor` role | `roles` id=8, code `vendor` | ✅ seeded |
| User↔Role pivot | `user_roles`, `User::roles()`, `User::hasRole()` | ✅ working |
| Role request flow | `requestRole` / `myRoleRequests` / admin `approveRoleRequest` | ✅ working |
| Vendor gate | `App\Http\Middleware\VendorMiddleware`, alias `vendor` | ✅ built + registered |
| Site submission | `submitSite` — vendor-gated, `routes/api.php:308` | ✅ working |
| Site moderation | `pendingSites` / `approveSite` / `rejectSite` | ✅ working |
| Site ownership | `sites.user_id` | ✅ established pattern |
| Site geo tree | `sites.parent_id` → District > City/Village > Place (167/172) | ✅ populated |
| Reusable morphs | Comment, Rating, Favourite, Contact, Gallery | ✅ working |
| Monetization precedent | `banner_packages` + `banner_placements` + `banners.impressions/clicks` | ✅ mirror it |
| Activity logging | `user_activity_logs` (entity_type/entity_id/platform/meta_data) | ✅ reuse for metering |
| Upload throttle | `throttle:uploads`, `throttle:writes` | ✅ working |

**Live onboarding flow:**
```
register → requestRole('vendor') → admin approveRoleRequest → vendor role granted
        → addSite (vendor-gated) → submission_status='pending'
        → admin approveSite → status=true
        → [ MISSING: add products ]  ← this document
```

---

## 2. Core model decisions

### 2.1 No `vendors` table
A vendor is a **user holding the `vendor` role**. The role gives identity; `sites.user_id` gives
ownership. Business details already exist as `sites` columns (`name`, `logo`, `icon`,
`domain_name`, `social_media`, `speciality`, `rules`, `latitude`, `longitude`, `pin_code`).
KYC / GSTIN / PAN live in `sites.meta_data` until payouts are actually built.

### 2.2 `parent_id` stays geographic — never reuse it for branches
`sites.parent_id` is the **location tree**, and code depends on that meaning:

- `app/Services/SiteService.php:111` — hot places where `parent_id = $siteId`
- `app/Services/SiteService.php:34` — browse a village's places
- `app/Http/Controllers/Admin/V2/SiteController.php:71` — `whereNotNull('parent_id')` = "a real place"

A branch pointed at its head office would **vanish from its own village's listing** and from
nearby-search — the opposite of what a vendor pays for. Every branch keeps its true
geographic parent.

### 2.3 Multi-outlet vendors are grouped by `user_id`

```
User #42 (role: vendor)
  ├─ "Sagar Resort — Tarkarli"   user_id=42  parent_id=Tarkarli   is_primary=true
  ├─ "Sagar Resort — Malvan"     user_id=42  parent_id=Malvan     is_primary=false
  └─ "Sagar Foods — Vengurla"    user_id=42  parent_id=Vengurla   is_primary=false
        └─ products (site_id)
```

One new column — `sites.is_primary` — is the entire vendor layer.

| Need | Query |
|---|---|
| Vendor's outlets | `Site::where('user_id', $id)` |
| Primary business | `...->where('is_primary', true)` |
| Vendor's products | `Product::whereHas('site', fn($q) => $q->where('user_id', $id))` |
| Places in Malvan | `Site::where('parent_id', $malvanId)` — branches included ✅ |

### 2.4 Flatten the `productable` morph
The 2022 design made `products` a thin join to per-vertical tables (Food / TourPackage /
Accomodation). Each new vertical cost a migration + model + controller + `getData()` case +
`morphMap` entry + admin UI. That is precisely why it stalled with three half-built verticals.

**Replaced by a category-defined attribute schema** — see [§6](#6-attribute-schema-engine--app-driven-forms).
A new vertical becomes **one row**: no migration, no code, no app release.

### 2.5 `allowed_product_categories` kept as originally designed
Maps site category → permitted product categories, on top of the existing 81-row taxonomy:

| Site category | Allowed product categories |
|---|---|
| Hotel Rooms / Resort / Lodge (9.x) | Room Night, Stay Package |
| Restaurant / Cafe (15.x) | Menu Item, Thali, Combo |
| Local Market (74.x) | Alphonso, Kokum, Cashew, Handicraft |
| Water Sport (44.x) | Activity Ticket, Equipment Rental |

This is what stops a hospital from listing mangoes. Extended with `is_required` and
`max_products` so it doubles as a per-category quota.

---

## 3. Booking-ready design — READ BEFORE WRITING ANY PRICING CODE

> **Booking/availability is NOT being built now.** This section exists so that adding a
> calendar later is a **pure addition**, never a rewrite. The hooks below are cheap today and
> expensive to retrofit. Follow all six rules.

**Target shape once booking lands:**

```
products
  └─ product_variants              "Deluxe AC Room", "Non-AC Room", "1 kg box"
       └─ product_availability     ← FUTURE: date, slots_total, slots_booked, price_override
            └─ bookings            ← FUTURE
```

### The six forward-compatibility rules

**R1 — Price is resolved through the variant, never off the product.**
`products.base_price` is a *display* value only ("starting from ₹1,200"). `product_variants.price`
is authoritative. A future `product_availability.price_override` slots in above the variant.
If you price only on `products` now, adding per-date pricing later means rewriting **every**
price read in backend, app, and web. Every product gets at least one variant
(`is_default = true`), auto-created if the vendor doesn't define one.

**R2 — Ship `product_categories.booking_type` now, even though nothing reads it.**
```
none        enquiry-only listing (default — everything at launch)
date_range  accommodation: check-in → check-out          ← calendar
slot        activities/water sports: fixed time slots     ← calendar
quantity    physical goods: plain stock, no calendar
```
Seed accommodation categories as `date_range` and activity categories as `slot` from day one.
When the calendar is built, the taxonomy is already correct and no re-seeding is needed.

**R3 — Ship `products.is_bookable` now, default `false`.**
Every launch listing is enquiry-only. Flipping a product to bookable later is a boolean update,
not a migration.

**R4 — Lock `unit` semantics now.** Booking math depends on it:
```
per_night | per_person | per_plate | per_kg | per_hour | per_piece | per_package
```
Store the enum code, never free text. `per_night` + `date_range` is what makes nightly
calculation possible without a data cleanup.

**R5 — Nothing date-varying goes in `attributes` JSON.**
`attributes` is for *static* facts (occupancy, AC, cuisine, grade). Anything that changes by
date — price, availability, slots — belongs in the future `product_availability` table.
Violating this makes the calendar migration a data-archaeology project.

**R6 — `lead_type` is an extensible enum.**
```
call | whatsapp | directions | enquiry     ← now
+ booking_request                          ← added later, no schema change
```
Leads are the pre-booking. When booking arrives, a booking is just a lead that converted, and
the metering pipeline already handles it.

### Where this note lives in the code

So a future developer cannot miss it, the same reference is planted in four places:

| Location | Marker |
|---|---|
| `docs/VENDOR_PRODUCTS_DESIGN.md` | this section (canonical) |
| `..._create_products_table.php` | header comment: `BOOKING-READY: see docs/VENDOR_PRODUCTS_DESIGN.md §3 (R1–R6)` |
| `app/Models/Product.php` | docblock on `price()` accessor citing R1 |
| `app/Models/ProductCategory.php` | docblock on `booking_type` cast citing R2 |

---

## 4. Phase 1 — Remove `projects` entirely

Verified safe: `projects` = 0 rows, `photos` = 0 rows, `users.project_id` = 0 non-null values.

**Delete files**
- `app/Models/Projects.php`
- `app/Http/Controllers/API/V1/ProjectsController.php`
- `database/seeders/ProjectSeeder.php`

**Strip references**

| File | What |
|---|---|
| `app/helpers.php:7,44` | `use Projects` + `case 'Projects'` in `getData()` |
| `app/Models/User.php:32,157,167` | `project_id` fillable, `belongsTo(Projects)`, `hasMany(Projects)` |
| `app/Models/City.php:63` | `hasMany(Projects)` |
| `app/Models/Favourite.php:50` | `belongsTo(Projects,'favouritable_id')` — broken anyway |
| `app/Models/Photos.php:22` | `project_id` fillable |
| `app/Http/Controllers/User/V2/LandingPageController.php:12` | dead import |
| `app/Http/Controllers/API/V1/LandingPageController.php:12,70` | dead (V1 fully commented out) |
| `app/Http/Controllers/API/V1/CategoryController.php:39` | `getAllProjects()` |
| `config/constants.php:24` | `'project'` upload path |

**Also delete — empty, broken, and FK'd to `projects`**
- Tables: `accomodations`, `accomodation_categories`, `tour_packages`, old `products`
- Models: `Accomodation`, `AccomodationCategory`, `TourPackage`
- Controllers: `Admin/AccomodationController` (Roles copy-paste, fatal),
  `Admin/AccomodationCategoryController`, `Admin/TourPackageController`,
  `Admin/ProductController` (stubs + wrong relation name)
- Legacy `/admin/api/*` product routes — `routes/admin.php:83-115`
- `morphMap` entry `'accomodation'` — `app/Providers/AppServiceProvider.php:78`
- `ProductSeeder` — inserts columns that don't exist

Side effect: retires the `accomodation` misspelling baked into the morph map.

**Migration** `2026_08_05_000001_drop_projects_and_legacy_product_tables.php`
Order: drop FKs → drop `users.project_id`, `photos.project_id` → drop child tables → drop `projects`.

---

## 5. Schema

### 5.1 Modified

**`sites`** — one column
```php
$table->boolean('is_primary')->default(false)->after('user_id')->index();
```

**`product_categories`** — extend + fix two live bugs
```php
// existing: id, name, icon, meta_data, timestamps
$table->unsignedInteger('parent_id')->nullable()->after('id');
$table->string('code')->unique()->after('name');
$table->string('slug')->unique();
$table->string('mr_name')->nullable();                    // Marathi — app is i18n
$table->json('attribute_schema')->nullable();             // ← the engine, §6
$table->enum('booking_type', ['none','date_range','slot','quantity'])
      ->default('none');                                  // ← R2, §3
$table->boolean('status')->default(true);
$table->integer('sort_order')->default(0);

$table->string('icon')->nullable()->change();             // BUG FIX: NOT NULL today
$table->json('meta_data')->nullable()->change();          // BUG FIX: NOT NULL today
```

**`allowed_product_categories`** — extend
```php
$table->boolean('is_required')->default(false);
$table->unsignedInteger('max_products')->nullable();
$table->unique(['category_id', 'product_category_id']);
```

### 5.2 Rebuilt

**`products`**
```
id
site_id                FK sites cascade         owner derived: site->user_id
product_category_id    FK product_categories
name, mr_name, slug
description, mr_description
attributes             json          validated against category attribute_schema (§6)
base_price             decimal(10,2) nullable   DISPLAY ONLY — see R1
sale_price             decimal(10,2) nullable
currency               char(3) default 'INR'
unit                   enum per_night|per_person|per_plate|per_kg|
                            per_hour|per_piece|per_package               ← R4
is_bookable            boolean default false                             ← R3
status                 enum draft|pending|approved|rejected|paused  default draft
rejection_reason       text nullable
is_featured            boolean default false
available_from / to    date nullable
views_count            unsigned int default 0    denormalized for fast display
leads_count            unsigned int default 0
sort_order             int default 0
timestamps + softDeletes

INDEX  (site_id, status), (product_category_id, status), (status, is_featured)
UNIQUE (site_id, slug)
```

### 5.3 New

**`product_variants`** — R1 makes this mandatory, not optional
`product_id`, `name`, `sku` nullable, `price` decimal, `stock` nullable,
`attributes` json, `is_default` bool, `status` bool

**`product_media`** — `product_id`, `path`, `type` (image|video), `sort_order`, `is_cover`
*(alternative: reuse the existing `Gallery` morph via `galleryable`, consistent with Site/Event)*

**`product_view_events`** — append-only raw log, pruned at 90 days
`product_id`, `user_id` nullable, `session_hash`, `platform`, `referrer`, `ip_hash`, `created_at`

**`product_leads`** — `product_id`, `user_id` nullable,
`lead_type` enum(call, whatsapp, directions, enquiry) ← R6, `message` nullable, `platform`, `created_at`

**`product_daily_stats`** — permanent rollup
`product_id`, `date`, `views`, `unique_views`, `leads`, `shares` — UNIQUE `(product_id, date)`

**`plans`** — `code` (free|starter|growth), `name`, `price`, `billing_period`, `limits` json, `is_active`
`limits` = `{max_sites, max_products, max_images_per_product, featured_slots, included_leads}`

**`vendor_subscriptions`** — `user_id`, `plan_id`, `starts_at`, `ends_at`, `status`, `price_paid`, `auto_renew`

---

## 6. Attribute-schema engine — app-driven forms

**This is P0**, because the vendor's only interface is the mobile app. The app must be able to
render an Add-Product form for a category it has never seen, without a Play Store release.

`product_categories.attribute_schema`:
```json
{
  "occupancy":  {"type":"int",  "label":"Max guests", "mr_label":"जास्तीत जास्त पाहुणे",
                 "required":true, "min":1, "max":20},
  "ac":         {"type":"bool", "label":"AC"},
  "meal_plan":  {"type":"enum", "label":"Meal plan", "options":["EP","CP","MAP","AP"]},
  "check_in":   {"type":"time", "label":"Check-in"}
}
```

Supported types: `string`, `text`, `int`, `decimal`, `bool`, `enum`, `multi`, `date`, `time`.

| Category | Schema |
|---|---|
| Hotel Rooms | occupancy, ac, meal_plan, check_in |
| Restaurant | cuisine[multi], veg_type[enum], serves_alcohol[bool] |
| Alphonso | grade[enum A,B], dozen_count[int], harvest_month[enum] |
| Water Sport | duration_min[int], min_age[int], safety_gear[bool] |

`app/Services/ProductAttributeValidator.php` compiles the schema into Laravel rules at write
time, so the server enforces exactly what the app rendered. `mr_label` keeps the app's
i18next Marathi support working without a translation deploy.

**Constraint (R5):** the schema must never describe anything that varies by date.

---

## 7. API surface

All POST, matching existing V2 controller style, `sendResponse` / `sendError` from `BaseController`.

### Vendor — `/api/v2/*` · `['auth:api','premiddleware','vendor','throttle:writes']`
```
mySites                     setPrimarySite

allowedProductCategories    site_id → permitted categories
categoryAttributeSchema     product_category_id → form schema   ← drives the app form

addProduct                  updateProduct         deleteProduct
myProducts                  getProduct            toggleProductStatus
submitProductForReview

uploadProductMedia          deleteProductMedia    reorderProductMedia   (throttle:uploads)
addProductVariant           updateProductVariant  deleteProductVariant

mySubscription              myUsageStats          productAnalytics
```

### Public — `/api/v2/*` · `['auth:api','premiddleware']`
```
listProducts        filters: site_id, category, price range, lat/lng radius, featured
getProduct          productsBySite       featuredProducts
recordProductView   recordProductLead    (throttled, queued, fire-and-forget)
```

### Admin — `/admin/v2/*` · `['auth:api','premiddleware','admin','throttle:admin']`
```
pendingProducts   approveProduct   rejectProduct   featureProduct
listAllProducts   getProductAdmin

listProductCategories  addProductCategory  updateProductCategory  deleteProductCategory
setAllowedProductCategories

listPlans  addPlan  updatePlan   listSubscriptions  vendorUsageReport
```

### Authorization
`ProductPolicy` — `$product->site->user_id === auth()->id()` for update/delete.
On create, the target site must be **owned by the caller** *and* `status = true` (admin-approved).

---

## 8. Mobile app flow (`tourkokan-v2`)

Vendors add products **from the app**. Backend contract implications:

1. **Multipart uploads** — product media arrives as files from the device, not URLs.
   Reuse `uploadFile()` + `throttle:uploads`, same as `uploadSiteGallery`.
2. **Dynamic forms** — the app calls `categoryAttributeSchema` and renders fields from the
   response. No hardcoded per-category screens; a new vertical needs no app release.
3. **Draft-first** — `status = draft` lets a vendor save a partial listing on a patchy Kokan
   network and finish later. `submitProductForReview` flips draft → pending.
4. **i18n** — every vendor-facing label ships with `mr_label`; product content has
   `name`/`mr_name` and `description`/`mr_description`. No hardcoded English (repo rule).
5. **Redux Toolkit** — vendor catalog state via `createAsyncThunk`, services in
   `src/Services/`, per the mobile conventions in `CLAUDE.md`.

**Screen sequence**
```
Profile → "Become a Vendor" → requestRole          [exists]
        → (admin approves)
        → "My Business" → addSite                   [exists]
        → (admin approves)
        → "My Products" → pick site → pick category → dynamic form → media → submit
                                                     [TO BUILD]
```

---

## 9. Monetization

Free for 12 months, then metered. **Build the meter now** — launching free without metering
leaves no data to price on.

**Launch config:** every vendor auto-enrolled on `free`, `ends_at = onboarded_at + 12 months`,
generous limits. `CheckPlanLimit` middleware reads `plans.limits` and enforces from day one.
Going paid = insert new plan rows; **zero code change**.

**Bill on leads, not views.** Vendors don't feel an impression; they feel a phone call.

| Signal | Definition | Bill? |
|---|---|---|
| views | product detail opened | no — show as value proof |
| **leads** | call / WhatsApp / directions / enquiry tap | **yes** |
| listings | active approved products | yes — flat tier |

**Metering pipeline**
```
recordProductView / recordProductLead
        → queued job → product_view_events / product_leads   (raw, 90d TTL)
                     → atomic increment products.views_count / leads_count
nightly ProductStatsRollup  → product_daily_stats            (permanent)
nightly ProductStatsPrune   → delete raw older than 90d
```
Feed the same hook into `user_activity_logs` and the analytics dashboard comes free.

---

## 10. Implementation phases

| # | Scope | Unblocks |
|---|---|---|
| ~~**1**~~ ✅ | Demolition: drop `projects` + legacy product/accom/tour tables, models, broken controllers, legacy routes | clean slate |
| ~~**2**~~ ✅ | `sites.is_primary`, `User::sites()`, `setPrimarySite`, `mySubmissions` → `mySites` | multi-outlet vendors |
| ~~**3**~~ ✅ | `product_categories` rebuild + `attribute_schema` + `booking_type` + validator + admin CRUD + seeder | taxonomy |
| **4** ← next | `products` + variants + media + vendor CRUD + `ProductPolicy` + admin moderation | **vendor can list from app** |
| **5** | Public reads: listProducts / getProduct / productsBySite / geo + filters; wire Comment/Rating/Favourite morphs | tourist can browse |
| **6** | Metering: view/lead recording, rollup + prune jobs, vendor analytics | pricing data |
| **7** | `plans` + `vendor_subscriptions` + `CheckPlanLimit`, everyone on free/12mo | monetization ready |
| **8** | *(~12 mo)* Payment gateway, invoices, dunning | revenue |
| **9** | *(when needed)* `product_availability` + `bookings` — pure addition if §3 R1–R6 were followed | booking calendar |

**Phases 1–4 are the critical path** to a vendor listing a product from the app.
Phase 7 is cheap now and expensive to retrofit — do not skip it.

---

## 11. Deferred, with a documented path back

| Item | Decision | Path back |
|---|---|---|
| Booking calendar | Not now. Hooks shipped in Phase 3–4 per §3 | Phase 9 — additive only |
| Multi-user vendor accounts (owner + staff logins) | Not now, one user = one vendor | Add `vendor_users` pivot; change policy to `site->user_id IN (...)`. Cheap *because* ownership is a single column |
| Web vendor portal | Not now — app only | Same APIs; `tourkokan` Next.js consumes them unchanged |
| Payments/payouts | Not now | KYC fields already reserved in `sites.meta_data` |
