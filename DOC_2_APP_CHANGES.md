# DOC 2 — App Changes & API Integration

> **Audience:** Mobile app developer (React Native / Flutter)
> **Scope:** New features to build in the app and all API calls with request/response format.
> **Base URL:** Replace `{{API_BASE_URL}}` with the actual server base URL.
> **Auth:** All endpoints (except login/register) require `Authorization: Bearer USER_TOKEN` header.

---

## Feature 1 — Comment System

### Behaviour
- User submits a comment → backend marks it `status: false` (pending review).
- Show user a message: *"Your comment has been submitted and is under review."*
- Public listing only shows approved comments (`status: true`) — handled by backend, no filter needed.

### 1.1 Submit a Comment

```bash
curl -X POST {{API_BASE_URL}}/api/v2/comment \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "commentable_type": "App\\Models\\Site",
    "commentable_id": 5,
    "comment": "Amazing place, very clean and peaceful!"
  }'
```

**Success Response:**
```json
{
  "success": true,
  "data": {
    "id": 42,
    "comment": "Amazing place, very clean and peaceful!",
    "status": false,
    "pending": true
  },
  "message": "Comment submitted and awaiting approval."
}
```

> Show a toast/snackbar: *"Comment submitted and awaiting approval."*

### 1.2 List Approved Comments for a Place

```bash
curl -X POST {{API_BASE_URL}}/api/v2/comments \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "commentable_type": "App\\Models\\Site",
    "commentable_id": 5,
    "per_page": 15
  }'
```

### 1.3 Reply to a Comment (nested)

```bash
curl -X POST {{API_BASE_URL}}/api/v2/comment \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "commentable_type": "App\\Models\\Site",
    "commentable_id": 5,
    "parent_id": 42,
    "comment": "Totally agree, loved it too!"
  }'
```

### 1.4 Update Own Comment

```bash
curl -X POST {{API_BASE_URL}}/api/v2/updateComment \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "id": 42,
    "comment": "Updated comment text here."
  }'
```

### 1.5 Delete Own Comment

```bash
curl -X POST {{API_BASE_URL}}/api/v2/deleteComment \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": 42}'
```

---

## Feature 2 — Admin Direct Messages (Inbox)

### Behaviour
- Admin sends a personal message to a specific user.
- User sees an inbox in the app with unread badge count.
- Tapping a message marks it as read.

### UI Recommendations
- Add an inbox/notification bell icon in the app header or profile screen.
- Show unread count badge on the icon.
- Inbox list shows subject + preview + date + read/unread state.

### 2.1 Get Unread Message Count (for badge)

Call on app launch and after login.

```bash
curl -X POST {{API_BASE_URL}}/api/v2/unreadMessageCount \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json"
```

**Response:**
```json
{
  "success": true,
  "data": { "unread_count": 3 },
  "message": "Unread count fetched."
}
```

### 2.2 Get Inbox (list all messages)

```bash
curl -X POST {{API_BASE_URL}}/api/v2/myMessages \
  -H "Authorization: Bearer USER_TOKEN" \
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
        "id": 1,
        "subject": "Your submission has been reviewed",
        "message": "Hi, your place Hotel Sagar has been approved and is now live.",
        "is_read": false,
        "read_at": null,
        "created_at": "2026-04-26T10:00:00Z",
        "admin": { "id": 2, "name": "Admin" }
      }
    ]
  }
}
```

### 2.3 Mark Message as Read

Call when user opens a message.

```bash
curl -X POST {{API_BASE_URL}}/api/v2/readMessage \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": 1}'
```

---

## Feature 3 — Site Onboarding (Submit Your Place)

### Behaviour
- Registered + logged-in users can submit their hotel, restaurant, homestay, etc.
- Submission goes to admin for review (`pending` state).
- User is notified of approval/rejection via Admin Direct Message (Feature 2).
- On rejection, user sees the rejection reason and can edit and resubmit.

> **Verification Note:** Mobile number and email verification before submission will be added in a future app update (profile update flow / OTP verification). For now, any logged-in user can submit.

### Flow

```
User taps "Add Your Place"
  → Step 1: Basic Info (name, description, tag_line, category)
  → Step 2: Location (map picker OR paste Google Maps URL)
  → Step 3: Photos (image, logo)
  → Step 4: Details (website, pin_code, social_media)
  → Submit → "Under Review" screen
```

### 3.1 Parse Google Maps URL (extract lat/lng — free, no API key)

User can paste a Google Maps link instead of using the map picker.

```bash
curl -X POST {{API_BASE_URL}}/api/v2/parseMapUrl \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://maps.app.goo.gl/abc123xyz"}'
```

**Success Response:**
```json
{
  "success": true,
  "data": {
    "latitude": 16.0601,
    "longitude": 73.4677
  },
  "message": "Coordinates extracted successfully."
}
```

**Error Response (URL format not recognised):**
```json
{
  "success": false,
  "message": "Could not extract coordinates from this URL. Please use the map picker or enter manually."
}
```

> Supported URL formats: `goo.gl`, `maps.app.goo.gl`, full Google Maps URLs with `/@lat,lng` or `?q=lat,lng`.

### 3.2 Submit a New Place

`latitude` and `longitude` are **required**.

```bash
curl -X POST {{API_BASE_URL}}/api/v2/addSite \
  -H "Authorization: Bearer USER_TOKEN" \
  -F "name=Hotel Sagar" \
  -F "description=A beautiful hotel on the Malvan coast with private sea view rooms and local cuisine." \
  -F "tag_line=Stay by the sea" \
  -F "latitude=16.0601" \
  -F "longitude=73.4677" \
  -F "parent_id=5" \
  -F "categories[]=3" \
  -F "domain_name=https://hotelsagar.com" \
  -F "pin_code=416606" \
  -F "image=@/path/to/image.jpg" \
  -F "logo=@/path/to/logo.jpg"
```

**Request Fields:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | Yes | 2–100 chars |
| `description` | string | Yes | Min 20 chars |
| `latitude` | numeric | Yes | -90 to 90 |
| `longitude` | numeric | Yes | -180 to 180 |
| `categories` | array | Yes | Category IDs from `listcategories` |
| `tag_line` | string | No | Max 100 chars |
| `parent_id` | integer | No | Parent city/taluka site ID |
| `domain_name` | url | No | Website URL |
| `pin_code` | string | No | 6 digits |
| `image` | file | No | jpeg/jpg/png, max 2MB |
| `logo` | file | No | jpeg/jpg/png, max 1MB |
| `social_media` | JSON string | No | e.g. `{"instagram":"url","facebook":"url"}` |
| `speciality` | JSON string | No | e.g. `["Sea food","Bonfire","Water sports"]` |
| `rules` | JSON string | No | e.g. `["No smoking","Check-in 12pm"]` |

**Success Response:**
```json
{
  "success": true,
  "data": {
    "id": 55,
    "name": "Hotel Sagar",
    "submission_status": "pending",
    "status": false,
    "categories": [{ "id": 3, "name": "Hotel", "code": "hotel" }]
  },
  "message": "Your place has been submitted and is under review. We will notify you once approved."
}
```

**Uniqueness errors:**
- Same user submitting same name at same coordinates → `422` with validation error on `name`.

### 3.3 List My Submissions

```bash
curl -X POST {{API_BASE_URL}}/api/v2/mySubmissions \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"per_page": 15}'
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
        "image": "storage/sites/image.jpg",
        "status": false,
        "submission_status": "pending",
        "rejection_reason": null,
        "created_at": "2026-04-26T09:00:00Z"
      },
      {
        "id": 48,
        "name": "Sagar Homestay",
        "submission_status": "rejected",
        "rejection_reason": "Incomplete information. Please add a proper description and at least one photo.",
        "status": false
      }
    ]
  }
}
```

**Submission Status UI:**

| `submission_status` | UI Label | Color |
|---|---|---|
| `pending` | Under Review | Orange |
| `approved` | Live | Green |
| `rejected` | Rejected — tap to edit | Red |

### 3.4 Update a Pending or Rejected Submission

Only `pending` or `rejected` submissions can be edited. Approved (live) submissions cannot be edited by the user.

```bash
curl -X POST {{API_BASE_URL}}/api/v2/updateMySubmission \
  -H "Authorization: Bearer USER_TOKEN" \
  -F "id=48" \
  -F "description=Updated: A cozy homestay with traditional Malvani food and beach access. Book in advance." \
  -F "image=@/path/to/new_image.jpg"
```

> When a `rejected` submission is updated, its status resets to `pending` automatically. The rejection reason is cleared.

### 3.5 Delete a Pending or Rejected Submission

Approved (live) submissions cannot be deleted by the user.

```bash
curl -X POST {{API_BASE_URL}}/api/v2/deleteMySubmission \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": 48}'
```

---

## Feature 4 — Events

### 4.1 List Events

```bash
curl -X POST {{API_BASE_URL}}/api/v2/listEvents \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"per_page": 15}'
```

### 4.2 Create Event

```bash
curl -X POST {{API_BASE_URL}}/api/v2/createEvent \
  -H "Authorization: Bearer USER_TOKEN" \
  -F "title=Malvan Beach Festival" \
  -F "description=Annual beach festival with music, food and cultural programs." \
  -F "start_date=2026-05-15" \
  -F "end_date=2026-05-17" \
  -F "site_id=5" \
  -F "image=@/path/to/event.jpg"
```

### 4.3 My Events

```bash
curl -X POST {{API_BASE_URL}}/api/v2/myEvents \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json"
```

### 4.4 Event Interactions

```bash
# Like
curl -X POST {{API_BASE_URL}}/api/v2/likeEvent \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"event_id": 10}'

# Going
curl -X POST {{API_BASE_URL}}/api/v2/goingEvent \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"event_id": 10}'

# Interested
curl -X POST {{API_BASE_URL}}/api/v2/interestedEvent \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"event_id": 10}'
```

---

## Feature 5 — Notifications (Push Token)

Register device token after login so the user receives push notifications.

```bash
# Register token after login
curl -X POST {{API_BASE_URL}}/api/v2/registerPushToken \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "token": "fcm_or_apns_device_token_here",
    "platform": "android"
  }'

# Unregister on logout
curl -X POST {{API_BASE_URL}}/api/v2/unregisterPushToken \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"token": "fcm_or_apns_device_token_here"}'
```

---

## Auth Endpoints (for reference)

```bash
# Register
curl -X POST {{API_BASE_URL}}/api/v2/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Rahul Patil","email":"rahul@example.com","password":"secret123","phone":"9876543210"}'

# Login
curl -X POST {{API_BASE_URL}}/api/v2/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"rahul@example.com","password":"secret123"}'

# Refresh token
curl -X POST {{API_BASE_URL}}/api/v2/auth/refresh \
  -H "Authorization: Bearer USER_TOKEN"

# Logout
curl -X POST {{API_BASE_URL}}/api/v2/logout \
  -H "Authorization: Bearer USER_TOKEN"
```

---

## Error Handling Reference

| HTTP Code | Meaning | App Action |
|---|---|---|
| `401` | Unauthenticated / token expired | Redirect to login |
| `422` | Validation error | Show field errors |
| `404` | Record not found | Show "not found" message |
| `200` with `success: false` | Business logic error | Show `message` to user |
| `200` with `success: true` | Success | Proceed normally |
