# Site Onboarding — Backend Implementation

> Feature: Allow users to submit their hotel, restaurant, homestay, tour package, or any business listing via the Tourkokan app. Admin reviews and approves before it goes live.

---

## Setup

```bash
php artisan migrate
```

One migration will run:

| Migration | Effect |
|---|---|
| `2026_04_26_000001_add_submission_status_to_sites_table.php` | Adds `submission_status` and `rejection_reason` to `sites` |

---

## Design Decisions

| Decision | Choice | Reason |
|---|---|---|
| Google Places API | **Not used** | Cost; not needed |
| Coordinate method | Map picker + URL parser + manual | Free, multiple fallbacks |
| Website flow | Download App popup only | Website is guest-only, no form |
| Admin approval gate | Yes | Prevents spam, same as events/comments |
| User can edit/delete own submission | Yes, only when pending or rejected | Standard ownership |
| Existing admin `addSite` | **Unchanged** | Admin-created sites bypass review |

---

## Database Changes

### `sites` table — new columns

| Column | Type | Default | Notes |
|---|---|---|---|
| `submission_status` | ENUM `pending,approved,rejected` | `approved` | Default `approved` preserves existing admin-created sites |
| `rejection_reason` | TEXT | NULL | Set by admin when rejecting; cleared on resubmission |

### Submission State Machine

```
User submits
     ↓
submission_status = pending   (status = false — not visible publicly)
     ↓
Admin reviews in panel
     ↓
 ┌─ APPROVE ─→ submission_status = approved, status = true   (site goes live)
 └─ REJECT  ─→ submission_status = rejected, rejection_reason saved
                    ↓
              User sees reason in mySubmissions
                    ↓
              User fixes and calls updateMySubmission
                    ↓
              submission_status resets to pending, rejection_reason cleared
```

---

## Files Changed / Created

| File | Action |
|---|---|
| `database/migrations/2026_04_26_000001_add_submission_status_to_sites_table.php` | New |
| `app/Models/Site.php` | Added `submission_status`, `rejection_reason` to `$fillable` |
| `app/Http/Controllers/User/V2/OnboardingController.php` | New |
| `app/Http/Controllers/Admin/V2/OnboardingController.php` | New |
| `routes/api.php` | Added all new routes |

> **Existing admin `Admin/V2/SiteController` is completely untouched.**

---

## API Reference

### User Endpoints (Bearer token required)

| Method | URL | Purpose |
|---|---|---|
| POST | `/api/v2/parseMapUrl` | Parse Google Maps URL → return lat/lng |
| POST | `/api/v2/addSite` | Submit new place (pending, not live) |
| POST | `/api/v2/mySubmissions` | List user's own submissions with status |
| POST | `/api/v2/updateMySubmission` | Edit a pending or rejected submission |
| POST | `/api/v2/deleteMySubmission` | Delete own pending or rejected submission |

### Admin Endpoints (Bearer token required)

| Method | URL | Purpose |
|---|---|---|
| POST | `/api/v2/admin/pendingSites` | List all pending submissions |
| POST | `/api/v2/admin/allSubmissions` | List all with optional status filter |
| POST | `/api/v2/admin/approveSite` | Approve → site goes live |
| POST | `/api/v2/admin/rejectSite` | Reject with reason |

---

## Validation Rules

### `POST /api/v2/addSite`

| Field | Rule |
|---|---|
| `name` | required, string, 2–100 chars |
| `categories` | required, array, min 1 item, each ID must exist in categories |
| `parent_id` | nullable, must exist in sites (which city/taluka) |
| `description` | required, string, min 20 chars |
| `tag_line` | nullable, string, max 100 |
| `domain_name` | nullable, valid URL |
| `image` | nullable, jpeg/jpg/png, max 2MB |
| `logo` | nullable, jpeg/jpg/png, max 1MB |
| `latitude` | nullable, required_with:longitude, -90 to 90 |
| `longitude` | nullable, required_with:latitude, -180 to 180 |
| `pin_code` | nullable, exactly 6 digits |
| `social_media` | nullable, valid JSON string |
| `speciality` | nullable, valid JSON string |
| `rules` | nullable, valid JSON string |

### `POST /api/v2/admin/rejectSite`

| Field | Rule |
|---|---|
| `id` | required, exists in sites |
| `rejection_reason` | required, string, max 1000 chars |

---

## cURL Examples

### User Flow

#### 1 — Parse a Google Maps URL for coordinates

```bash
curl -X POST http://127.0.0.1:8000/api/v2/parseMapUrl \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "url": "https://www.google.com/maps/@16.0601,73.4662,15z" }'
```

**Response:**
```json
{
  "success": true,
  "data": { "latitude": 16.0601, "longitude": 73.4662 },
  "message": "Coordinates extracted successfully."
}
```

**Short URL (goo.gl redirect followed automatically):**
```bash
curl -X POST http://127.0.0.1:8000/api/v2/parseMapUrl \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "url": "https://maps.app.goo.gl/AbCdEfGh" }'
```

---

#### 2 — Submit a new place

```bash
curl -X POST http://127.0.0.1:8000/api/v2/addSite \
  -H "Authorization: Bearer USER_TOKEN" \
  -F "name=Malvan Beach Homestay" \
  -F "categories[]=3" \
  -F "categories[]=7" \
  -F "parent_id=12" \
  -F "description=A peaceful homestay near Sindhudurg fort with sea view rooms and home-cooked Malvani food." \
  -F "tag_line=Stay close to the sea" \
  -F "latitude=16.0601" \
  -F "longitude=73.4662" \
  -F "pin_code=416606" \
  -F 'social_media={"phone":"+919876543210","instagram":"malvanstay"}' \
  -F "image=@/path/to/cover.jpg" \
  -F "logo=@/path/to/logo.png"
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 88,
    "name": "Malvan Beach Homestay",
    "submission_status": "pending",
    "status": false,
    "categories": [...]
  },
  "message": "Your place has been submitted and is under review. We will notify you once approved."
}
```

---

#### 3 — View my submissions

```bash
curl -X POST http://127.0.0.1:8000/api/v2/mySubmissions \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "page": 1 }'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 88,
        "name": "Malvan Beach Homestay",
        "image": "sites/abc.jpg",
        "status": false,
        "submission_status": "pending",
        "rejection_reason": null,
        "created_at": "2026-04-26T10:00:00Z"
      }
    ],
    "total": 1
  }
}
```

---

#### 4 — Update a rejected submission (resubmit)

```bash
curl -X POST http://127.0.0.1:8000/api/v2/updateMySubmission \
  -H "Authorization: Bearer USER_TOKEN" \
  -F "id=88" \
  -F "description=A peaceful homestay near Sindhudurg fort with sea view rooms, home-cooked Malvani food, and boat trips arranged." \
  -F "image=@/path/to/new_cover.jpg"
```

**Response:**
```json
{
  "success": true,
  "data": { "id": 88, "submission_status": "pending", "rejection_reason": null },
  "message": "Submission updated. It is now under review again."
}
```

---

#### 5 — Delete a pending submission

```bash
curl -X POST http://127.0.0.1:8000/api/v2/deleteMySubmission \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "id": 88 }'
```

---

### Admin Flow

#### 6 — List pending submissions

```bash
curl -X POST http://127.0.0.1:8000/api/v2/admin/pendingSites \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "page": 1, "per_page": 20 }'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 88,
        "name": "Malvan Beach Homestay",
        "submission_status": "pending",
        "status": false,
        "created_at": "2026-04-26T10:00:00Z",
        "categories": [{ "id": 3, "name": "Homestay", "code": "homestay" }],
        "user": { "id": 7, "name": "Pranav", "email": "p@x.com" }
      }
    ],
    "total": 1
  }
}
```

---

#### 7 — List all submissions filtered by status

```bash
# All submissions
curl -X POST http://127.0.0.1:8000/api/v2/admin/allSubmissions \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "page": 1 }'

# Only rejected
curl -X POST http://127.0.0.1:8000/api/v2/admin/allSubmissions \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "submission_status": "rejected" }'

# Search by name
curl -X POST http://127.0.0.1:8000/api/v2/admin/allSubmissions \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "search": "malvan" }'
```

---

#### 8 — Approve a site

```bash
curl -X POST http://127.0.0.1:8000/api/v2/admin/approveSite \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "id": 88 }'
```

**Response:**
```json
{
  "success": true,
  "data": { "id": 88, "submission_status": "approved", "status": true },
  "message": "Site approved and is now live."
}
```

---

#### 9 — Reject a site with reason

```bash
curl -X POST http://127.0.0.1:8000/api/v2/admin/rejectSite \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "id": 88,
    "rejection_reason": "Please add at least one clear photo of the property and verify the pin code."
  }'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 88,
    "submission_status": "rejected",
    "rejection_reason": "Please add at least one clear photo..."
  },
  "message": "Site rejected. User has been notified of the reason."
}
```

---

## Google Maps URL — Supported Patterns

The `parseMapUrl` endpoint handles all these formats without any API key:

| URL Format | Example |
|---|---|
| Standard map view | `google.com/maps/@16.0601,73.4662,15z` |
| Place URL | `google.com/maps/place/Name/@16.0601,73.4662,17z` |
| Query URL | `google.com/maps?q=16.0601,73.4662` |
| Short URL (auto-redirect) | `maps.app.goo.gl/AbCdEfGh` |

If parsing fails, a clear error is returned and the app falls back to manual entry.

---

## Notes for Future Releases

- **User verification gate** — `isVerified` + mobile number check before allowing `addSite` will be added in a future app update when the Profile verification flow is implemented. The OTP infrastructure (`sendOtp`, `verifyOtp`) already exists in the backend. This note is intentional — verification is not blocking the onboarding launch.
- **Push notification on approve/reject** — Firebase push to user's device can be added to `approveSite` / `rejectSite` once the notification infrastructure is confirmed stable.
- **Gallery photos on submission** — Currently only `image` (cover) and `logo` are uploaded at submission time. Multi-image gallery upload can be added in a later step using the existing `Gallery` morphMany relation.
