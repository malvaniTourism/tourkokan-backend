# Commerce & Booking — Design & Implementation Plan

**Repo:** tourkokan-backend (Laravel 12)
**Date:** 2026-08-20
**Status:** Plan — nothing in this document is built yet.
**Prerequisite reading:** `docs/VENDOR_PRODUCTS_DESIGN.md` §3 (R1–R6) and §3b (C1–C5).

This plan turns the four monetisable revenue lines into backend work. It builds **on top of**
the forward-compatibility contract already shipped with the vendor marketplace — every rule
in §3/§3b was written so this phase is an *addition*, never a rewrite. Where this document
says "already safe", it is because that contract was honoured.

Ganpati-season travel (private-vehicle seat aggregation) is **explicitly out of scope** —
see §8 for why it is a separate product, not a phase here.

---

## Table of contents

- [1. Where we actually stand](#1-where-we-actually-stand)
- [2. What is missing — the one gap](#2-what-is-missing--the-one-gap)
- [3. Phase A — Commerce core](#3-phase-a--commerce-core)
- [4. Phase B — Produce pre-orders](#4-phase-b--produce-pre-orders)
- [5. Phase C — Availability & bookings](#5-phase-c--availability--bookings)
- [6. Phase D — Monetisation](#6-phase-d--monetisation)
- [7. Phase E — Cross-cutting](#7-phase-e--cross-cutting)
- [8. Out of scope — Ganpati travel](#8-out-of-scope--ganpati-travel)
- [9. Effort, sequencing and risk](#9-effort-sequencing-and-risk)
- [10. Non-code blockers](#10-non-code-blockers)

---

## 1. Where we actually stand

Verified against the live schema on 2026-08-20, not from memory.

### Already built — do NOT rebuild

| Capability | Evidence |
|---|---|
| Vendor onboarding, multi-outlet ownership | `sites.user_id`, `is_primary`, role flow |
| Product catalog with 20 categories | `product_categories` = 20 rows |
| Schema-driven attributes per category | `attribute_schema` JSON + `ProductAttributeValidator` |
| Variants as the unit of sale | `product_variants` (price, sale_price, stock, min/max_order_qty) |
| Tax fields recorded per product | `hsn_code`, `tax_rate`, `price_includes_tax` |
| Fulfilment switch | `products.fulfilment_type` = `enquiry\|order\|booking` |
| Booking flag | `products.is_bookable` (default false) |
| Availability *window* (not calendar) | `products.available_from` / `available_to` |
| Lead capture + metering | `product_leads`, `product_view_events`, `product_daily_stats` |
| Featured flag + plan slots | `products.is_featured`, `plans.limits.featured_slots` |
| Advertising | `banner_packages` (7), `banner_placements` (10), live `/advertise` |
| Event/jatra tables | `events`, `event_types`, `event_interactions` — **schema only, 0 rows** |

### The taxonomy already covers every in-scope revenue line

`booking_type` was seeded correctly from day one (R2), so no re-seed is needed:

| booking_type | Categories | Revenue line |
|---|---|---|
| `quantity` | `alphonso_mango`, `kokum_product`, `cashew`, `farm_produce`, `handicraft`, `retail_item` | **2 — Produce** |
| `date_range` | `room_night`, `stay_package`, `tour_package`, `catering_package` | **1 — Stays** |
| `slot` | `activity_ticket`, `boat_ride`, `equipment_rental`, `guide_service`, `taxi_transfer`, `vehicle_rental` | **4 — Experiences** |
| `none` | `menu_item`, `thali`, `service_call`, `repair_service` | enquiry-only |

---

## 2. What is missing — the one gap

**The catalog and the demand signal exist. The transaction does not.**

Verified absent: no `orders`, `order_items`, `payments`, `refunds`, `bookings`,
`product_availability`, `commission`, `payouts`, `carts`. No payment gateway in
`composer.json`. Every product in the database is `fulfilment_type = 'enquiry'`.

Today a buyer taps call/WhatsApp and transacts **off-platform**. We capture the *lead*, never
the *sale* — so there is nothing to take commission on. Every revenue line except advertising
monetises at the moment money changes hands, and that moment does not exist in code.

Closing that gap is this entire document.

---

## 3. Phase A — Commerce core

> The shared foundation. Built once, generic across produce, stays and experiences.
> **Do not build this per-vertical.**

### 3.1 Schema

```
orders                  buyer, seller snapshot, totals, status, addresses
  └── order_items       snapshots variant, unit_price, tax_rate, tax_amount   (C2)
  └── payments          gateway txn, status, idempotency_key
  └── refunds           partial/full, reason, gateway refund id
commission_ledger       per order_item: rate, amount, seller payout due
payouts                 settlement batches to a vendor
```

**Key columns (orders):**
`id`, `order_no` (human, sequential per FY), `user_id` (buyer), `site_id` + `seller_user_id`
(snapshot, C5), `status`, `subtotal`, `tax_total`, `shipping_total`, `discount_total`,
`grand_total`, `currency`, `placed_at`, `meta_data`.

**Key columns (order_items):** `order_id`, `product_variant_id`, `product_id` (denormalised
for reporting), `name_snapshot`, `unit_price`, `qty`, `tax_rate`, `tax_amount`,
`line_total`, `commission_rate`, `commission_amount`.

Order lines reference a **variant**, never a product (C1). They **snapshot** price and tax;
never recompute from the live catalog (C2). A vendor editing their price must not rewrite
what a customer already paid.

### 3.2 Order state machine

```
draft → pending_payment → paid → confirmed → fulfilled → completed
                │                    │
                └──→ payment_failed  └──→ cancelled → refunded
```

Rules:
- Only `paid` may transition to `confirmed`. Nothing is fulfillable before money is captured.
- `cancelled` from `confirmed` triggers the refund policy (§5.4).
- Transitions are logged; status is never mutated by a bare `update()`.

### 3.3 Payment gateway — Razorpay

Chosen for India: UPI-first, and **Razorpay Route** supports marketplace split settlement,
which is what commission requires without us becoming a money handler manually.

Non-negotiables:

1. **Webhook signature verification.** Never trust an unverified callback.
2. **Idempotency.** Gateways retry. A `payments.idempotency_key` unique index means a
   replayed webhook is a no-op, not a double credit.
3. **Server-side amount authority.** The client sends a cart, never a price. The server
   computes the total. A client-supplied amount is a way to buy a mango box for ₹1.
4. **Reconciliation job.** A daily command comparing gateway state to `payments`, because
   the one guaranteed failure mode is a payment captured while our request timed out.

### 3.4 Commission

Configurable per **product category** with a per-vendor override, resolved at order time and
snapshotted onto `order_items.commission_rate`. Never recomputed later — the same reason as
C2. Ledger rows are append-only; corrections are new rows, never edits.

### 3.5 APIs

| Surface | Endpoints |
|---|---|
| **User** | `createOrder`, `verifyPayment`, `myOrders`, `orderDetail`, `cancelOrder` |
| **Vendor** | `myVendorOrders`, `updateOrderStatus`, `myPayouts`, `myCommissionSummary` |
| **Admin** | `listOrders`, `orderDetail`, `refundOrder`, `listPayouts`, `markPayoutSettled`, `setCommissionRate` |

### 3.6 Tests

Order total arithmetic (incl. tax-inclusive vs exclusive) · state-machine legality ·
webhook signature rejection · **idempotent webhook replay** · amount tampering ·
commission calculation · refund math · concurrent payment callbacks.

---

## 4. Phase B — Produce pre-orders

> Revenue line 2. **The fastest path to real revenue** — needs no calendar.

### 4.1 Why this one first

- Taxonomy done (`alphonso_mango` grade/box-count/harvest-month, `kokum_product`, `cashew`)
- Commerce columns already on `products` and `product_variants` (`min_order_qty`,
  `max_order_qty`, `stock`)
- `booking_type = quantity` — **no availability calendar required**, which is the single
  most expensive component in this plan
- March–June season is a hard external deadline
- Proves the payment rails that Phase C then reuses

### 4.2 Schema

```
carts / cart_items          keyed on product_variant_id (C1)
stock_reservations          holds stock between cart and payment
shipping_addresses          reuse `addresses` morph (already exists)
shipments                   courier, tracking no, dispatched_at
```

`product_variants.stock` is an **availability figure, not a ledger**. Reservation lives in
`stock_reservations` with a TTL, so an abandoned checkout releases stock automatically.

### 4.3 Pre-order semantics

Alphonso is sold **before it is harvested**. `available_from`/`available_to` already exist on
`products` and express the harvest window. A pre-order is an order placed before
`available_from` with an expected-dispatch date — no new concept, just a flag on the order
plus a clear buyer-facing promise.

### 4.4 Flow

```
browse → add to cart → address → reserve stock → pay → order confirmed
      → vendor packs → dispatched (tracking) → delivered → commission settled
```

### 4.5 Tests

Stock reservation and release on abandonment · **oversell under concurrency** ·
min/max order qty · pre-order before harvest window · vendor dispatch flow ·
buyer cancellation before dispatch.

---

## 5. Phase C — Availability & bookings

> Revenue lines 1 (stays) and 4 (experiences). They differ only in **granularity**, so they
> are one phase, not two.

### 5.1 Schema

```
product_availability      product_variant_id, date, [slot_start, slot_end],
                          capacity, booked_count, price_override, is_blocked
bookings                  order_id, variant, check_in/out or slot, guests, status
cancellation_policies     per product/category: window → refund %
```

`price_override` slots **above** the variant price exactly as R1 anticipated. R5 is why
nothing date-varying was ever allowed into `attributes` — this table is where it belongs.

### 5.2 Rate calendar — do not skip this

Konkan pricing is not flat. Ganpati, Diwali, summer and long weekends *are* the margin. A
booking system without per-date pricing is unusable here, and it is the component most often
omitted from estimates. `price_override` is the mechanism; vendor bulk-edit APIs
("set ₹X for these dates") are the usable surface.

### 5.3 Double-booking — the hardest correctness problem here

Two users booking the last room in the same second **must** not both succeed. Application-level
"check then insert" is a race. The implementation must use a database-level guarantee:
`SELECT … FOR UPDATE` on the availability rows inside the booking transaction, plus a unique
constraint that makes an oversell physically impossible.

This is the one place in this plan where correctness cannot be traded for speed. It needs
explicit concurrency tests, not just happy-path coverage.

### 5.4 Cancellation & refunds

Policy is per product category with a vendor override: a window (e.g. >7 days, 2–7 days,
<48h) mapped to a refund percentage. Resolved and **snapshotted onto the booking** at
purchase — a vendor tightening their policy must not retroactively change what an existing
guest was promised. Same principle as C2.

### 5.5 APIs

| Surface | Endpoints |
|---|---|
| **User** | `checkAvailability`, `getRateCalendar`, `createBooking`, `myBookings`, `cancelBooking` |
| **Vendor** | `setAvailability`, `blockDates`, `setRates` (bulk), `myBookings`, `confirmBooking` |
| **Admin** | `listBookings`, `forceCancel`, `setCancellationPolicy` |

### 5.6 Tests

**Concurrent booking of the last unit** · date-range overlap maths · slot capacity ·
price override resolution order (override → variant → product) · blocked dates ·
cancellation refund tiers · policy snapshot immutability.

---

## 6. Phase D — Monetisation

> Revenue line 5. Cheapest phase — it reuses Phase A's payment rails.

1. **Self-serve plan upgrade.** Closes the deferred B2 decision. `plans` and `subscriptions`
   exist; Starter (₹499) and Growth (₹1,499) are seeded `is_active = false`. Needs a
   checkout, then activation becomes a data change.
2. **Featured-slot purchase.** `products.is_featured` and `plans.limits.featured_slots`
   already exist; needs purchase + enforcement against the slot ceiling.
3. **Jatra / festival calendar.** `events`, `event_types`, `event_interactions` exist but are
   **empty (0 rows)**. Needs seeding, admin CRUD, and promoted placement.
4. **Ads.** Substantially done — 7 packages, 10 placements, live `/advertise`.

---

## 7. Phase E — Cross-cutting

- **Admin APIs** across every phase above (orders, payouts, bookings, commission, policies).
- **Notifications** — extend the existing `VendorNotifier`: order placed, payment received,
  booking confirmed, cancellation, payout settled. Inbox + FCM paths already exist.
- **API docs + Postman**, matching `docs/marketplace-integration.md`.
- **End-to-end flow tests** — browse → pay → fulfil → settle, per vertical.

---

## 8. Out of scope — Ganpati travel

Deliberately excluded, and worth stating why so it is not mistaken for an oversight.

**It is RedBus, not Ola.** Ola/Uber is real-time dispatch — live GPS, driver matching, surge,
a driver app. Ganpati travel is *scheduled seat inventory*: known operators, known routes,
fixed departure dates, sell the seats. The second is far lighter, but still a new subsystem.

What exists today is a **timetable, not an inventory**: `routes`, `route_stops`, `bus_types`
(10 seeded) carry timings and working days. Verified: there is **no seat concept anywhere** in
the migrations or models, and a route has no *date* — so there is no trip instance to sell.

Missing entirely: trip instances, seat maps, seat holds, group booking, seat alerts.

**The real blocker is not code.** Aggregating *private* vehicles means implicitly vouching for
operators carrying families overnight. Permit, insurance and licence verification plus an
incident-response path are not optional, and that is what makes it a separate product with its
own operational burden. A 3-week annual demand window also means a slipped date costs a full
year.

MSRTC ticketing APIs, if pursued later, are an integration — not this build.

---

## 9. Effort, sequencing and risk

Solo, agentic development. "Done" = migrations + models + vendor/user/admin APIs +
notifications + tests + docs.

| Phase | Days | Risk |
|---|---|---|
| **A. Commerce core** | 15–20 | 🔴 High — money correctness |
| **B. Produce pre-orders** | 8–12 | 🟡 Medium |
| **C. Availability & bookings** | 15–20 | 🔴 High — concurrency |
| **D. Monetisation** | 6–9 | 🟢 Low |
| **E. Cross-cutting** | 6–8 | 🟢 Low |
| **Total** | **50–69 days ≈ 10–14 weeks** | |

### What actually drives the number

Not the CRUD — vendor/product/admin endpoints go fast, as the marketplace phase showed
(320 tests). Three things dominate:

1. **Money correctness** (A). Idempotent webhooks, double-charge prevention, reconciliation
   when gateway and DB disagree. Does not compress the way CRUD does — the tests *are* the work.
2. **Double-booking prevention** (C). A real DB-locking problem, not an application check.
3. **Rate calendars** (C). Seasonal pricing is the margin, and is routinely under-estimated.

### Recommended sequencing

**A (trimmed) + B ≈ 25–32 days → first commission revenue.** No calendar needed, taxonomy and
tax columns already in place, and the mango season sets the deadline. Then C unlocks stays and
experiences together; D is cheap because it reuses A.

---

## 10. Non-code blockers

Start these on **day one of Phase A** — they run in calendar time, not developer time, and
will block launch if started late.

- **Payment gateway onboarding.** Razorpay Route needs business KYC; vendor payouts need each
  vendor's bank details and PAN. Realistically 2–4 weeks, in parallel.
- **Vendor bank details.** Currently parked in `sites.meta_data`. Needs a proper, access-
  controlled home before any payout runs.
- **Legal / financial posture.** Holding and disbursing money brings settlement obligations,
  refunds, GST invoicing and dispute handling. Worth a CA conversation *before* committing to
  a mango season, not after.

---

## Appendix — contract compliance

Every phase above was checked against the shipped forward-compatibility rules. None requires
altering an existing column:

| Rule | Where honoured |
|---|---|
| R1 price via variant | `product_availability.price_override` sits above the variant |
| R2 `booking_type` seeded | Drives which vertical each phase touches — no re-seed |
| R3 `is_bookable` | Flip to true when Phase C ships |
| R4 `unit` enum locked | `per_night` + `date_range` makes nightly maths work |
| R5 nothing date-varying in `attributes` | `product_availability` is its home |
| R6 `lead_type` extensible | `booking_request` joins the enum, no migration |
| C1 variant is unit of sale | `order_items`, `cart_items`, availability all key on it |
| C2 snapshot price/tax | `order_items` + booking policy snapshot |
| C3 tax recorded now | `hsn_code`/`tax_rate` already populated at listing time |
| C4 `fulfilment_type` | `order`/`booking` become value changes, not migrations |
| C5 seller in one hop | `product → site → user_id`, snapshotted onto the order |
