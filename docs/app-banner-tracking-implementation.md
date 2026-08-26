# Banner Tracking — App Implementation Guide

**Feature:** Advertising impression & click tracking
**Endpoints:** `POST /api/v2/recordBannerImpression` · `POST /api/v2/recordBannerClick`
**Status:** Live on backend `dev`. Answers `docs/banner-tracking-backend-ask.md` §1.

Advertisers' `impressions` and `clicks` were permanently `0` because nothing could increment
them. Both endpoints now exist, built to mirror `recordProductView` / `recordProductLead` —
same payload shape, same envelope, same fire-and-forget contract — so wiring them up should
be a few lines.

---

## 1. The endpoints

```
POST /api/v2/recordBannerImpression
POST /api/v2/recordBannerClick

Authorization: Bearer <token>      // same user token as everything else
```

### Request body

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | int | **yes** | The banner id |
| `placement` | string | no | e.g. `HOME_HERO`. **Send it** — see §3 |
| `platform` | string | no | `app` |

All reads and writes in this API are `POST`; everything goes in the body.

Both are rate-limited under the same `throttle:writes` bucket as the product engagement
endpoints, and both require a logged-in user.

---

## 2. Response shapes

Captured live, not paraphrased.

**Impression counted:**
```json
{"version":"1.0.0","language":"en","success":true,"message":"Recorded.","data":{"id":1,"counted":true}}
```

**Duplicate impression — deliberately not counted:**
```json
{"version":"1.0.0","language":"en","success":true,"message":"Already recorded.","data":{"id":1,"counted":false}}
```

**Unknown / inactive banner:**
```json
{"success":false,"message":{"id":["The selected id is invalid."]}}
```

Three things to note:

- **A duplicate is `success: true`, not an error.** Re-firing is normal and expected from a
  carousel. `data.counted` tells you whether it actually incremented — useful for debugging,
  safe to ignore in production.
- **Read `success`, never the HTTP status.** This API answers `200` on most failures and
  `422` on validation.
- **Treat both as fire-and-forget.** Ignore the response, never block the UI, exactly as you
  already do with `recordProductView`.

---

## 3. Counting rule — answering the question in the ask

> *"Should an impression be counted once per session, once per slide view, or every time it
> scrolls into view?"*

**Decision: once per session, per placement, per day.**

Reasoning, so you can push back knowingly: packages are sold at a **fixed price, not on
CPM**, so an impression is *proof of delivery* rather than a billing unit. The number an
advertiser actually understands is "how many distinct people saw my ad", and that must not be
inflatable by an auto-playing carousel looping its slides all afternoon.

**Clicks are never deduplicated** — a second tap is genuine repeat interest.

### What changed for you

**This is now enforced server-side.** You no longer carry responsibility for correctness:

- Keep your client-side per-session dedup if you like — it saves requests — but the numbers
  are correct either way.
- A buggy release can no longer inflate an advertiser's figures.
- You can fire on every slide view without worrying. The server collapses them.

### ⚠️ Send `placement`

The dedup key is **banner + placement + session + day**. So:

- The same creative running in `HOME_HERO` and `CITY_MIDDLE` earns **two** impressions —
  correct, they are two paid slots.
- Omit `placement` and those collapse into **one**, under-reporting the advertiser.

---

## 4. When to fire

| Event | Fire when | Note |
|---|---|---|
| **Impression** | The creative actually becomes *visible* — not merely mounted | For a carousel, once per slide becoming active is fine; the server dedups |
| **Click** | On tap, **before** `Linking.openURL` | So it survives a failed browser handoff |

Only **live** campaigns accrue. An expired or unpublished banner returns a failure envelope
and records nothing — so an advertiser is never shown activity from outside their run. If you
see `success: false` on a banner you just rendered, the campaign has almost certainly ended;
that banner should no longer be served to you.

---

## 5. Suggested wiring

**Service** — alongside the existing marketplace calls:

```js
export const recordBannerImpression = (id, placement, platform = 'app') =>
  api.post('/api/v2/recordBannerImpression', { id, placement, platform })
     .catch(() => {});   // fire-and-forget

export const recordBannerClick = (id, placement, platform = 'app') =>
  api.post('/api/v2/recordBannerClick', { id, placement, platform })
     .catch(() => {});
```

**Impression** — on the banner becoming visible:

```js
useEffect(() => {
  if (isVisible) recordBannerImpression(banner.id, placementCode);
}, [isVisible, banner.id]);
```

**Click** — before the handoff:

```js
const onPress = () => {
  recordBannerClick(banner.id, placementCode);   // not awaited
  Linking.openURL(banner.redirect_url);
};
```

Neither call should ever be awaited or allowed to throw into the UI.

---

## 6. What this does not cover

**§2 of the ask — creative dimension enforcement at upload — is not part of this.** That is
an admin-panel change, not backend and not app. The sizes and the 10% safe-area rule in your
doc still need enforcing in the admin upload form before advertisers can self-serve
creatives.

Nothing in §3 or §4 of your ask needs backend action; §4 was already fixed app-side.

---

## 7. Where the numbers live

For context, if you ever need to reason about the data:

- `banners.impressions` / `banners.clicks` — denormalised counters, the fast value on a
  banner row. Unchanged shape; they now actually move.
- `banner_events` — the new per-event record (type, placement, session, platform, timestamp).
  This is the reporting and audit source, and it is what makes per-day campaign reporting and
  billing-dispute defence possible. A counter alone has no dates.

Same split the catalog already uses between `product_view_events` and `products.views_count`.

Backend cover: `tests/Feature/BannerTrackingTest.php` — 13 tests, including the
carousel-inflation and expired-campaign cases.
