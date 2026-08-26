# Banners — Tracking Ask + Creative Spec

**From:** App (tourkokan-v2)
**To:** Backend (tourkokan-backend) + Admin panel
**Date:** 2026-08-20
**Priority:** #1 is revenue-affecting.

Found during a full banner audit of all 10 placements (all 19 seed creatives measured
against how each screen renders them).

---

## 1. ⚠️ BLOCKING — impressions and clicks are never recorded

**The problem:** every banner row returns `impressions: 0, clicks: 0`, and **the app has no
way to increment either**. There is no tracking endpoint, so the app only opens
`redirect_url` on tap and reports nothing.

**Consequence:** advertisers are paying for placements whose performance numbers are
permanently zero. There is currently no way to tell an advertiser how their ad performed,
and no data behind `banner_packages` pricing.

**What the app needs — two endpoints** (mirroring the product `recordProductView` /
`recordProductLead` pattern the app already uses):

```
POST /api/v2/recordBannerImpression   { id, placement?, platform? }
POST /api/v2/recordBannerClick        { id, placement?, platform? }
```

| Field | Type | Notes |
|---|---|---|
| `id` | int | the banner id |
| `placement` | string | optional, e.g. `HOME_HERO` — useful if one creative runs in several slots |
| `platform` | string | optional, `app` (matches the product-lead convention) |

**Response:** anything with the standard envelope. The app treats both as
**fire-and-forget** — it ignores the response and never blocks the UI, same as
`recordProductView`.

**Semantics the app will implement once these exist:**
- **Impression** fires when a banner actually becomes visible — for a carousel, once per
  slide per session, not once per render (an auto-playing 3-slide carousel must not inflate
  counts on every loop).
- **Click** fires on tap, *before* `Linking.openURL`, so it is recorded even if the browser
  handoff fails.
- Both deduped client-side per session to avoid inflating numbers on re-render/scroll.

**Please confirm:** should an impression be counted once per *session*, once per *slide
view*, or every time it scrolls into view? That decision belongs to whoever prices the
packages — it changes what advertisers are billed against. The app can implement any of the
three.

---

### ✅ BUILT — backend response (2026-08-26)

**Both endpoints are live, exactly as specced.** Payloads, envelope and fire-and-forget
contract are unchanged from the ask.

```
POST /api/v2/recordBannerImpression   { id, placement?, platform? }
POST /api/v2/recordBannerClick        { id, placement?, platform? }
```

Both sit inside `auth:api` with `throttle:writes`, matching `recordProductView`.

**Response** adds one field worth reading, though you can keep ignoring it:

```json
{ "success": true, "message": "Recorded.", "data": { "id": 7, "counted": true } }
```

`counted: false` means the impression was a duplicate and was deliberately not counted. It
is **not an error** — a repeat is a normal call from a carousel, and it returns
`success: true`.

**Answering the question — impressions count once per session, per placement, per day.**

Reasoning, so you can push back knowingly: packages are sold at a fixed price, not on CPM, so
an impression is *proof of delivery*, not a billing unit. The number an advertiser actually
understands is "how many distinct people saw my ad", and that figure cannot be inflated by a
carousel looping its slides all afternoon. Clicks are **never** deduplicated — a second tap
is genuine repeat interest.

**This is now enforced server-side**, so you no longer have to carry that responsibility.
Keep your client-side dedup if you like — it saves requests — but the numbers are correct
either way, and a buggy release cannot inflate an advertiser's figures.

Two consequences worth noting:
- The same creative in two placements earns **two** impressions. Send `placement`, or they
  collapse into one.
- Only a **live** banner accrues. An expired or unpublished campaign returns 404 and records
  nothing, so an advertiser is never shown activity from outside their run.

Changing the rule (once per whole campaign, or every view) is a one-line change to
`BannerController::impressionKey()`.

**Storage:** raw events land in a new `banner_events` table — event type, placement, session,
platform, timestamp — with `banners.impressions` / `banners.clicks` kept in step as the fast
display counters. The counters alone could never answer "how did my campaign do across its
run?" or defend a billing dispute; the events table is the reporting and audit source. Same
split as `product_view_events` and `products.views_count`.

Covered by `tests/Feature/BannerTrackingTest.php` (13 tests), including the carousel-inflation
and expired-campaign cases.

**Still outstanding:** §2, the creative-size enforcement in the admin upload form. That is an
admin-panel change, not backend.

---

## 2. Creative spec to enforce at upload (admin panel)

Every placement's container ratio is now driven by the creative's real aspect ratio. If an
advertiser uploads an off-ratio image it will letterbox or crop. **Enforce these dimensions
in the admin upload form:**

| Placement | Required size | Ratio |
|---|---|---|
| `HOME_HERO` | **1200×600** (ideally **1200×667**, see below) | 2:1 |
| `HOME_MIDDLE` | 1200×400 | 3:1 |
| `HOME_FOOTER` | 1200×200 | 6:1 |
| `CITY_MIDDLE` | 1200×400 | 3:1 |
| `CITY_FOOTER` | 1200×200 | 6:1 |
| `ROUTE_DETAIL_MIDDLE` | 1200×400 | 3:1 |
| `ROUTE_DETAIL_FOOTER` | 1200×200 | 6:1 |
| `ROUTE_LIST_MIDDLE` | 1200×400 | 3:1 |
| `ROUTE_LIST_FOOTER` | 1200×200 | 6:1 |
| `APP_SPLASH` | 1080×1920 | 9:16 |

**Safe area:** keep all text and logos within **10% of every edge**. The hero band renders
slightly taller than 2:1, so it trims ~5% per side — the current seed creative has 6.67%
left padding, which only just survives. 10% padding makes any creative safe.

**Hero would ideally be 1200×667 (1.8:1).** The app renders the hero band at 1.8:1; a 2:1
creative therefore gets a 5%-per-side trim to fill it. A 1200×667 creative fills the band
with **zero** trim and would let the band go taller safely.

---

## 3. Carousel placements should be ratio-consistent

The app measures the **first** image in a placement to size the carousel. Placements with
multiple creatives (`HOME_MIDDLE` ×3, `CITY_MIDDLE` ×2, `ROUTE_*_MIDDLE` ×2) therefore
assume every creative in that slot shares one ratio.

Today all seed data is uniform, so this is fine. But if an advertiser uploads a 3:1 into a
slot whose first image is 6:1, images 2..n will letterbox. **Enforcing #2 at upload solves
this** — no app change needed.

---

## 4. ✅ Fixed on the app side (no backend action)

- **Hero was cropping 32.5% of every ad** (16% per edge) — the container was 1.35:1 while
  creatives are 2:1. Fixed; the full ad is now visible.
- **`mr_image` was never used.** The app read `image` only, so Marathi users saw English
  creatives on all 8 placements that ship a `_mr` variant. Now honoured (with fallback to
  `image`) in both the carousel and the splash overlay.
- Null-guard added on image paths (a missing path previously threw).

---

### Summary
**One thing to build:** the two tracking endpoints in #1 (plus the impression-counting
decision). **One thing to enforce:** the creative spec in #2, in the admin upload form.
Everything else is already handled app-side.
