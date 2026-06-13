# Image Guidelines — TourKokan

Exact sizes for every image type uploaded via the admin panel, derived from how
the mobile app actually renders them. Resize to these before upload; the admin
panel / backend should validate against the rules at the bottom.

---

## Do tablets and phones need different images?

**No. One image per slot serves all devices.**

- The app scales every image to its container (`cover` for heroes/cards,
  `contain` for ads/gallery viewer) — it never selects files by device type.
- The recommended upload sizes below are 3× phone width (~1500–1620 px), which
  already covers a 10" tablet at its 2× density (~1600 px). Same file, sharp
  on both.
- The only device difference is **cropping, not resolution**: on tablets the
  home hero displays a wide 2.4:1 center band of your 1.35:1 image
  (top/bottom get cropped). Handle this with composition, not a second file:

> **Safe-zone rule:** keep all text, logos, and key subjects inside the
> center **70%** of hero images (both axes). Treat the outer edges as
> croppable decoration.

---

## Size chart

| # | Image type | Used in | Aspect ratio | **Upload size (px)** | Minimum (px) | Max file size |
|---|------------|---------|--------------|----------------------|--------------|---------------|
| 1 | Home hero banner (`HOME_TOP`) | Home screen top carousel | **1.35 : 1** | **1620 × 1200** | 1080 × 800 | 400 KB |
| 2 | Site detail hero | Site/place detail page top | **4 : 3** | **1600 × 1200** | 1080 × 810 | 400 KB |
| 3 | Ad banners (`HOME_MIDDLE`, `SITE_*`, `CITY_MIDDLE`, Routes) | Mid-page carousels | **2.5 : 1** | **1500 × 600** | 1080 × 432 | 400 KB |
| 4 | Gallery images | Gallery grid (3-col squares) + full-screen viewer | **1 : 1** | **1080 × 1080** | 720 × 720 | 500 KB |
| 5 | Event images | Events list cards | **16 : 9** | **1280 × 720** | 960 × 540 | 250 KB |
| 6 | Site/place card thumbnails | Package/Place/City cards | **3 : 2** | **900 × 600** | 600 × 400 | 250 KB |
| 7 | Category icons | Category list circles | **1 : 1** | **256 × 256** (PNG) | 128 × 128 | 50 KB |

### Behaviour notes per type

- **Heroes (1, 2):** rendered with `cover` — the image fills the box and edges
  crop on mismatched screens. Never distorts. Apply the safe-zone rule.
- **Ad banners (3):** the app auto-sizes the container to the **uploaded
  image's own ratio** — whatever ratio you upload is exactly what users see.
  Standardize on 2.5:1 so all ad slots look uniform across the app.
- **Gallery (4):** shown as small square thumbnails (cover-cropped) AND
  full-screen (contain). Square 1080×1080 serves both well.
- **Cards (5, 6):** `cover`-cropped into fixed card boxes; center your subject.

---

## File rules

| Rule | Value |
|------|-------|
| Format | JPEG or WebP for photos (quality ~80). PNG only for icons/transparency. |
| Color profile | sRGB |
| Metadata | Strip EXIF (size + privacy) |
| Naming | lowercase, hyphenated, no spaces: `tarkarli-beach-sunset.jpg` |

File size matters more than pixels — every image loads from S3 over mobile
data. Stay under the per-type caps above.

---

## Upload validation (admin panel / backend)

Validate `minWidth` + aspect ratio (±10% tolerance) + max bytes. If the image
is **too large**, auto-resize down to the recommended size instead of
rejecting. Reject only when too small or wrong ratio.

```js
// Admin panel (JS) validation config
const IMAGE_RULES = {
  hero_home: {ratio: 1.35,   minW: 1080, maxKB: 400},
  hero_site: {ratio: 4 / 3,  minW: 1080, maxKB: 400},
  ad_banner: {ratio: 2.5,    minW: 1080, maxKB: 400},
  gallery:   {ratio: 1,      minW: 720,  maxKB: 500},
  event:     {ratio: 16 / 9, minW: 960,  maxKB: 250},
  card:      {ratio: 1.5,    minW: 600,  maxKB: 250},
  icon:      {ratio: 1,      minW: 128,  maxKB: 50},
  ratioTolerance: 0.10,
};
```

```php
// Laravel FormRequest mirror (example: ad banner)
'image' => 'required|image|mimes:jpeg,png,webp|max:400'
         . '|dimensions:min_width=1080,ratio=5/2',
```

---

## Where these numbers come from (app code references)

| Type | Source |
|------|--------|
| Home hero | `HomeScreen.js` — `BANNER_HEIGHT = SW / 1.35`; tablet cap `width/2.4 ≤ 380dp` |
| Site hero | `SiteDetailPage.js` — `HERO_H = SW * 0.75` |
| Ad banners | `Banner.js` — auto-height from image ratio, fallback `width / 2.5` |
| Gallery grid | `ExploreGrid.js` — 3 columns, square `CELL_SIZE` |
| Event cards | `EventsList.js` — full width × 170dp, `cover` |
| Cards | `HomeScreen.js` — `cardWidth 180dp × 0.66` image ratio |

If any of these layout constants change in the app, update this document.
