# App — Missing Features to Implement

**From:** Backend (tourkokan-backend)
**To:** App (tourkokan-v2)
**Date:** 2026-08-26
**Basis:** Cross-check of the app feature-status board against the live backend
(`api/v2` route list + database schema), not read from memory.

This is the gap list: features the app should build, split by whether the backend is already
ready, needs a decision first, or needs an endpoint built. Sequencing recommendation at the
end.

---

## A. Backend-ready — pure app wiring (no backend work)

The full marketplace server exists; the app simply doesn't call it yet. Building these is
wiring, not greenfield.

| # | App feature | Backend endpoints already live |
|---|---|---|
| 1 | **Browse products** | `listProducts`, `featuredProducts`, `productsBySite`, `categoryAttributeSchema`, `allowedProductCategories`, `businessCategories` |
| 2 | **Product detail** (one category-aware template) | `productDetail`, `getProduct` |
| 3 | **Vendor profile** | `vendorProfile`, `listVendors` |
| 4 | **Vendor dashboard** (leads + analytics) | `myLeads`, `markLeadRead`, `productAnalytics`, `myUsageStats` |
| 5 | **Add / edit product** (schema-driven form) | `addProduct`, `updateProduct`, `uploadProductMedia`, `saveProductVariant`, `submitProductForReview`, `deleteProduct`, `reorderProductMedia`, `setProductCover`, `deleteProductMedia`, `deleteProductVariant`, `toggleProductStatus`, `bulkProductStatus`, `myProducts` |
| 6 | **Become a vendor** flow | `requestRole`, `myRoleRequests`, `addSite`, `mySites`, `setPrimarySite`, `mySubmissions`, `updateMySubmission`, `deleteMySubmission` |
| 7 | **Subscription / plans** | `listPlans`, `mySubscription` |
| 8 | **Buyer favourites & enquiry history** | `favourites`, `myEnquiries`, `addDeleteFavourite` |
| 9 | **Banner impression / click tracking** — *shipped on backend 2026-08-26, not on the board* | `recordBannerImpression`, `recordBannerClick` |

### #9 is the quick win
Two fire-and-forget calls, mirroring `recordProductView`. Implementation guide already
provided: `docs/app-banner-tracking-implementation.md`. Turns advertisers' permanent-zero
impression/click numbers into real data.

> **Server prerequisite for #9:** the `dev` branch must be deployed to the target server and
> migrated (`git pull origin dev && php artisan migrate --force && php artisan optimize:clear`)
> — `recordBanner*` and the `banner_events` table do not exist on an un-deployed server, and
> the app will get the generic fallback response until they do.

---

## B. Needs a backend decision before the app builds it

| # | App feature | Blocker |
|---|---|---|
| 10 | **Custom properties builder** (vendor-defined specs) | The backend **rejects** any product attribute key not in the category schema (`ProductAttributeValidator`, decision B1 = no-build). If the app ships this as designed, **every product submission through it returns 422.** Decision required: reverse B1 (~1 hr — add a `custom_specs` JSON column the validator ignores) **or** remove the screen from the plan. |

The B1 rationale, for context: free-form specs are permanently unfilterable and
uncomparable ("Floor: 3" vs "floor no: third"), and the intended path for a property that
matters is adding it to the category schema — a single admin action, no app release.

---

## C. App feature shipped/planned, but backend endpoint is missing

These need backend work before the app screen can function.

| # | App feature | Gap |
|---|---|---|
| 11 | **Route data-accuracy report** — marked shipped (v55) | No `report` / `correction` / `feedback` route exists. Correction submissions currently have nowhere to go. **Backend endpoint needed.** |
| 12 | **Wallet transaction history** | Only the *total* is exposed (`user-profile` returns `wallets_sum_amount`). No per-transaction list endpoint. Fine if the screen only shows a balance. |
| 13 | **Referral code display / list** | A referral is credited at registration, but there is no endpoint to fetch a user's referral code or their referral list, and `users.code` is null on every row. Confirm where the profile screen sources the code. |

---

## Board corrections (feature-status board `c29b7c46`)

Verified against the live system:

- Taxonomy is understated: the board says "13 groups, ~90 sub-categories"; actual is
  **14 groups, 99 sub-categories** (113 categories total).
- **20 product categories** — correct.
- Everything in the board's **Live** column has backend support.
- **Custom properties builder** should not sit under "approved to build against" until B1 is
  resolved (see #10).
- **Banner tracking** (#9) is missing from the board entirely and is now app work.

---

## Recommended sequence

1. **#9 banner tracking** — trivial, revenue-visible, guide ready. (After the server deploy.)
2. **#1 → #6 marketplace slice** — all backend-ready; the largest coherent block of value.
3. **#7, #8** — subscription and buyer history, backend-ready.
4. **#10 → #13** — hold until the backend decision (#10) and endpoints (#11, and #12/#13 if
   those screens are real) land.

### What is blocked on backend (me), not the app
- **#10** — the B1 decision.
- **#11** — the correction-reporting endpoint (cleanest to build; can be done with tests
  quickly).
- **#12 / #13** — only if those screens are actually planned.
