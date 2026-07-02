# Tourkokan Banner Advertising — Pricing Model

**Status:** Adopted (Phase 1 / launch pricing)
**Date:** 2 July 2026
**Applies to:** `banner_packages` / `banner_placements` / `banners` tables, seeded by `database/seeders/BannerSeeder.php`
**Related:** [DOC_BANNER_ADVERTISING.md](../DOC_BANNER_ADVERTISING.md) (API + admin panel implementation guide)

---

## 1. Cost baseline

Monthly platform running cost (hosting, AWS S3, MSG91 SMS, Google Maps, Claude API,
domain, misc): **₹5,000 – ₹15,000/month**. All calculations below use the midpoint:

```
Infra cost:  ₹10,000 / month  =  ₹333 / day  =  ₹2,333 / week
```

Goal: banner advertising alone should cover 100% of infra cost with **2–3 active
advertisers**, and scale to ~2.5× cost when inventory is fully sold.

---

## 2. Market benchmarks (researched 2 July 2026)

| Platform | Model | Rate |
|---|---|---|
| JustDial banner ads | CPM / CPC | ₹100–3,000 per 1,000 impressions; ₹5–50 per click |
| AdMob banner (India avg) | CPM | ₹40–80 per 1,000 impressions |
| India overall display avg | CPM | ≈ ₹55 |
| Hyperlocal ad platforms | Per impression | ≈ ₹0.15 / impression (₹150 CPM) — **best comparable**: audience is high-intent Konkan travellers |
| MakeMyTrip / TripAdvisor | Enterprise CPM | No public rate card; targets hotel chains and airlines |

Sources:
- https://www.themediaant.com/digital/justdial-advertising/banner
- https://www.businessofapps.com/ads/research/mobile-app-advertising-cpm-rates/
- https://www.themediaant.com/digital/hyperlocal-digital-marketing-website-advertising
- https://www.themediaant.com/digital/makemytrip-advertising

Key takeaway: small tourism platforms do not sell CPM — they sell **flat
sponsorships** (like local newspaper ads). Our package model is correct; prices
must match delivered value at current scale.

---

## 3. Impression-value calculation (launch scale)

Assumption for early traction: **~300 daily active users**, home screen viewed
~2×/day per user.

```
HOME placements:   ~600 views/day   → ~4,200 impressions / 7 days
Inner pages:       ~215 views/day   → ~1,500 impressions / 7 days

Market value of HOME slot, 7 days @ ₹150 CPM:   4,200 × ₹0.15  =  ₹630
With 2× exclusivity premium (advertiser owns the slot, no rotation
with strangers):                                                 ≈ ₹1,200

Market value of inner slot, 7 days @ ₹150 CPM:  1,500 × ₹0.15  =  ₹225
With exclusivity premium:                                        ≈ ₹450
```

This is why the original prices (₹2,999/7d, ₹9,999.99/30d, ₹21,999.99/90d) are
**3–6× above delivered value at launch scale** — they are kept as the Phase 2
rate card (see §6).

---

## 4. Phase 1 — Launch rate card (ACTIVE)

Placement groups (codes from `banner_placements`):

| Group | Codes |
|---|---|
| Inner | `CITY_MIDDLE`, `CITY_FOOTER`, `ROUTE_DETAIL_MIDDLE`, `ROUTE_DETAIL_FOOTER`, `ROUTE_LIST_MIDDLE`, `ROUTE_LIST_FOOTER` |
| Home secondary | `HOME_MIDDLE`, `HOME_FOOTER` |
| Premium | `HOME_HERO`, `APP_SPLASH` |

| Package | Duration | Allowed placements | Price | Per-day | Derivation |
|---|---|---|---|---|---|
| **Starter** | 7 days | Inner | **₹499** | ₹71 | ≈ inner-slot market value (₹450) + margin; impulse-buy price for lodges/restaurants, undercuts JustDial entry plans |
| **Growth** | 30 days | Inner + Home secondary | **₹1,499** | ₹50 | 4 × Starter (₹1,996) − 25% volume discount |
| **Spotlight** | 30 days | All (incl. `HOME_HERO`, `APP_SPLASH`) | **₹3,999** | ₹133 | ≈ 2.7 × Growth — matches the ~3× visibility premium JustDial charges for its top tier |
| **Season Pass** | 90 days | All | **₹9,999** | ₹111 | Anchor tier for resorts covering a full tourist season (Oct–May); 3 × Spotlight (₹11,997) − ~17% commitment discount |

---

## 5. Break-even and revenue ceiling

```
Break-even (₹10k/mo midpoint):
  2 × Spotlight (₹7,998) + 1 × Growth (₹1,499) + 1 × Starter (₹499) = ₹9,996  ✓

Break-even (₹15k/mo worst case):
  3 × Spotlight (₹11,997) + 2 × Growth (₹2,998)                     = ₹14,995 ✓

Inventory ceiling (all 18 seeded slots sold):
  4 hero slots  × ₹4k                ≈ ₹16,000
  ~8 inner/home slots × ~₹1.5k       ≈ ₹12,000
  Total potential                    ≈ ₹25–28k / month  →  ~2.5× max spend
```

---

## 6. Phase 2 — Growth rate card (INACTIVE, pre-seeded)

Activate when the app reaches **~10,000–15,000 monthly active users**
(≈ 2,200 home-screen impressions/day — the point where ₹9,999/30d equals fair
CPM value). Flip `is_active` on these `banner_packages` rows in the admin panel:

| Package | Duration | Price | Allowed placements |
|---|---|---|---|
| Basic Starter | 7 days | ₹2,999 | Middle placements |
| Standard Growth | 30 days | ₹9,999.99 | Hero + footer placements |
| Premium Dominance | 90 days | ₹21,999.99 | All |

When activating Phase 2, deactivate (don't delete) the Phase 1 packages so
existing campaign rows keep their package reference.

---

## 7. Tax and seasonality

- **GST:** advertising services attract **18% GST**. Decide before first sale
  whether listed prices are inclusive or "+GST", and show it in the app/invoice.
  (`banner_packages.price` stores the base price; there is no tax logic in code.)
- **Seasonality:** Konkan traffic peaks Oct–May (Ganpati, Diwali, summer). No
  schema change needed — run a second "Peak Season" package set at ~1.5× and
  toggle `is_active` per season.

---

## 8. Data mapping

Everything above is seeded by `database/seeders/BannerSeeder.php`
(registered in `DatabaseSeeder`; idempotent via `updateOrCreate`):

- 10 rows in `banner_placements` (codes in §4)
- 7 rows in `banner_packages` — 4 Phase 1 (active) + 3 Phase 2 (inactive)
- 18 placeholder "Ad Space" rows in `banners` (one per sellable slot,
  `redirect_url` → contact page) so every placement renders a
  "buy this space" creative until a real campaign replaces it

## 9. Known schema caveat

`banners.status` is a **boolean** (`tinyint(1)`) from the original 2023
migration — the string-status (`pending/approved/rejected/expired`) column in
`2025_01_18_000003_add_advertising_columns_to_banners_table.php` was skipped by
its `hasColumn` guard. Code that writes `'status' => 'pending'` or filters
`where('status', 'approved')` (e.g. `Banner::scopeActive`) does not work as
intended against a boolean column. Until migrated, treat `status` as
approved-yes/no and rely on `is_active` + date range for serving.
