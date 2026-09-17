# Dashboard Analytics — Required Backend Changes

Backend spec for the data the **main admin dashboard** (`/#/dashboard`) needs to render
its KPI tiles, graphs, and charts.

The admin panel already renders real analytics on the dashboard using the existing
endpoints. This document lists the **new endpoints** and **changes to existing endpoints**
required to complete the graphs/charts and headline totals.

---

## Conventions (apply to every endpoint below)

- **Method:** `POST`
- **Base URL:** `/admin/v2`
- **Auth:** `Authorization: Bearer <token>` · role `admin` or `superadmin`
- **Envelope:** every response is wrapped as
  ```json
  { "version": "2.0.5", "language": "en", "success": true, "message": "...", "data": <payload> }
  ```
  Only the `data` payload is shown below.
- **Keys:** `snake_case`. **Dates:** `YYYY-MM-DD`. **Numbers:** plain integers/decimals.
- **Time scoping (where noted):** accept `days` (int, last N days) OR `date_from` + `date_to`
  (`YYYY-MM-DD`). If none supplied, default to last 30 days.
- Frontend charts use `@coreui/react-chartjs` (`CChartLine`, `CChartBar`, `CChartDoughnut`).

---

## Summary

| # | Endpoint | Status | Powers | Priority |
|---|----------|--------|--------|----------|
| 1 | `analytics/activityTimeseries` | **NEW** | Main traffic line chart | **High** |
| 2 | `analytics/globalTotals` | **NEW** | KPI tiles + pending-work counters | **High** |
| 3 | `analytics/userGrowthTimeseries` | **NEW** | User-growth area chart | Medium |
| 4 | `analytics/topRoutes` | **CHANGE** | Add source/destination names | Medium |
| 5 | `analytics/vendorFunnel` | **NEW (optional)** | Vendor pipeline chart | Low |
| 6 | `analytics/platformBreakdown` | exists ✅ | Platform doughnut | — |
| 7 | `analytics/eventTypeSummary` | exists ✅ | Event-type bar chart | — |

---

## 1. `analytics/activityTimeseries` — NEW — main line chart

The single most important gap: there is currently **no time-series endpoint**, so the
dashboard has no real historical trend chart.

**Request**

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `days` | integer | ❌ | Last N days. Default `30`. |
| `date_from` | date | ❌ | `YYYY-MM-DD`. Overrides `days` when both `date_from` and `date_to` are sent. |
| `date_to` | date | ❌ | `YYYY-MM-DD`. |
| `granularity` | string | ❌ | `day` (default) / `week` / `month`. |

**Response `data`** — one row per time bucket, ascending by `date`:

```json
[
  { "date": "2026-09-01", "logins": 120, "api_calls": 4300, "site_views": 890, "event_views": 210, "active_users": 340 },
  { "date": "2026-09-02", "logins": 138, "api_calls": 4620, "site_views": 910, "event_views": 245, "active_users": 358 }
]
```

Notes:
- Include a row for **every** bucket in the range, even zero-activity days (fill gaps with 0),
  so the line chart has no missing points.
- `active_users` = distinct `user_id` in that bucket.
- Metric source = `user_activity_logs` grouped by `DATE(created_at)` and `event_type`.

---

## 2. `analytics/globalTotals` — NEW — KPI tiles

`dashboardStats` is **today-only**. The dashboard also needs all-time headline totals and the
pending-work counters used daily.

**Request:** none — send `{}`.

**Response `data`**

```json
{
  "total_users": 63,
  "total_vendors": 4,
  "total_tourists": 58,
  "total_sites": 120,
  "approved_sites": 95,
  "pending_sites": 12,
  "total_events": 40,
  "total_products": 210,
  "approved_products": 180,
  "pending_products": 18,
  "active_subscriptions": 4,
  "total_banners": 25,
  "active_banners": 9
}
```

Notes:
- `pending_sites` = sites with `submission_status = 'pending'`.
- `pending_products` = products with `status = 'pending'`.
- `total_vendors` = users having the `vendor` role; `total_tourists` = users with `tourist` role.
- All fields optional to extend later, but keep the names stable once shipped.

---

## 3. `analytics/userGrowthTimeseries` — NEW — growth area chart

**Request:** same time-scoping as #1 (`days` / `date_from` / `date_to` / `granularity`).

**Response `data`**

```json
[
  { "date": "2026-09-01", "new_users": 5, "cumulative_users": 41 },
  { "date": "2026-09-02", "new_users": 3, "cumulative_users": 44 }
]
```

- `new_users` = users created in that bucket.
- `cumulative_users` = running total of all users up to and including that bucket.

---

## 4. `analytics/topRoutes` — CHANGE — add names

Currently returns numeric IDs only, so the dashboard can only show IDs.

**Current**
```json
[ { "source_id": 3, "destination_id": 7, "search_count": 44, "unique_users": 20 } ]
```

**Required** — add resolved names (keep the IDs for linking):
```json
[
  { "source_id": 3, "source_name": "Kankavli", "destination_id": 7, "destination_name": "Malvan", "search_count": 44, "unique_users": 20 }
]
```

Request params unchanged (`limit`, `date_from`, `date_to`).

---

## 5. `analytics/vendorFunnel` — NEW (optional) — pipeline chart

Visualizes the vendor approval funnel on the dashboard.

**Request:** none — send `{}`.

**Response `data`**

```json
{
  "pending_role_requests": 3,
  "pending_sites": 12,
  "pending_products": 18,
  "approved_products": 180,
  "active_subscriptions": 4
}
```

(If #2 `globalTotals` already carries these, this endpoint is redundant — pick one.)

---

## 6. `analytics/platformBreakdown` — EXISTS ✅ — no change

Already chart-ready (doughnut: labels = `platform`, data = `count`).
```json
[
  { "platform": "android", "count": 5200, "unique_users": 40 },
  { "platform": "web",     "count": 3100, "unique_users": 18 }
]
```

---

## 7. `analytics/eventTypeSummary` — EXISTS ✅ — optional enhancement

Already chart-ready (bar: labels = `event_type`, data = `count`).
```json
[
  { "event_type": "site_view", "count": 890 },
  { "event_type": "login",     "count": 120 }
]
```
Optional: accept `days` / `date_from` / `date_to` so it can be scoped to the dashboard's
selected range (today it appears to be all-time / unscoped).

---

## Build order (backend)

1. **`activityTimeseries`** (#1) — unblocks the main trend chart.
2. **`globalTotals`** (#2) — unblocks KPI + pending-work tiles.
3. **`topRoutes` names** (#4) — quick change, big readability win.
4. `userGrowthTimeseries` (#3), then `vendorFunnel` (#5) if wanted.

Once #1 and #2 are live, the frontend will add: a `CChartLine` traffic chart, a `CChartDoughnut`
for platforms, a `CChartBar` for event types, and the totals/pending KPI tiles — plus a date-range
filter wired to the time-scoped endpoints.
