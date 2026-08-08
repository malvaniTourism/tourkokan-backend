# Tourkokan App API — Integration Basics

For `tourkokan-v2` (React Native) and the `tourkokan` web frontend — the **public,
tourist-facing** surface.

**Base URL** — `{API_PATH}/api/v2`
**Auth** — JWT. `Authorization: Bearer <token>` on every request below; the app requires
login before browsing.
**Method** — every endpoint is `POST`, including reads.

> Building the **vendor** screens instead? That is a separate, much larger contract:
> `docs/vendor-products-api.md`. This file is only what a tourist sees.

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

## 8. Checklist

- [ ] Read `success`, never the status code
- [ ] Rows are at `data.data`; `per_page` caps at 30
- [ ] Handle `message` as string **and** as field-errors object
- [ ] Price comes from `default_variant`, with `unit` as the suffix
- [ ] Send `latitude` and `longitude` together, or neither
- [ ] Fire `recordProductView` on detail open
- [ ] Fire `recordProductLead` on every contact tap, before leaving the app
- [ ] Use `mr_name` / `mr_description` when the user's language is Marathi
- [ ] Tell users their comment is awaiting moderation

---

Full wire format including the vendor side: `docs/vendor-products-api.md`.
Postman collection: `docs/tourkokan-vendor-products.postman_collection.json`.
