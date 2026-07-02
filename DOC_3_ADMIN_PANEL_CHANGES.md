# DOC 3 — Admin Panel API Integration

> **Audience:** Frontend developer working on the Tourkokan Admin Panel
> **Base URL:** Replace `{{ADMIN_BASE_URL}}` with actual server URL (admin routes live under `/admin/v2/`)
> **Auth:** All endpoints require `Authorization: Bearer ADMIN_TOKEN` header.
> **Note:** Admin tokens are obtained via `POST /admin/v2/auth/login`

---

## Auth

```bash
# Admin Login
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "admin@tourkokan.com", "password": "secret"}'

# Response
{
  "access_token": "eyJ...",
  "token_type": "bearer",
  "expires_in": 3600
}
```

---

## Module 1 — Site Management (Direct Admin CRUD)

> These are admin-created sites — public places (no `user_id`) or business listings added on behalf of owners.

### 1.1 List Sites

```bash
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/sites \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "apitype": "list",
    "per_page": 20,
    "category": "hotel",
    "parent_id": 5
  }'
```

**`apitype` values:** `list` (full data) | `dropdown` (id + name only)

### 1.2 Get Single Site

```bash
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/getSite \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": 5}'
```

### 1.3 Add Site

- `user_id` is **optional**. Omit for public places (temple, village, fort). Include for business owner's listing.
- Uniqueness: if `user_id` provided → `user_id + name + lat/long` must be unique. If no `user_id` → `name + parent_id` must be unique.

```bash
# Public place (no user_id — temple, village, fort)
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/addSite \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -F "name=Sindhudurg Fort" \
  -F "tag_line=17th century sea fort" \
  -F "description=Historic sea fort built by Chhatrapati Shivaji Maharaj in 1667." \
  -F "categories[]=1" \
  -F "parent_id=5" \
  -F "latitude=16.0494" \
  -F "longitude=73.5054" \
  -F "status=1" \
  -F "image=@/path/to/fort.jpg"

# Business listing (with user_id)
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/addSite \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -F "name=Hotel Sea View" \
  -F "tag_line=Premium sea-facing stay" \
  -F "description=Best hotel in Malvan with private beach access and Malvani cuisine." \
  -F "categories[]=3" \
  -F "user_id=42" \
  -F "parent_id=5" \
  -F "latitude=16.0612" \
  -F "longitude=73.4698" \
  -F "status=1"
```

**Request Fields:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | Yes | 2–100 chars |
| `tag_line` | string | Yes | 2–100 chars |
| `description` | string | Yes | |
| `categories` | array | Yes | Category IDs |
| `user_id` | integer | No | Omit for public places |
| `parent_id` | integer | No | Parent city/taluka ID |
| `latitude` | numeric | No | |
| `longitude` | numeric | No | Required if latitude given |
| `status` | boolean | No | `1` = live, `0` = hidden |
| `bus_stop_type` | string | No | `Stop` or `Depo` |
| `logo` | file | No | max 1MB |
| `icon` | file | No | max 512KB |
| `image` | file | No | max 1MB |
| `pin_code` | numeric | No | |
| `speciality` | JSON string | No | |
| `rules` | JSON string | No | |
| `social_media` | JSON string | No | |
| `meta_data` | JSON string | No | |

### 1.4 Update Site

```bash
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/updateSite \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "id": 5,
    "name": "Sindhudurg Fort Updated",
    "status": true,
    "is_hot_place": true
  }'
```

### 1.5 Delete Site

```bash
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/deleteSite \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": 5}'
```

---

## Module 2 — Site Submission Review (User Onboarding)

> Users submit places via the app. Admin reviews them here — approve to make live, or reject with a reason.

### UI Recommendation
- Add a **"Pending Submissions"** badge/count in the admin sidebar.
- Submission detail page shows: name, description, coordinates (map preview), photos, submitted by (user name + email), submitted date.
- Action buttons: **Approve** | **Reject (with reason)**

### 2.1 List Pending Submissions

```bash
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/pendingSites \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"per_page": 20}'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 55,
        "name": "Hotel Sagar",
        "description": "A beautiful hotel...",
        "latitude": 16.0601,
        "longitude": 73.4677,
        "submission_status": "pending",
        "created_at": "2026-04-26T09:00:00Z",
        "user": { "id": 12, "name": "Rahul Patil", "email": "rahul@example.com" },
        "categories": [{ "id": 3, "name": "Hotel", "code": "hotel" }]
      }
    ],
    "total": 8,
    "per_page": 20
  }
}
```

### 2.2 List All Submissions (with filter)

```bash
# All submissions
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/allSubmissions \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"per_page": 20}'

# Filter by status
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/allSubmissions \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"submission_status": "rejected", "per_page": 20}'

# Search by name
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/allSubmissions \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"search": "Hotel", "per_page": 20}'
```

**`submission_status` filter values:** `pending` | `approved` | `rejected`

### 2.3 Approve a Submission

Makes the site live (`status = true`, `submission_status = approved`).

```bash
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/approveSite \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": 55}'
```

> After approving, send the user a direct message (Module 4) notifying them it's live.

### 2.4 Reject a Submission

```bash
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/rejectSite \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "id": 55,
    "rejection_reason": "Incomplete information. Please add a proper description (min 50 words) and at least one clear photo of the place."
  }'
```

> After rejecting, send the user a direct message (Module 4) with the reason so they can fix and resubmit.

---

## Module 3 — Comment Moderation

> All user-submitted comments start as `status: false` (pending). Admin approves or rejects them here. Approved comments appear publicly on the app.

### UI Recommendation
- Add **"Pending Comments"** badge in admin sidebar.
- Show comment text, which place it's about (commentable), and who submitted it.
- Action buttons: **Approve** | **Reject (Delete)**

### 3.1 List Pending Comments

```bash
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/pendingComments \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"per_page": 20}'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 42,
        "comment": "Amazing place, very clean!",
        "status": false,
        "created_at": "2026-04-26T10:00:00Z",
        "users": { "id": 12, "name": "Rahul Patil", "email": "rahul@example.com" },
        "commentable_type": "App\\Models\\Site",
        "commentable_id": 5
      }
    ]
  }
}
```

### 3.2 List All Comments (with filter)

```bash
# All comments
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/listComments \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"per_page": 20}'

# Filter pending only
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/listComments \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status": false, "per_page": 20}'

# Filter by place
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/listComments \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"commentable_type": "App\\Models\\Site", "commentable_id": 5}'
```

### 3.3 Approve a Comment

```bash
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/approveComment \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": 42}'
```

### 3.4 Reject (Delete) a Comment

```bash
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/rejectComment \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": 42}'
```

---

## Module 4 — Direct Messaging to Users

> Admin sends personal messages to specific users. Users receive and read them in the app inbox.

### UI Recommendation
- Add a **"Messages"** section in the admin panel.
- "Send Message" form: select user (from user list), enter subject + message body.
- Message list with user name, subject, sent date, whether user has read it.

### 4.1 Send a Message to a User

```bash
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/sendMessage \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 12,
    "subject": "Your place submission has been approved!",
    "message": "Hi Rahul, we are happy to inform you that your place Hotel Sagar is now live on Tourkokan. Users can discover it on the app!"
  }'
```

**Request Fields:**

| Field | Type | Required |
|---|---|---|
| `user_id` | integer | Yes |
| `message` | string | Yes |
| `subject` | string | No |

### 4.2 List All Sent Messages

```bash
# All messages
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/listMessages \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"per_page": 20}'

# Filter by user
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/listMessages \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"user_id": 12}'
```

### 4.3 Delete a Message

```bash
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/deleteMessage \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": 1}'
```

---

## Module 5 — Event Management

### 5.1 List All Events

```bash
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/listEvents \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"per_page": 20}'
```

### 5.2 List Pending Events (awaiting approval)

```bash
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/pendingEvents \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"per_page": 20}'
```

### 5.3 Approve an Event

```bash
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/approveEvent \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": 10}'
```

### 5.4 Reject an Event

```bash
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/rejectEvent \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": 10, "reason": "Event details are incomplete."}'
```

### 5.5 Feature an Event (pin to top)

```bash
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/featureEvent \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": 10}'
```

### 5.6 Event Analytics

```bash
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/eventAnalytics \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": 10}'
```

---

## Module 6 — Category Management

```bash
# List categories
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/listcategories \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"per_page": 50}'

# Add category
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/addCategory \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name": "Homestay", "code": "homestay", "parent_id": null}'

# Update category
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/updateCategory \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": 5, "name": "Homestay Updated"}'

# Delete category
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/deleteCategory \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": 5}'
```

---

## Module 7 — User Management

```bash
# List all users
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/allUsers \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"per_page": 20}'
```

---

## Module 8 — Gallery Management

```bash
# Get gallery for a site
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/getGallery \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"galleryable_type": "App\\Models\\Site", "galleryable_id": 5}'

# Update gallery
curl -X POST {{ADMIN_BASE_URL}}/admin/v2/updateGallery \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -F "galleryable_type=App\Models\Site" \
  -F "galleryable_id=5" \
  -F "images[]=@/path/to/photo1.jpg" \
  -F "images[]=@/path/to/photo2.jpg"
```

---

## Recommended Admin Sidebar Structure

```
Dashboard
├── Sites
│   ├── All Sites          ← Module 1 (list/add/edit/delete)
│   └── Pending Submissions ← Module 2 (review user-submitted places)  [badge]
├── Events
│   ├── All Events         ← Module 5
│   └── Pending Events     ← Module 5  [badge]
├── Comments
│   ├── All Comments       ← Module 3
│   └── Pending Comments   ← Module 3  [badge]
├── Messages
│   └── Send / Manage      ← Module 4
├── Categories             ← Module 6
├── Users                  ← Module 7
└── Gallery                ← Module 8
```

---

## Error Handling Reference

| HTTP Code | Meaning |
|---|---|
| `401` | Token expired or missing — redirect to admin login |
| `422` | Validation error — show field-level errors |
| `404` | Record not found |
| `200` with `success: false` | Business logic error — show `message` |
| `200` with `success: true` | Success |
