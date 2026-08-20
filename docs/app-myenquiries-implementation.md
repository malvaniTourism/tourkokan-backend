# My Enquiries — App Implementation Guide

**Feature:** Buyer's enquiry history (marketplace C5)
**Endpoint:** `POST /api/v2/myEnquiries`
**Status:** Live on backend `dev` — mirror of the vendor-side `myLeads`.

This is the buyer-facing counterpart to the vendor's leads screen. When a tourist taps
call / WhatsApp / directions or sends an enquiry on a product, a lead is recorded. This
endpoint gives that same tourist a **history of the listings they reached out about**.

---

## 1. The endpoint

```
POST /api/v2/myEnquiries
Authorization: Bearer <token>          // same user token as the rest of the app
```

### Request body

| Field | Type | Required | Notes |
|---|---|---|---|
| `lead_type` | string | no | Filter to one channel: `call` · `whatsapp` · `directions` · `enquiry`. Omit for all. |
| `page` | number | no | Standard pagination. |

All reads in this API are `POST`. There is no path/query param — everything goes in the body.

---

## 2. Response shape

Standard envelope. **Read `success`, never the HTTP status** (this API answers `200` on
failure too). Rows are paginated under `data.data`.

```jsonc
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Enquiries fetched.",
  "data": {
    "current_page": 1,
    "last_page": 2,
    "per_page": 15,
    "total": 18,
    "data": [
      {
        "id": 91,
        "product_id": 6,
        "user_id": 9,
        "lead_type": "whatsapp",
        "message": "Is it available this weekend?",   // null if none was sent
        "created_at": "2026-08-20T09:14:02.000000Z",
        "available": true,                             // ← see §3
        "product": {
          "id": 6,
          "site_id": 12,
          "name": "Sea View Room",
          "slug": "sea-view-room",
          "base_price": "2400.00",
          "sale_price": null,
          "currency": "INR",
          "unit": "per_night",
          "status": "approved",
          "site": {
            "id": 12,
            "name": "Tarkarli Resort",
            "logo": "local/sites/logo.png",
            "phone": "9812345678",
            "whatsapp": "9812345678"
          },
          "default_variant": {
            "id": 20, "product_id": 6,
            "price": "2400.00", "sale_price": null, "stock": null
          },
          "cover": {
            "id": 4, "title": "Sea View Room",
            "path": "local/products/6a76e08f19d25.png",
            "path_url": "https://…s3…amazonaws.com/local/products/6a76e08f19d25.png",
            "is_cover": 1
          }
        }
      }
    ]
  }
}
```

- **Newest first**, ordered by enquiry id (stable even for two enquiries in the same second).
- `product` is the **same product card** you already render in `listProducts` — reuse the
  existing card component and price logic.
- Vendor-side fields (`is_read`, `read_at`, `platform`, `ip_hash`) are **not** in this
  response by design — this is the buyer's view.

---

## 3. `available` — handle the greyed-out case

A buyer may have contacted a listing that has since been **paused, rejected, or deleted**
along with its business. That row is **kept in the history**, not dropped, and flagged:

- `available: true`  → product is live and approved → render a normal, tappable card.
- `available: false` → product is gone/paused → render the card **greyed out**, disable the
  call/WhatsApp actions, and show "No longer available".

This is the exact same pattern as the favourites list (`favourites` returns `unavailable`
for dead listings). Reuse that handling.

> When `available` is `false`, `product` may still be present (paused listing) or `null`
> (hard-deleted). Guard for `product == null` and fall back to a plain "This listing is no
> longer available" row — don't crash on missing product fields.

---

## 4. Price resolution (unchanged — same rule as everywhere)

Resolve the display price through the variant, not the base price:

```
price = product.default_variant.sale_price
      ?? product.default_variant.price
      ?? product.sale_price
      ?? product.base_price
```

Prefix relative image paths (`cover.path`, `site.logo`) with `AWS_URL`. `cover.path_url`
is already absolute if you'd rather skip the prefix.

---

## 5. Suggested app wiring

1. **Service** — add to `MarketplaceServices.js`:
   ```js
   export const myEnquiries = (params = {}) =>
     api.post('/api/v2/myEnquiries', params);   // { lead_type?, page? }
   ```
2. **Screen** — a paginated list, reachable from the buyer's profile/account area
   ("My Enquiries"). Each row = product card + a small channel badge (`lead_type`) +
   relative timestamp (`created_at`).
3. **Empty state** — `total: 0` → "You haven't contacted any listings yet."
4. **Tap** — an `available` row opens `productDetail`; a non-available row is inert.
5. **Filter (optional)** — a channel chip row (All / Call / WhatsApp / Directions / Enquiry)
   that re-requests with `lead_type`.

No new permissions or role — any logged-in user can call it.

---

## 6. Two related decisions (no app work)

While you're here, two earlier open questions were closed as **no-build** — the app already
handles both correctly, so nothing changes on your side:

- **`custom_specs`** (vendor free-form product properties): **not adding.** Vendors use the
  category's schema attributes; anything extra goes in the product `description`. Keep the
  schema-driven Add-Product form as-is.
- **Self-serve subscription upgrade**: **not adding.** Plans stay admin-assigned; keep the
  "Contact us to switch" copy. Revisit when the commerce/payment layer ships.
