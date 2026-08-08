# Admin Panel — Vendor Products Integration

**Scope:** only what changed. The admin panel is already integrated with the existing admin
API; this covers the **18 new endpoints** for the vendor product feature, the **2 existing
endpoints whose behaviour changed**, one **breaking access change**, and **23 removed legacy
routes**.

Nothing else in `/admin/v2` is affected. Existing screens need no changes beyond §1.

**Base URL** — `{VITE_ADMIN_API_BASE}` → `/admin/v2` · **Method** — all `POST` ·
**Auth** — `Authorization: Bearer <token>`

---

## 1. ⚠️ Breaking: `/admin/v2/*` now requires the admin role

The group was declared without the `admin` middleware, so **every admin endpoint was
reachable by any authenticated user** — including `approveSite`, `approveRoleRequest` and
`deleteEvent`. Fixed on 2026-08-05.

```php
- ['auth:api', 'premiddleware', 'throttle:admin']
+ ['auth:api', 'premiddleware', 'admin', 'throttle:admin']
```

**What this means for the panel:** a valid token now also needs role code `admin` or
`superadmin` in `user_roles`. Any account that could previously use the panel *without* that
role will now get **403 on every endpoint**.

Worth checking before you deploy:

```sql
SELECT u.id, u.email, GROUP_CONCAT(r.code) AS roles
FROM users u
LEFT JOIN user_roles ur ON ur.user_id = u.id
LEFT JOIN roles r ON r.id = ur.role_id
GROUP BY u.id
HAVING roles IS NULL OR (roles NOT LIKE '%admin%');
```

Anyone in that result who is supposed to have panel access needs an `admin` role row. Handle
403 globally with a "your account no longer has admin access" message rather than a generic
error — otherwise this looks like a login bug.

---

## 2. Removed: 23 legacy `/admin/api/*` routes

The 2022 product/tour/accommodation endpoints were deleted along with their tables. They had
non-functional controllers (empty stubs, a `Roles::find()` inside an accommodation update),
so nothing could have been depending on their behaviour.

```
/admin/api/productcategories        /admin/api/productcategory[/{id}]
/admin/api/allowproductcategory[/{id}]
/admin/api/products                 /admin/api/product[/{id}]
/admin/api/tourpackages             /admin/api/tourpackage[/{id}]
/admin/api/accomodationcategories   /admin/api/accomodationcategory[/{id}]
```

I grepped `admin-panel/src` for all of these and for `admin/api` generally — **no references
found**, so this should be a no-op for you. Flagged only so a 404 is not a surprise.

Replacements are in §4 and §5 (`/admin/v2`, different shape).

---

## 3. Changed behaviour on 2 existing endpoints

Payloads and responses are unchanged; both do more than before.

### `approveSite`
A vendor's **first approved site is now automatically marked primary** (`is_primary = true`).
The response is the refreshed site, so the flag is present in what you receive. If the panel
shows a site list, `is_primary` is worth a badge.

### `approveRoleRequest`
Approving a **`vendor`** role request now also enrols the user on the **free plan**
(12 months). Idempotent, and invisible in the response — but it means a newly approved vendor
already has a subscription when you open `vendorUsageReport` for them.

---

## 4. New — Product moderation (build this first)

The daily-driver screen. A vendor's listing goes `draft → pending → approved`, and this is
where pending becomes approved.

| Endpoint | Payload |
|---|---|
| `pendingProducts` | `page?`, `per_page?` — the review queue, **oldest first** |
| `listAllProducts` | `status?`, `site_id?`, `product_category_id?`, `search?`, `page?` |
| `getProductAdmin` | `id` — full detail incl. variants, gallery, attribute schema |
| `approveProduct` | `id` |
| `rejectProduct` | `id`, `rejection_reason` (required, ≤1000) |
| `featureProduct` | `id`, `is_featured` (boolean) |

`status` values: `draft` · `pending` · `approved` · `rejected` · `paused`

**Two refusals to handle explicitly, because the reason is not obvious:**

- `approveProduct` returns `success: false` if the product's **site** is not itself approved
  and published. A vendor can build a catalogue while their business is still under review,
  so the queue legitimately contains products whose site is not live yet. Surface the message
  and link to the site review screen.
- `featureProduct` only accepts **approved** products.

`rejection_reason` is shown to the vendor in the app — treat it as user-facing copy, not an
internal note.

A product row carries `attributes` (a JSON object of category-specific fields) and
`product_category.attribute_schema` describing them. Rendering `label: value` pairs from the
schema gives a reviewer the full picture without a per-category screen.

---

## 5. New — Product taxonomy

Rarely touched, high leverage. **This is the screen that lets the business open a new vendor
category with no code and no app release.**

| Endpoint | Payload |
|---|---|
| `listProductCategories` | `search?`, `booking_type?`, `status?`, `page?` |
| `getProductCategory` | `id` |
| `addProductCategory` | see below |
| `updateProductCategory` | `id` + any add field |
| `deleteProductCategory` | `id` — refused if it has children |
| `setAllowedProductCategories` | `category_id`, `allowed[]` |

### `addProductCategory`

| Field | Rules |
|---|---|
| `name` | required, 2–100 |
| `code` | required, ≤60, **unique**, `snake_case` (`^[a-z][a-z0-9_]*$`) |
| `mr_name` | nullable, 2–100 |
| `parent_id` | nullable, exists |
| `description` | nullable, ≤1000 |
| `icon` | nullable image, jpeg/jpg/png/webp, ≤2 MB → **multipart** |
| `attribute_schema` | nullable object — see below |
| `booking_type` | `none` · `date_range` · `slot` · `quantity` (default `none`) |
| `status` | nullable boolean |
| `sort_order` | nullable integer |

Sending an `icon` makes the request `multipart/form-data`; `attribute_schema` must then be a
**JSON string**.

### The attribute schema — worth a proper builder UI

The mobile app renders its entire Add-Product form from this object. Adding a category here
with a good schema ships a new vertical.

```json
{
  "occupancy": { "type": "int",  "label": "Max guests", "mr_label": "पाहुणे",
                 "required": true, "min": 1, "max": 20 },
  "ac":        { "type": "bool", "label": "Air conditioned" },
  "meal_plan": { "type": "enum", "label": "Meal plan", "options": ["EP","CP","MAP","AP"] }
}
```

- **Types** — `string` `text` `int` `decimal` `bool` `enum` `multi` `date` `time`
- `enum` and `multi` **must** declare `options`
- Every field needs a `label` (the app renders it); `mr_label` is optional Marathi
- Optional per type: `required`, `min`, `max`
- Keys must be `snake_case`
- **Reserved keys are refused**: `price`, `sale_price`, `base_price`, `stock`, `currency`,
  `availability`, `slots`… — anything that varies by date belongs to pricing, not attributes

A form builder (add field → pick type → set label/options) beats a raw JSON textarea here,
because this screen is the whole point of the design: no engineer needed to open "Boat
Repair" or "Homestay Meals" as a category. Validation errors come back as
`{"attribute_schema": ["Attribute 'x' has unsupported type 'y'. Allowed: …"]}`.

`booking_type` is dormant — nothing reads it yet. It is set correctly now so the availability
calendar is a pure addition later. Show it as a field; do not build booking UI on it.

### `setAllowedProductCategories` — the guardrail

Defines which product categories a given **site** category may list. This is what stops a
site categorised "Hospital" from listing mangoes.

```json
{
  "category_id": 12,
  "allowed": [
    { "product_category_id": 3, "max_products": 50 },
    { "product_category_id": 7 }
  ]
}
```

**It replaces the entire set** for that site category — send the full list, never a delta.
An empty `allowed: []` revokes everything. Duplicate `product_category_id` values are
rejected. `max_products` is an optional per-site quota; omit for unlimited.

A two-pane picker (site categories on the left, product categories as checkboxes on the
right) matches the semantics better than a form.

---

## 6. New — Plans & subscriptions

Not urgent. Listing is free for the launch year, so this is admin tooling for later.

| Endpoint | Payload |
|---|---|
| `listPlans` | — (includes inactive tiers + active subscriber counts) |
| `addPlan` | `code`, `name`, `mr_name?`, `description?`, `price?`, `currency?`, `billing_period?`, `limits?`, `is_active?`, `sort_order?` |
| `updatePlan` | `id` + any add field |
| `listSubscriptions` | `plan_id?`, `status?`, `expiring_soon?` (bool, next 30 days) |
| `assignPlan` | `user_id`, `plan_id`, `months?` (1–120) |
| `vendorUsageReport` | `user_id` |

`limits` is `{key: int|null}`, `null` = unlimited:

```json
{ "max_sites": 5, "max_products": 100, "max_images_per_product": 10, "featured_slots": 0 }
```

Only those four keys are valid — **anything else is refused**, because a typo would silently
stop being enforced. Render "Unlimited" for `null`, not `0`.

Seeded: `free` (active), `starter` and `growth` (**inactive**, placeholder prices). Going paid
is a data change — activate a tier, then `assignPlan`, which closes the vendor's previous
subscription rather than leaving two live.

**`vendorUsageReport` is the useful one today** — it answers "why can this vendor not add
more?" with their plan, subscription dates, and usage against every quota. Worth putting on
a vendor detail/support screen.

---

## 7. Suggested build order

1. **Product moderation** (§4) — the only screen needed daily; nothing goes live without it
2. **Product taxonomy** (§5) — needed once, then rarely; unlocks new verticals
3. **Vendor detail / usage** — `vendorUsageReport` on the existing user screen
4. **Plans** (§6) — when charging starts

Existing role-request and site-review screens already cover the earlier steps of the vendor
flow; only their behaviour notes in §3 apply.

---

## 8. Reminders that still apply

Unchanged from the rest of the admin API, repeated because the new endpoints follow them:

- **HTTP 200 on failure** — read `success`, never the status code
- `message` is a **string** for business errors, a **field → errors object** for validation
- Paginated rows are at `data.data`; `per_page` is silently capped at **30**
- Analytics-style figures come from a nightly rollup — see below

If you add product analytics to the panel, note that vendor-facing stats read
`product_daily_stats`, populated by `php artisan products:rollup-stats` at 00:45. **Today's
activity is not included**, and everything reads zero until that job has run at least once.
Label such figures "updated daily".

---

## 9. Deployment prerequisites

The panel will show empty taxonomy screens until these run on the target server:

```bash
php artisan migrate --force
php artisan db:seed --class=PlanSeeder --force
php artisan db:seed --class=ProductCategorySeeder --force
php artisan db:seed --class=VendorCategorySeeder --force
```

`VendorCategorySeeder` adds the Tour & Travel, Local Services and Shopping **site**
categories, so the existing category screens will show new entries too.

---

Wire format for the app-facing side: `docs/vendor-products-api.md`.
Postman collection: `docs/tourkokan-vendor-products.postman_collection.json`.
Design rationale: `docs/VENDOR_PRODUCTS_DESIGN.md`.
