# Marketplace — Backend Asks & Open Questions

**From:** App (tourkokan-v2) · marketplace screens
**To:** Backend (tourkokan-backend)
**Status:** API is ~90% ready. **1 decision**, **3 confirmations**, **2 scope checks**.
**Basis:** Verified live against `localhost:8001` and the backend source (Aug 2026). Endpoints, envelope, `products` schema, and the attribute validator were all read directly.

---

## TL;DR checklist

- [ ] **#1 Decide `custom_specs`** — do we support vendor-defined custom properties? If yes, add a free-form JSON field. If no, we ship schema-only (zero backend work).
- [ ] **#2 Confirm public contact + geo** are returned by `productDetail` / `vendorProfile` (phone, WhatsApp, lat/lng). **Also: `productDetail` returned HTTP 000 in testing — please verify it responds.**
- [ ] **#3 Confirm image path convention** — absolute URLs vs relative (needs `AWS_URL` prefix).
- [ ] **#4 Confirm `addSite` category filtering** — how the app should show only vendor-registrable categories.
- [ ] **#5 Confirm attribute-level filtering is out of scope for v1.**
- [ ] **#6 Confirm booking is enquiry-only for v1 (no availability endpoint).**

---

## ✅ Already confirmed ready (no action needed)

- All marketplace endpoints exist under `api/v2/*` and require JWT; envelope is `{ version, language, success, message, data: { current_page, data: [...] } }`.
- `products` table (`2026_08_05_000004_create_products_tables.php`) has: `attributes` (JSON, schema-validated), `base_price`, `sale_price`, `currency`, `unit`, `is_bookable`, `status`, `rejection_reason`, `is_featured`, `available_from/to`, `views_count`, `leads_count`. Variants table + gallery with `is_cover`/`sort_order` are present.
- **Add-product form is fully supported as schema-driven:** `addProduct` → `ProductAttributeValidator` validates against the category's `attribute_schema`, enforces required fields, and returns errors keyed `attributes.<field>`.
- `Site` has `latitude`/`longitude`; a `Contact` model holds `phone`.
- 20 product categories + full site-category tree are seeded; demo marketplace data exists.

---

## ⚠️ #1 — DECISION: Custom vendor properties (`custom_specs`)

**The question:** should a vendor be able to add their *own* properties beyond the category schema (e.g. a room's "Floor" / "Balcony", a phone's "IMEI")?

**Current behavior (blocking):** `app/Services/ProductAttributeValidator.php` **hard-rejects** any attribute key not in the schema:

```php
$unknown = array_diff(array_keys($attributes), array_keys($schema));
if (!empty($unknown)) {
    return [[], ['attributes' => ['Unknown attribute(s) for category "…": ' . implode(', ', $unknown)]]];
}
```

So the app's optional **"Add custom detail"** section cannot send anything today — it would 422.

**Options:**

| | Change | Result |
|---|---|---|
| **A (recommended)** | Add a **`custom_specs` JSON** column on `products`; accept it in `addProduct`/`updateProduct` (free-form, **not** schema-validated); include it in `productDetail` + `listProducts`/`myProducts`. | Schema attributes stay clean & filterable; vendors still get freedom for extras. |
| **B** | Relax the validator to store unknown keys inside `attributes`. | Simpler, but pollutes the structured/filterable field — not recommended. |
| **C** | Do nothing. | App ships **schema-only** (drop the custom section). **Zero backend work.** |

**What we need:** pick A, B, or C. If **C**, the add-product form is already 100% supported and this whole item closes.

---

## ❓ #2 — CONFIRM: Public contact + geo in responses

The detail screen's **Call / WhatsApp / Directions** actions (and every `recordProductLead`) need public contact data. Owner phone/email are correctly private, so this must come from the **business/outlet**.

**Please confirm `productDetail` (and `vendorProfile`) return, on the `site`:**
- `phone` (public, shown to buyers)
- `whatsapp` number — **is it separate from `phone`, or the same number?**
- `latitude` + `longitude` (for the Directions deep link)

**⚠️ Bug to check:** in testing, `POST /api/v2/productDetail {"id":227}` returned **HTTP 000 / empty** while `listProducts` and `listcategories` worked with the same token. Please verify `productDetail` actually responds (possible hang/error).

---

## ❓ #3 — CONFIRM: Image path convention

Do these come back as **absolute URLs** or **relative paths** (needing the app to prepend `AWS_URL` / `FTP_PATH`, as the current app does elsewhere)?
- `product.cover.path`, `product.gallery[].path`
- `site.logo`

A single consistent convention (ideally absolute URLs) lets us use one image loader everywhere.

---

## ❓ #4 — CONFIRM: `addSite` category picker

`listcategories` returns the **whole** tree — including directory-only branches (Destination, Transportation, Emergency, Government, Education) that a vendor cannot register a business under.

**What we need:** how should the app show only **vendor-registrable** categories in the "Register a business" picker?
- A flag on the category (e.g. `is_vendor_registrable` / `is_business`), **or**
- The app filters to categories that have allowed product categories, **or**
- A dedicated endpoint/param.

Please advise the intended approach.

---

## 🔭 #5 — SCOPE: Attribute-level filtering (v1?)

`listProducts` supports `category_code`, `site_id`, `min/max_price`, `search`, `is_featured`, `latitude`+`longitude`, `radius_km`, `sort`. It does **not** filter by specific attributes (e.g. "AC only", "Grade A", "256GB").

**Confirm:** attribute filters are **out of scope for v1** (the Filters screen will offer category / price / rating / distance / sort only). If in scope, we'll need `listProducts` to accept attribute filters.

---

## 🔭 #6 — SCOPE: Booking (v1?)

`booking_type` (`date_range` / `slot` / `quantity` / `none`) is present but documented as informational — everything is **enquiry-only** for now.

**Confirm:** the date / time-slot / quantity pickers on detail screens are **informational only** (they feed the enquiry message via `recordProductLead(message)`), and there is **no availability/booking endpoint** expected in v1.

---

## Reference — endpoints the screens call

| Screen | Endpoint(s) |
|---|---|
| Browse / Filters | `listProducts`, `featuredProducts` |
| Product detail | `productDetail`, `recordProductView`, `recordProductLead` |
| Favourite / rate / comment | `addDeleteFavourite`, `addUpdateRating`, `comment(s)` (type `"Product"`) |
| Vendors | `listVendors`, `vendorProfile` |
| Become a vendor | `requestRole`, `myRoleRequests`, `addSite`, `mySites`, `setPrimarySite` |
| Add product | `allowedProductCategories`, `categoryAttributeSchema`, `addProduct`, `uploadProductMedia`, `saveProductVariant`, `submitProductForReview` |
| Manage catalog | `myProducts`, `getProduct`, `updateProduct`, `deleteProduct`, `toggleProductStatus`, `deleteProductMedia`, `setProductCover`, `reorderProductMedia`, `deleteProductVariant` |
| Dashboard / Leads / Analytics | `myUsageStats`, `myLeads`, `productAnalytics` |
| Plan | `mySubscription`, `listPlans` |

_Full contract: `docs/app-api-integration.md` · walkthrough: `php artisan vendor:walkthrough`._
