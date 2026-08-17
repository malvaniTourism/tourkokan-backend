# Marketplace — Backend Response

**Re:** `marketplace-backend-asks.md`
**From:** Backend (tourkokan-backend)
**Status:** 2 implemented · 3 confirmed no-change · 1 decision is yours
**Verified:** live against a running server + 292 passing tests (Aug 2026)

---

## TL;DR

| # | Ask | Outcome |
|---|---|---|
| **#1** | `custom_specs` custom vendor properties | **Your decision.** Not built — pick A/B/C below. If C, nothing to do |
| **#2** | Public contact + geo in responses | ✅ **Implemented** — `phone` + `whatsapp` added; lat/lng already there |
| **#2 bug** | `productDetail` "HTTP 000" | ✅ **Not a bug** — it returns 200. The 000 was no server running on that port |
| **#3** | Image path convention | ✅ **No change needed** — confirmed convention below (relative + `AWS_URL`) |
| **#4** | `addSite` category picker | ✅ **Implemented** — new `businessCategories` endpoint + `is_business` flag |
| **#5** | Attribute filtering out of scope v1 | ✅ **Confirmed** — not in `listProducts`, matches your assumption |
| **#6** | Booking enquiry-only v1 | ✅ **Confirmed** — informational only, no availability endpoint |

Two migrations, one new endpoint, seeder kept in sync. Run on your side:

```bash
php artisan migrate
```

---

## ✅ #2 — Public contact + geo — IMPLEMENTED

Sites now carry a **public** `phone` and `whatsapp` (separate from the owner's encrypted PII).

**New columns** (`2026_08_13_000001_...`): `sites.phone`, `sites.whatsapp` (nullable, digit/`+`/`-`/space only).

**Captured in** `addSite` and `updateMySubmission` — send `phone` / `whatsapp` alongside the
other fields. WhatsApp is a **separate field** from phone (a business often uses one number
for calls and another for WhatsApp); send the same value in both if they're identical.

**Returned on the `site` block of:** `productDetail`, `listProducts`, `productsBySite`,
`featuredProducts`, `vendorProfile`, `mySites`. Example from `productDetail`:

```json
"site": {
  "id": 41, "name": "Sagar Resort", "logo": "https://…",
  "latitude": "16.0512000", "longitude": "73.4680000", "pin_code": "416606",
  "phone": "+91 9876543210",
  "whatsapp": "9876543210",
  "social_media": null, "domain_name": null
}
```

- **Call** → `site.phone`
- **WhatsApp** → `site.whatsapp` (fall back to `site.phone` if null)
- **Directions** → `site.latitude` + `site.longitude` (already present, unchanged)

Owner name/email/mobile remain private — never returned on the public `site` block.

### The "HTTP 000" was not a bug
`POST /api/v2/productDetail` returns **HTTP 200** with the full product. The `000` you saw is
curl's code for *no connection* — the dev server wasn't running on that port. `php artisan
serve` picks the **next free port** if 8000 is taken (CometChat occupies 8000 on this
machine), so it was likely on 8001/8003. Check the port the serve command prints.

---

## ✅ #4 — Vendor-registrable categories — IMPLEMENTED

Two parts:

**1. A flag.** `categories.is_business` (boolean) marks the branches a vendor can register a
business under — set automatically for any category that can carry products (has a whitelist
entry), plus its parent. Directory-only branches (Destination, Transportation, Emergency,
Government, Education) are `false`.

**2. A dedicated endpoint** — cleaner than filtering the full tree client-side:

```
POST /api/v2/businessCategories        { }        (auth required)
```

Returns **only registrable** parents with their registrable children, flat, no pagination —
exactly the "Register a business" picker:

```json
"data": [
  { "id": 9, "name": "Accommodation", "code": "accomodation", "is_business": true,
    "sub_categories": [
      { "id": 11, "name": "Hotel Rooms", "code": "hotel_rooms", "is_business": true }, …
    ] },
  { "id": 82, "name": "Tour & Travel", "code": "tour_travel", … },
  { "id": 89, "name": "Local Services", … },
  { "id": 102, "name": "Shopping", … }, …
]
```

Use `businessCategories` for the register screen; keep `listcategories` for browse. The
`is_business` flag is also on every `listcategories` row if you prefer to filter there.

> **One thing to confirm on your side:** `Kokan View` (Beach, Temple, Fort…) is currently
> registrable, because a vendor can list *Activity Ticket / Guide Service* at those spots
> (a water-sports operator at a beach). If you don't want places in the business picker,
> tell us and we'll drop those from the whitelist — it's a one-line seeder change, no code.

---

## ✅ #3 — Image paths — NO CHANGE NEEDED (convention confirmed)

Checked all three frontends. **The whole platform already uses one convention**, and your
existing image helper already handles it:

- Image fields (`cover.path`, `gallery[].path`, event/site galleries) are **relative
  storage paths** — prepend `AWS_URL` / `FTP_PATH`.
- Legacy rows may hold an absolute URL, so guard with `startsWith('http')` — which
  `tourkokan-v2` already does:
  ```js
  uri = item.path?.startsWith('http') ? item.path : `${AWS_URL}${item.path}`
  ```
- `site.logo` comes back **absolute** (it runs through a URL accessor). The `startsWith`
  guard above handles that too — no special-casing needed.

We deliberately did **not** switch product paths to absolute: web (`ftpUrl(g.path)`) and app
(`${AWS_URL}${item.path}`) both expect relative, and changing it would double-prefix every
existing gallery across the platform. **Reuse your existing helper and everything works** —
`site.logo` (absolute, guarded) and `cover.path` (relative, prefixed) both resolve.

_(Note: every Gallery row also includes a ready-made absolute `path_url` if you ever want it,
but the relative + `AWS_URL` path is the platform standard.)_

---

## ⚠️ #1 — `custom_specs` — YOUR DECISION (not built)

Confirmed exactly as you described: `ProductAttributeValidator` **hard-rejects** any attribute
key outside the category schema, so the "Add custom detail" section would 422 today. This is a
product decision, not a bug — pick one:

| | Change | Backend work |
|---|---|---|
| **A** | Add a free-form `custom_specs` JSON column on `products`; accept in add/update; return in detail/list. Schema attributes stay clean & filterable | ~1 migration + a few controller lines |
| **B** | Relax the validator to store unknown keys inside `attributes` | Small, but pollutes the filterable field — not recommended |
| **C** | Ship schema-only (drop the custom section) | **Zero** — everything else is ready |

**Tell us A, B, or C.** If **C**, this item closes and the add-product flow is 100% done.
If **A** (our recommendation too), we'll add `custom_specs` — say the word.

---

## ✅ #5 & #6 — Scope confirmed

**#5 — Attribute filtering is out of scope for v1.** `listProducts` filters on
`category_code`, `site_id`, `min/max_price`, `search`, `is_featured`, `latitude`+`longitude`,
`radius_km`, `sort` — no per-attribute filters. Your Filters screen (category / price /
rating / distance / sort) matches what's there. If you want "AC only" / "Grade A" later,
it's an additive change to `listProducts`.

**#6 — Booking is enquiry-only for v1.** `booking_type` (`date_range`/`slot`/`quantity`/`none`)
is informational — there is **no** availability or booking endpoint. Date/slot/quantity
pickers on detail screens are informational and should feed the enquiry via
`recordProductLead(message)`. The schema is already shaped so a real availability calendar is
a pure addition later (see `VENDOR_PRODUCTS_DESIGN.md §3`), but nothing to integrate in v1.

---

## What changed in this response (for your changelog)

- `sites.phone`, `sites.whatsapp` — new, captured in `addSite`/`updateMySubmission`, returned on every public `site` block.
- `categories.is_business` — new flag, auto-derived from the product whitelist.
- `POST /api/v2/businessCategories` — new endpoint for the register-a-business picker.
- No breaking changes. Existing responses gain fields; nothing is removed or renamed.

Full app contract: `docs/app-api-integration.md` (§9–13 cover the vendor flow).
