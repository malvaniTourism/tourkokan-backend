# Vendor Product Listing — Design & Implementation Plan

**Status:** Phases 1–5 implemented and tested — **vendors list, tourists browse** · Phase 6 (metering rollup) next
**Date:** 2026-08-05
**Branch:** `feature/vendor-products`
**Tests:** 211 passing — run with `./vendor/bin/phpunit` (requires the `tktesting_test` schema, see §0.5)
**Client:** Tourkokan mobile app (`tourkokan-v2`, React Native) — vendors add products from the app
**Backend:** `tourkokan-backend` (Laravel 12)

---

## Table of contents

0. [Defects found and fixed](#0-defects-found-and-fixed)
1. [What already exists](#1-what-already-exists--do-not-rebuild)
2. [Core model decisions](#2-core-model-decisions)
3. [Booking-ready design](#3-booking-ready-design--read-before-writing-any-pricing-code)
3b. [Commerce-ready design](#3b-commerce-ready-design--read-before-touching-pricing-or-orders)
4. [Phase 1 — Remove `projects`](#4-phase-1--remove-projects-entirely)
5. [Schema](#5-schema)
6. [Attribute-schema engine](#6-attribute-schema-engine--app-driven-forms)
7. [API surface](#7-api-surface)
8. [Mobile app flow](#8-mobile-app-flow-tourkokan-v2)
9. [Monetization](#9-monetization)
10. [Phases](#10-implementation-phases)

---

## 0. Defects found and fixed

Bugs uncovered while implementing Phases 1–3. All are fixed and covered by tests; the
regressions listed here are the ones worth guarding, not the routine ones.

### 0.1 🔴 The entire `/admin/v2/*` API was unauthenticated for role — **fixed**

`routes/admin.php` declared the group as `['auth:api', 'premiddleware', 'throttle:admin']`.
The `admin` alias was missing, so **every** admin endpoint was reachable by any
authenticated user. Verified against a user holding only the `vendor` role:

```
listBanners              HTTP 200   (expected 403)
listAppVersions          HTTP 200
pendingSites             HTTP 200
listEvents               HTTP 200
analytics/dashboardStats HTTP 200
```

Real-world impact: an ordinary app user could approve their own site submission
(`approveSite`), grant themselves any role (`approveRoleRequest`), delete other users'
events (`deleteEvent`), read the full user list (`allUsers`), or message all users
(`sendMessage`).

Two sibling groups were already correct — `/admin/api/*` and the route-management group —
so this was an oversight when the V2 group was introduced, not a deliberate choice.

**Fix:** added `'admin'` to the group's middleware. Blast radius checked first — only
`admin-panel` calls `/admin/v2` (`VITE_ADMIN_API_BASE=/admin/v2`); the mobile app and web
frontend never do. The seeded admin holds `superadmin`, which `AdminAccessMiddleware`
accepts.

**Regression cover:** `tests/Feature/AdminAccessTest.php` — 15 representative endpoints ×
{vendor, plain user, anonymous}, plus positive cases for admin/superadmin, role revocation,
and a check that the vendor API is unaffected. 50 tests.

### 0.2 `product_categories.icon` and `.meta_data` were NOT NULL — **fixed**

Both columns were `NOT NULL` with no default while `ProductCategoryController` validated
them as optional, so every insert that omitted them failed at the database. Fixed in the
Phase 3 rebuild; covered by
`LegacySchemaRemovedTest::test_product_categories_no_longer_require_icon_and_meta_data`.

### 0.3 Multipart booleans and multi-selects were unvalidatable — **fixed**

Vendors add products from the React Native app over `multipart/form-data`, where every
value arrives as a **string**. Laravel's `boolean` rule accepts `1/0/"1"/"0"` but rejects
`"true"`/`"false"`, and `array` rejects a JSON string — so every `bool` and `multi`
attribute in every vertical would have failed validation in the app while passing any
JSON-based test.

`ProductAttributeValidator::normalize()` now coerces these before validation. Covered by
data-driven tests over all input shapes the app can send.

### 0.4 `assertOk()` is not evidence of success in this API

`BaseController::sendResponse` returns `{"success": false, "message": "Unauthorised
Access"}` with **HTTP 200** when `PreMiddleware` cannot resolve an app version, and
validation failures elsewhere also return 200. A test asserting only the status code
passes while receiving no data.

Not changed — altering the status codes would break existing clients — but
`tests/ApiTestCase` provides `assertApiSuccess()` / `assertApiFailure()`, which check the
envelope's `success` flag. **Use those, never bare `assertOk()`.**

> Worth revisiting separately: an auth failure answering 200 defeats client-side error
> handling and any HTTP-level monitoring.

### 0.5 The test suite could wipe the development database — **fixed**

`RefreshDatabase` runs `migrate:fresh`. With no `DB_DATABASE` override in `phpunit.xml`
(the state this repo shipped in) it targets whatever `.env` points at — the dev database.

`tests/CreatesApplication.php` now refuses to boot against any database whose name does not
end in `_test`. The check sits in `createApplication()` rather than `TestCase::setUp()`
because Laravel boots the `RefreshDatabase` trait *inside* its own `setUp()`, where a later
check would fire only after the data was gone.

### 0.6 `galleries.title` is NOT NULL — worked around

Product image upload passes no caption (the app does not prompt for one), which crashed on
insert. The column is shared with Site and Event galleries, which always send a title, so
rather than alter a shared table the product uploader falls back to the product name — also
sensible alt text. Covered by `VendorProductTest::test_the_first_uploaded_image_becomes_the_cover`.

### 0.7 `attributes` collides with Eloquent's internal property

`Model::$attributes` is Eloquent's own storage. `$product->attributes` works from outside
the model (PHP routes it through `__get`), but **inside** model or trait code it returns the
raw internal array rather than the cast value. Read it with `getAttribute('attributes')` in
any code that lives on the model. Covered by
`VendorProductTest::test_attributes_are_cast_despite_colliding_with_eloquents_internal_property`.

### 0.8 Policy abilities resolve by model class

`$user->can('createOn', $site)` resolves `SitePolicy`, which does not exist — so the check
silently misbehaves instead of consulting `ProductPolicy`. The call must name the policy's
model: `can('createOn', [Product::class, $site])`.

### 0.9 `Category::$fillable` omitted `mr_name`

`categories.mr_name` is NOT NULL with no default, but `mr_name` was absent from the model's
`$fillable`, so mass assignment silently dropped it. This only looked harmless because the
dev MySQL runs without `STRICT_TRANS_TABLES` (`sql_mode` is just `NO_ENGINE_SUBSTITUTION`)
and coerced it to `''`. On a strict-mode production server every `Category::create()` would
have failed. Added to `$fillable`.

### 0.10 `Product::live()` ignored the site

The scope checked only the product's own status, so a listing stayed publicly visible after
its site was unpublished — and, once vendors could list against pending sites, would have
gone live before the business was ever approved. Now requires the site be `approved` and
`status = true`. Covered by `PendingSiteProductTest`.

### 0.11 `gte:min_price` rejected the commonest filter in the catalogue

`listProducts` validated `max_price` with an unconditional `gte:min_price`. Laravel evaluates
that rule even when `min_price` is absent, so **filtering by a maximum price alone — the
most common filter any catalogue has — returned 422**.

It hid well: this API answers 200 on failure (§0.4), so the app would have shown "no results"
rather than an error, and the first test missed it by not asserting the envelope. Now wrapped
in `Rule::when($request->filled('min_price'), ...)`, with tests for both the max-only filter
and the genuinely invalid max-below-min case.

Lesson applied across the catalogue tests: helper methods assert success by default.

### 0.12 `Product::scopeLive` used unqualified column names

`where('status', 'approved')` broke the moment a query joined `sites`, which also has
`status` — MySQL rejected it as ambiguous. Harmless until the public catalogue joined `sites`
for distance sorting, then fatal. All columns in the scope are now table-qualified.

### 0.13 Legacy `Projects` naming

`PlaceController` answered "Projects updated successfully" when updating a Place, and
`Blog`/`Photos` carried docblocks describing Projects. Cleaned up. The only remaining
mentions are inside the commented-out V1 route blocks, which go when `app/Http/Controllers/API/V1/`
is deleted.

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

### 2.6 A vendor lists products while the business is still under review

Requiring site approval before any product could be added meant a vendor onboarded, went
idle waiting, then had to come back and start again — two round trips before they saw any
value, at the point where they are least invested.

Instead **both queues fill in parallel**. A vendor submits their business and immediately
starts adding listings; site and products all sit `pending`, and an admin reviews the
business once and its catalog alongside it.

Nothing becomes publicly visible early — three independent gates:

| Gate | Where |
|---|---|
| Product must be `approved` | admin per listing |
| `approveProduct` refuses while the site is not live | `Admin\V2\ProductController::approveProduct` |
| `Product::live()` requires the site be approved **and** `status = true` | `Product::scopeLive` |

That third gate also covers a case the first two do not: an admin unpublishing a site later
must take its listings down with it.

A **rejected** site is excluded — `createOn` allows `pending` and `approved` only. Fix the
business listing before hanging more off it. `mySites` and `allowedProductCategories`
follow the same rule, and `mySites` returns `submission_status` so the app can badge
listings that are still under review.

### 2.7 Who can be a vendor

The original taxonomy describes tourist *places* — it covered hotels and restaurants, but a
tour operator, a carpenter or a village shop had nowhere to register, and `Transportation`
holds infrastructure (Airport, Railway Station, MSRTC) rather than services for hire.

`VendorCategorySeeder` adds three business-facing branches:

| Branch | Children | Can list |
|---|---|---|
| **Tour & Travel** `tour_travel` | Tour Operator, Travel Agency, Taxi Service, Boat Operator, Vehicle Rental, Tour Guide | Tour Package, Guide Service, Vehicle Rental, Taxi/Transfer, Boat Ride |
| **Local Services** `local_service` | Carpenter, Electrician, Plumber, Mason, Painter, Vehicle Mechanic, Tailor, Salon & Barber, Photographer, Catering, Event Decorator, Appliance Repair | Service Visit, Repair Service, Catering Package |
| **Shopping** `shopping` | Grocery, Bakery, Sweet Shop, Medical Store, Hardware, Clothing, Handicraft Shop, Fish Market, Farm Produce | Shop Item, Farm Produce, Handicraft, Alphonso, Kokum, Cashew |

Plus the existing **Accommodation** → Room Night / Stay Package and **Food** → Menu Item /
Thali.

Children may narrow their parent's set (`allowedByChildCategory`) — without it a carpenter
is offered "Catering Package" and a taxi service is offered "Boat Ride". Harmless to the
data, but it makes the app's category picker noisy for the vendor.

Categories with no whitelist entry — Hospital, Government offices, Beach — can list
nothing, which is the intended default.

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

## 3b. Commerce-ready design — READ BEFORE TOUCHING PRICING OR ORDERS

> **Selling through the platform is NOT built** — no cart, orders, payments, delivery or
> payouts. This section exists so that building it later is an *addition*, never a rewrite.
> Same contract as §3 does for booking.

**Target shape once commerce lands:**

```
product_variants          ← the unit of sale (already exists)
     └── cart_items       ← FUTURE
     └── order_items      ← FUTURE: snapshots price + tax at purchase
          └── orders      ← FUTURE
               ├── payments   ← FUTURE
               ├── shipments  ← FUTURE
               └── payouts    ← FUTURE: settles to product → site → user_id
```

### What is already safe

Cart, orders, payments, refunds, coupons, delivery, commission and stock reservation are all
**pure additions** — new tables keyed on `product_variants.id`. None of them forces a change
to anything that exists today.

### The five rules

**C1 — `product_variants` is the unit of sale. Order lines reference a variant, never a product.**
Every product has at least one variant, auto-created (R1). Had price lived only on
`products`, every historical order line would turn ambiguous the day that product gained
variants. This is the one that would have been a genuine rewrite, and it is already right.

**C2 — Order lines snapshot price and tax at purchase time. Never recompute from the live catalog.**
A vendor editing their price must not silently rewrite what a customer already paid. When
`order_items` is built it carries its own `unit_price`, `tax_rate` and `tax_amount` copies.

**C3 — Tax is recorded on the product from day one.**
`hsn_code`, `tax_rate`, `price_includes_tax` ship now, before any order exists, because C2
means order lines snapshot the rate and **history cannot be back-filled with a rate that was
never recorded**. `tax_rate` is constrained to the GST slabs (0/5/12/18/28) — a free-text
rate becomes a tax liability the moment orders start copying it. Vendors fill these in while
listing, so the data is already there when commerce switches on.

**C4 — `fulfilment_type` ships now, defaulting to `enquiry`, and is not vendor-writable.**
```
enquiry   customer calls / WhatsApps the vendor   (everything, today)
order     bought through the platform             ← needs the commerce layer
booking   reserved for a date or slot             ← needs the availability calendar
```
Introducing this after launch would mean backfilling every listing with a guess about what
the vendor meant. Turning commerce on becomes a value change, not a migration. Absent from
the vendor payload alongside `status`, `is_featured` and `is_bookable` — enabling commerce
is a platform decision, not a field a vendor can post.

**C5 — The seller stays derivable in one hop: `product → site → user_id`.**
Commission and settlement attach there. Never add a second seller column to the catalog —
two ownership columns that can disagree turn split payouts into a correctness problem.
Orders *will* snapshot the seller for the payout record; that is a different concern from
catalog ownership.

### Still to build, and genuinely additive

Cart · orders · payments · refunds · invoices · coupons · shipping addresses · delivery and
serviceability (radius / pincode list) · vendor payout and bank details (currently parked in
`sites.meta_data`) · stock reservation. `product_variants.stock` is an availability figure,
not a ledger — reservation belongs in the commerce layer.

Regression cover: `tests/Feature/CommerceReadinessTest.php`.

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
| ~~**4**~~ ✅ | `products` + variants + media + vendor CRUD + `ProductPolicy` + admin moderation | **vendor can list from app** |
| ~~**5**~~ ✅ | Public reads: listProducts / productDetail / productsBySite / featuredProducts, geo + price + category filters, view & lead capture | tourist can browse |
| **6** ← next | Nightly rollup into product_daily_stats, 90-day prune, vendor analytics dashboard | pricing data |
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
