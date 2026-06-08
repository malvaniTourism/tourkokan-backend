# User Activity Tracking & Analytics

**Version:** 1.0  
**Date:** 2026-06-05

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [How It Works (Flow)](#2-how-it-works-flow)
3. [Event Taxonomy](#3-event-taxonomy)
4. [Backend — Implementation Details](#4-backend--implementation-details)
5. [Frontend — Integration Guide](#5-frontend--integration-guide)
6. [Admin API Reference](#6-admin-api-reference)
7. [Queue Setup](#7-queue-setup)
8. [Data Retention](#8-data-retention)

---

## 1. System Overview

Every API call is silently tracked in the `user_activity_logs` table.  
Tracking happens **after the response is sent** — the user never waits for it.

```
User hits any API
        │
        ▼
Middleware records start time
        │
        ▼
Controller runs → sets log context on $request->attributes (optional enrichment)
        │
        ▼
Response sent to client  ← user gets response immediately here
        │
        ▼  (terminate() fires after response is flushed)
ActivityLogMiddleware builds single $payload array
        │
        ▼
LogUserActivityJob dispatched to `analytics` queue
        │
        ▼
Job writes one row to user_activity_logs
```

**Zero latency** — the user's response is never delayed by logging.

---

## 2. How It Works (Flow)

### Step-by-step

1. **Request arrives** → `ActivityLogMiddleware::handle()` captures `$startTime`
2. **Controller executes** → can optionally enrich log context using `$request->attributes->set()`
3. **Response sent** → client receives the JSON response immediately
4. **`terminate()` fires** → builds the payload array using `array_merge`:
   ```php
   $logAttrs = collect($request->attributes->all())
       ->only(['log_entity_type', 'log_entity_id', 'log_entity_name', 'log_meta_data'])
       ->mapWithKeys(fn($v, $k) => [str_replace('log_', '', $k) => $v])
       ->filter()
       ->toArray();

   $payload = array_merge([
       'user_id', 'route', 'method', 'ip_address', 'user_agent',
       'platform', 'app_version', 'success', 'response_time_ms'
   ], $logAttrs);
   ```
5. **Single `$payload` array** dispatched to `LogUserActivityJob`
6. **Job resolves `event_type`** from the route string, then calls `UserActivityLog::create()`

### Why single array?

Instead of 13 constructor parameters, the job receives one clean array:
```php
// ✅ Clean
LogUserActivityJob::dispatch($payload)->onQueue('analytics');

// ❌ Old way — fragile, hard to extend
LogUserActivityJob::dispatch($path, $method, $ip, $ua, $source, $version, ...);
```

To add a new field: add it to `$payload` in the middleware. Job and DB column. Done.

### How controllers inject extra context

Only 5 key controllers set enrichment. All others still get logged automatically via the middleware's route-to-event mapping — they just won't have `entity_id` / `entity_name`.

```php
// Example in SiteController@getSite
$request->attributes->set('log_entity_type', 'site');
$request->attributes->set('log_entity_id',   $site->id);
$request->attributes->set('log_entity_name', $site->getRawOriginal('name'));
$request->attributes->set('log_meta_data', [
    'categories'  => $site->categories->pluck('code')->toArray(),
    'is_favorite' => (bool) $site->is_favorite,
]);
```

The `log_` prefix is stripped automatically — keys become `entity_type`, `entity_id`, etc. in the DB row.

---

## 3. Event Taxonomy

All events are stored under the `event_type` column.

| event_type | Triggered by route | entity_type stored |
|---|---|---|
| `login` | `v2/auth/login` | — |
| `logout` | `v2/auth/logout` | — |
| `register` | `v2/auth/register` | — |
| `otp_send` | `v2/auth/sendOtp` | — |
| `otp_verify` | `v2/auth/verifyOtp` | — |
| `site_view` | `v2/getSite` | `site` |
| `site_list` | `v2/listCities` | — |
| `site_submit` | `v2/addSite` | `site` |
| `site_update` | `v2/updateMySubmission` | `site` |
| `favourite_toggle` | `v2/favourite` | `site` or `event` |
| `comment_add` | `v2/addComment` | — |
| `rating_add` | `v2/addUpdateRating` | — |
| `event_view` | `v2/getEvent` | `event` |
| `event_list` | `v2/listEvents` | — |
| `event_create` | `v2/createEvent` | `event` |
| `event_update` | `v2/updateEvent` | `event` |
| `event_cancel` | `v2/cancelEvent` | `event` |
| `event_interaction` | `v2/eventInteraction` | `event` |
| `route_search` | `v2/routes` | — |
| `route_list` | `v2/listroutes` | — |
| `route_stops_view` | `v2/getRouteStops` | `route` |
| `category_view` | `v2/getCategory` | `category` |
| `category_list` | `v2/listCategories` | — |
| `landing_page` | `v2/landingpage` | — |
| `profile_update` | `v2/updateProfile` | `user` |
| `role_request` | `v2/requestRole` | — |
| `gallery_upload` | `v2/uploadSiteGallery` / `v2/uploadEventGallery` | — |
| `banner_fetch` | `v2/banners` | — |
| `contact_query` | `v2/addQuery` | — |
| `message_view` | `v2/myMessages` | — |
| `api_call` | all other routes | — |

---

## 4. Backend — Implementation Details

### Files Created / Modified

| File | Action | Purpose |
|---|---|---|
| `database/migrations/2026_06_05_000001_create_user_activity_logs_table.php` | Created | DB schema |
| `app/Models/UserActivityLog.php` | Created | Eloquent model + scopes |
| `app/Jobs/LogUserActivityJob.php` | Created | Async job, single array payload |
| `app/Http/Middleware/ActivityLogMiddleware.php` | Created | terminate() hook |
| `app/Http/Controllers/Admin/V2/AnalyticsController.php` | Created | All 12 admin analytics APIs |
| `bootstrap/app.php` | Modified | Registered ActivityLogMiddleware globally |
| `routes/admin.php` | Modified | Added 12 analytics routes under `analytics/*` |
| `app/Http/Controllers/User/V2/SiteController.php` | Modified | Log context on `getSite` |
| `app/Http/Controllers/User/V2/EventController.php` | Modified | Log context on `show` |
| `app/Http/Controllers/User/V2/FavouriteController.php` | Modified | Log context + action on `addDeleteFavourite` |
| `app/Http/Controllers/User/V2/RouteController.php` | Modified | Log context on `routes` |

### DB Schema

```sql
CREATE TABLE user_activity_logs (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id           BIGINT UNSIGNED NULL,          -- NULL for guest/unauthenticated
    event_type        VARCHAR(50) NOT NULL,           -- login, site_view, etc.
    entity_type       VARCHAR(50) NULL,               -- site, event, route, category
    entity_id         BIGINT UNSIGNED NULL,           -- the entity's DB id
    entity_name       VARCHAR(255) NULL,              -- snapshot (survives deletions)
    route             VARCHAR(150) NULL,              -- /api/v2/getSite
    method            VARCHAR(10) NULL,               -- POST / GET
    ip_address        VARCHAR(45) NULL,
    user_agent        VARCHAR(300) NULL,
    platform          VARCHAR(20) NULL,               -- mobile, web, admin
    app_version       VARCHAR(20) NULL,
    success           TINYINT(1) DEFAULT 1,           -- was response success:true
    response_time_ms  SMALLINT UNSIGNED NULL,         -- ms from request to response
    meta_data         JSON NULL,                      -- event-specific extra context
    created_at        TIMESTAMP NULL,
    updated_at        TIMESTAMP NULL,

    INDEX (user_id),
    INDEX (event_type),
    INDEX (entity_type),
    INDEX (entity_id),
    INDEX (user_id, event_type),
    INDEX (entity_type, entity_id),
    INDEX (event_type, created_at),
    INDEX (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
```

### meta_data examples

```json
// site_view
{ "categories": ["heritage", "temple"], "is_favorite": false }

// route_search
{ "source_id": 12, "destination_id": 45, "results_count": 3 }

// event_view
{ "event_type": "Cultural", "is_free": true, "is_online": false }

// favourite_toggle
{ "action": "add" }   or   { "action": "remove" }
```

### Run migration

```bash
php artisan migrate
```

---

## 5. Frontend — Integration Guide

### What changes for frontend?

**Nothing is mandatory.** Every API call is already tracked automatically.

The only optional enhancement is sending one header to improve platform detection accuracy.

### Recommended: Send `X-App-Source` header

```
X-App-Source: mobile    ← React Native app
X-App-Source: web       ← Next.js web frontend
X-App-Source: admin     ← Admin panel
```

Without this header, the backend guesses platform from the `User-Agent` (OkHttp → mobile, admin path → admin, else web). The header makes it explicit and accurate.

#### Next.js — Add to API client

```ts
// src/lib/api.ts  (or wherever you configure your fetch/axios)
const headers: HeadersInit = {
  'Content-Type': 'application/json',
  'X-App-Source': 'web',
};

if (token) {
  headers['Authorization'] = `Bearer ${token}`;
}
```

#### React Native — Add to Axios instance

```ts
// src/Services/api.ts
import axios from 'axios';

const api = axios.create({
  baseURL: process.env.API_PATH,
  headers: {
    'X-App-Source': 'mobile',
  },
});

api.interceptors.request.use((config) => {
  const token = store.getState().auth.token;
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export default api;
```

#### Admin Panel — Add to Axios instance

```js
// src/services/axiosInstance.js
import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_BACKEND_URL,
  headers: {
    'X-App-Source': 'admin',
  },
});
```

### What gets tracked automatically (no frontend work needed)

| Action | Tracked as |
|---|---|
| User logs in | `login` — platform, IP, timestamp |
| User logs out | `logout` |
| User views a site | `site_view` — site name, categories, is_favorite |
| User views an event | `event_view` — event title, type |
| User adds/removes favourite | `favourite_toggle` — entity type + action |
| User searches routes | `route_search` — source/destination IDs, result count |
| User creates event | `event_create` |
| User browses landing page | `landing_page` |
| Any other API call | `api_call` |

### Nothing is blocked if logging fails

The job runs on a separate queue worker. If the queue is down or the job fails after 2 retries, the API response is completely unaffected. The user never knows.

---

## 6. Admin API Reference

**Base URL:** `https://api.tourkokan.com/admin/v2`  
**Auth:** `Authorization: Bearer <admin_token>` on all requests.

---

### `POST /analytics/dashboardStats`

Dashboard summary — call this on admin panel home page.

**Request:** _(no body required)_

**Response:**
```json
{
  "success": true,
  "data": {
    "total_logins_today": 142,
    "total_api_calls_today": 5890,
    "total_site_views_today": 780,
    "total_event_views_today": 210,
    "top_event_type_today": "site_view",
    "avg_response_time_ms": 145,
    "most_active_user_today": {
      "id": 23,
      "name": "Ravi K.",
      "count": 48
    }
  }
}
```

```bash
curl -X POST https://api.tourkokan.com/admin/v2/analytics/dashboardStats \
  -H "Authorization: Bearer TOKEN"
```

---

### `POST /analytics/activityLogs`

Raw paginated log list. Use for full audit trail.

**Request body (all optional):**
```json
{
  "per_page": 50,
  "user_id": 12,
  "event_type": "site_view",
  "entity_type": "site",
  "entity_id": 5,
  "platform": "mobile",
  "success": true,
  "date_from": "2026-06-01",
  "date_to": "2026-06-05",
  "ip_address": "103.21.0.1",
  "search": "getSite"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 1001,
        "user_id": 12,
        "event_type": "site_view",
        "entity_type": "site",
        "entity_id": 5,
        "entity_name": "Ajanta Caves",
        "route": "/api/v2/getSite",
        "method": "POST",
        "ip_address": "103.21.0.1",
        "platform": "mobile",
        "app_version": "2.1.0",
        "success": true,
        "response_time_ms": 128,
        "meta_data": { "categories": ["heritage"], "is_favorite": false },
        "created_at": "2026-06-05T10:32:11.000000Z",
        "user": { "id": 12, "name": "Priya S.", "mobile": "9876543210" }
      }
    ],
    "current_page": 1,
    "total": 4210,
    "per_page": 50
  }
}
```

**All filter params:**

| Param | Type | Description |
|---|---|---|
| `per_page` | integer 1–200 | Default 50 |
| `user_id` | integer | Filter by specific user |
| `event_type` | string | e.g. `site_view`, `login` |
| `entity_type` | string | `site`, `event`, `route`, `category` |
| `entity_id` | integer | Specific entity |
| `platform` | string | `mobile`, `web`, `admin` |
| `success` | boolean | Filter successes / failures |
| `date_from` | date | `YYYY-MM-DD` |
| `date_to` | date | `YYYY-MM-DD` |
| `ip_address` | string | Exact IP match |
| `search` | string | Searches route + entity_name |

---

### `POST /analytics/userTimeline`

All activity for one user in chronological order. Use in user detail page.

**Request body:**
```json
{
  "user_id": 12,
  "per_page": 50,
  "date_from": "2026-06-01",
  "date_to": "2026-06-05"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "user": {
      "id": 12,
      "name": "Priya S.",
      "mobile": "9876543210",
      "email": "priya@example.com",
      "created_at": "2026-01-10T00:00:00Z",
      "roles": [{ "id": 1, "name": "User", "code": "user" }]
    },
    "logs": {
      "data": [...],
      "total": 182,
      "per_page": 50
    }
  }
}
```

```bash
curl -X POST .../admin/v2/analytics/userTimeline \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"user_id": 12}'
```

---

### `POST /analytics/activeUsers`

DAU / WAU / MAU counts.

**Request:** _(no body required)_

**Response:**
```json
{
  "success": true,
  "data": {
    "dau": 142,
    "wau": 891,
    "mau": 3204,
    "new_users_today": 18,
    "avg_events_per_user_today": 6.4
  }
}
```

---

### `POST /analytics/topSites`

Most visited sites. Use for trending/hot places widget in admin.

**Request body (all optional):**
```json
{
  "limit": 20,
  "date_from": "2026-06-01",
  "date_to": "2026-06-05",
  "platform": "mobile"
}
```

**Response:**
```json
{
  "success": true,
  "data": [
    { "entity_id": 5, "entity_name": "Ajanta Caves", "view_count": 420, "unique_users": 310 },
    { "entity_id": 12, "entity_name": "Ellora Caves", "view_count": 389, "unique_users": 280 }
  ]
}
```

---

### `POST /analytics/topEvents`

Most viewed events.

**Request body:** Same as `topSites` (limit, date_from, date_to, platform)

**Response:** Same shape as `topSites` but for events.

---

### `POST /analytics/topRoutes`

Most searched source → destination pairs.

**Request body:** `limit`, `date_from`, `date_to`

**Response:**
```json
{
  "success": true,
  "data": [
    { "source_id": "12", "destination_id": "45", "search_count": 89, "unique_users": 71 },
    { "source_id": "8",  "destination_id": "22", "search_count": 64, "unique_users": 55 }
  ]
}
```

---

### `POST /analytics/userInterests`

Category distribution — what types of sites users view most. Use for recommendation signals.

**Request body (all optional):**
```json
{
  "user_id": 12,
  "date_from": "2026-06-01",
  "date_to": "2026-06-05"
}
```

Without `user_id` → returns global interest distribution across all users.

**Response:**
```json
{
  "success": true,
  "data": [
    { "category_id": 3, "category_name": "Heritage", "visit_count": 1240, "unique_users": 890 },
    { "category_id": 7, "category_name": "Temple",   "visit_count": 980,  "unique_users": 740 }
  ]
}
```

---

### `POST /analytics/loginHistory`

Login / logout / register events with IP and platform. Use for security audit or user session history.

**Request body (all optional):**
```json
{
  "user_id": 12,
  "platform": "mobile",
  "date_from": "2026-06-01",
  "date_to": "2026-06-05",
  "per_page": 50
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 900,
        "event_type": "login",
        "ip_address": "103.21.0.1",
        "platform": "mobile",
        "app_version": "2.1.0",
        "created_at": "2026-06-05T08:10:00Z",
        "user": { "id": 12, "name": "Priya S.", "mobile": "9876543210" }
      }
    ],
    "total": 28
  }
}
```

---

### `POST /analytics/eventTypeSummary`

Count per event_type. Use for bar chart on analytics dashboard.

**Request body (all optional):** `date_from`, `date_to`, `platform`

**Response:**
```json
{
  "success": true,
  "data": [
    { "event_type": "site_view",  "count": 4200 },
    { "event_type": "api_call",   "count": 3100 },
    { "event_type": "login",      "count": 1800 },
    { "event_type": "event_view", "count": 1200 }
  ]
}
```

---

### `POST /analytics/platformBreakdown`

mobile vs web vs admin split. Use for pie chart.

**Request body (all optional):** `date_from`, `date_to`

**Response:**
```json
{
  "success": true,
  "data": [
    { "platform": "mobile", "count": 8900, "unique_users": 1200 },
    { "platform": "web",    "count": 4200, "unique_users": 890  },
    { "platform": "admin",  "count": 320,  "unique_users": 12   }
  ]
}
```

---

### `POST /analytics/favouriteActivity`

All favourite add/remove events with entity context.

**Request body (all optional):**
```json
{
  "user_id": 12,
  "entity_type": "site",
  "date_from": "2026-06-01",
  "date_to": "2026-06-05",
  "per_page": 50
}
```

**Response:** Paginated logs filtered to `favourite_toggle` event type.

---

## 7. Queue Setup

### Development (default sync — no changes needed)

`QUEUE_CONNECTION=sync` in `.env` means the job runs synchronously, inline, still after `terminate()`. Works fine for development.

### Production (recommended: database queue)

**Step 1 — Switch driver:**
```bash
# .env
QUEUE_CONNECTION=database
```

**Step 2 — Create jobs table (one time):**
```bash
php artisan queue:table
php artisan migrate
```

**Step 3 — Start worker:**
```bash
php artisan queue:work --queue=analytics --tries=2 --timeout=10 --sleep=3
```

**Step 4 — Supervisor config (VPS):**
```ini
[program:tourkokan-analytics]
command=php /var/www/tourkokan-backend/artisan queue:work --queue=analytics --tries=2 --sleep=3
directory=/var/www/tourkokan-backend
autostart=true
autorestart=true
numprocs=1
user=www-data
stderr_logfile=/var/log/supervisor/analytics-worker.err.log
stdout_logfile=/var/log/supervisor/analytics-worker.out.log
```

**Step 5 — Reload supervisor:**
```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start tourkokan-analytics
```

---

## 8. Data Retention

The table grows fast. Add cleanup in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;
use App\Models\UserActivityLog;

Schedule::call(function () {
    // Keep last 6 months of raw logs
    UserActivityLog::where('created_at', '<', now()->subMonths(6))->delete();
})->monthly();
```

Run the scheduler (add to server crontab):
```
* * * * * cd /var/www/tourkokan-backend && php artisan schedule:run >> /dev/null 2>&1
```

---

## Appendix — Admin Panel Page Suggestions

| Admin Panel Page | APIs to call |
|---|---|
| Dashboard home | `dashboardStats` + `activeUsers` |
| Analytics overview | `eventTypeSummary` + `platformBreakdown` |
| Trending content | `topSites` + `topEvents` + `topRoutes` |
| User detail page | `userTimeline` + `userInterests` (with user_id) |
| User list page | `activityLogs` (filtered by user_id) |
| Security audit | `loginHistory` |
| Content team | `topSites` + `userInterests` (for editorial signals) |
