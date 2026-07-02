# Comment Moderation & Admin Direct Messaging

## Overview

Two features added to the Tourkokan backend:

1. **Comment Moderation** — All user comments default to `status = false` (pending). Admin approves or rejects them. Only approved comments (`status = true`) appear publicly.
2. **Admin Direct Messaging** — Admin can send personal messages/notes to any specific user. Users receive them in an inbox and can mark them as read.

---

## Setup

```bash
php artisan migrate
```

Two migrations will run:

| Migration File | Effect |
|---|---|
| `2026_04_18_000001_add_status_to_comments_table.php` | Adds `status BOOLEAN DEFAULT false` to `comments` |
| `2026_04_18_000002_create_admin_messages_table.php` | Creates `admin_messages` table |

---

## Files Changed / Created

### Backend

| File | Type | Change |
|---|---|---|
| `database/migrations/2026_04_18_000001_add_status_to_comments_table.php` | New | Adds `status` column to comments |
| `database/migrations/2026_04_18_000002_create_admin_messages_table.php` | New | Creates admin_messages table |
| `app/Models/Comment.php` | Modified | Added `status` to `$fillable` and `$casts` |
| `app/Models/AdminMessage.php` | New | AdminMessage model with user/admin relations |
| `app/Http/Controllers/User/V2/CommentController.php` | Modified | Public listing filters `status=true`; store sets `status=false` |
| `app/Http/Controllers/Admin/V2/CommentController.php` | New | Admin pending / approve / reject |
| `app/Http/Controllers/Admin/V2/MessageController.php` | New | Admin send / list / delete messages |
| `app/Http/Controllers/User/V2/MessageController.php` | New | User inbox / mark read / unread count |
| `routes/api.php` | Modified | Added all new user and admin routes |

---

## Database Schema

### `comments` table (updated)

| Column | Type | Notes |
|---|---|---|
| `status` | boolean | `false` = pending, `true` = approved. Default `false` |

### `admin_messages` table (new)

| Column | Type | Notes |
|---|---|---|
| `id` | unsigned int | Primary key |
| `user_id` | unsigned int | FK → users.id (recipient) |
| `admin_id` | unsigned int | FK → users.id (sender) |
| `subject` | varchar | Optional subject line |
| `message` | text | Message body (max 2000 chars) |
| `is_read` | boolean | Default `false` |
| `read_at` | timestamp | Nullable, set when user reads |
| `created_at` | timestamp | — |
| `updated_at` | timestamp | — |

---

## API Reference

### User Endpoints

| Method | URL | Auth | Purpose |
|---|---|---|---|
| POST | `/api/v2/comment` | User token | Submit a comment (auto pending) |
| POST | `/api/v2/comments` | User token | List approved comments for a resource |
| POST | `/api/v2/myMessages` | User token | Get inbox |
| POST | `/api/v2/readMessage` | User token | Mark a message as read |
| POST | `/api/v2/unreadMessageCount` | User token | Get unread badge count |

### Admin Endpoints

| Method | URL | Auth | Purpose |
|---|---|---|---|
| POST | `/api/v2/admin/pendingComments` | Admin token | List unapproved comments |
| POST | `/api/v2/admin/listComments` | Admin token | List all comments (filterable by status) |
| POST | `/api/v2/admin/approveComment` | Admin token | Approve → sets `status = true` |
| POST | `/api/v2/admin/rejectComment` | Admin token | Reject → deletes the comment |
| POST | `/api/v2/admin/sendMessage` | Admin token | Send direct message to a user |
| POST | `/api/v2/admin/listMessages` | Admin token | List sent messages (filterable by user) |
| POST | `/api/v2/admin/deleteMessage` | Admin token | Delete a sent message |

---

## Feature 1: Comment Moderation — cURL Flow

### Step 1 — User posts a comment

```bash
curl -X POST http://127.0.0.1:8000/api/v2/comment \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "commentable_type": "Site",
    "commentable_id": 5,
    "comment": "Beautiful place!"
  }'
```

**Response:**
```json
{
  "success": true,
  "data": { "pending": true },
  "message": "Comment submitted and awaiting approval."
}
```

> Comment is saved with `status = false`. Not visible to anyone else yet.

---

### Step 2 — Public: list approved comments

```bash
curl -X POST http://127.0.0.1:8000/api/v2/comments \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "commentable_type": "Site",
    "commentable_id": 5,
    "per_page": 10
  }'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 38,
        "comment": "Amazing view!",
        "status": true,
        "created_at": "2026-04-25T09:00:00Z",
        "users": { "id": 3, "name": "Ravi", "email": "ravi@x.com" }
      }
    ],
    "total": 1,
    "current_page": 1,
    "last_page": 1,
    "per_page": 10
  }
}
```

> Only `status = true` comments are returned.

---

### Step 3 — Admin: list pending comments

```bash
curl -X POST http://127.0.0.1:8000/api/v2/admin/pendingComments \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "page": 1,
    "per_page": 20
  }'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 42,
        "comment": "Beautiful place!",
        "status": false,
        "created_at": "2026-04-25T10:00:00Z",
        "users": { "id": 7, "name": "Pranav", "email": "pranav@x.com" },
        "commentable": { "id": 5, "name": "Tarkarli Beach" }
      }
    ],
    "total": 1,
    "current_page": 1,
    "last_page": 1,
    "per_page": 20
  }
}
```

---

### Step 4 — Admin: list all comments with filters

```bash
# All comments (no filter)
curl -X POST http://127.0.0.1:8000/api/v2/admin/listComments \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "page": 1 }'

# Only approved comments
curl -X POST http://127.0.0.1:8000/api/v2/admin/listComments \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "status": true }'

# Only pending comments
curl -X POST http://127.0.0.1:8000/api/v2/admin/listComments \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "status": false }'

# Comments for a specific resource
curl -X POST http://127.0.0.1:8000/api/v2/admin/listComments \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "commentable_type": "Site",
    "commentable_id": 5
  }'
```

---

### Step 5 — Admin: approve a comment

```bash
curl -X POST http://127.0.0.1:8000/api/v2/admin/approveComment \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "id": 42 }'
```

**Response:**
```json
{
  "success": true,
  "data": null,
  "message": "Comment approved."
}
```

> Sets `status = true`. Comment now appears in public listing.

---

### Step 6 — Admin: reject a comment

```bash
curl -X POST http://127.0.0.1:8000/api/v2/admin/rejectComment \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "id": 42 }'
```

**Response:**
```json
{
  "success": true,
  "data": null,
  "message": "Comment rejected and deleted."
}
```

> Permanently deletes the comment.

---

## Feature 2: Admin Direct Messaging — cURL Flow

### Step 1 — Admin: send a message to a user

```bash
curl -X POST http://127.0.0.1:8000/api/v2/admin/sendMessage \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 7,
    "subject": "Welcome to Tourkokan!",
    "message": "Hi Pranav, thank you for joining. Let us know if you need help exploring Kokan."
  }'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "user_id": 7,
    "admin_id": 1,
    "subject": "Welcome to Tourkokan!",
    "message": "Hi Pranav, thank you for joining...",
    "is_read": false,
    "read_at": null,
    "created_at": "2026-04-25T10:30:00Z",
    "admin": { "id": 1, "name": "Admin" },
    "user": { "id": 7, "name": "Pranav", "email": "pranav@x.com" }
  },
  "message": "Message sent successfully."
}
```

> `subject` is optional. If omitted, message is sent without a subject line.

---

### Step 2 — Admin: list all messages (globally)

```bash
curl -X POST http://127.0.0.1:8000/api/v2/admin/listMessages \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "page": 1, "per_page": 20 }'
```

---

### Step 3 — Admin: list messages sent to a specific user

```bash
curl -X POST http://127.0.0.1:8000/api/v2/admin/listMessages \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "user_id": 7, "page": 1 }'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "subject": "Welcome to Tourkokan!",
        "message": "Hi Pranav...",
        "is_read": true,
        "read_at": "2026-04-25T11:00:00Z",
        "created_at": "2026-04-25T10:30:00Z",
        "user": { "id": 7, "name": "Pranav", "email": "pranav@x.com" },
        "admin": { "id": 1, "name": "Admin" }
      }
    ],
    "total": 1,
    "current_page": 1,
    "last_page": 1,
    "per_page": 20
  }
}
```

---

### Step 4 — Admin: delete a message

```bash
curl -X POST http://127.0.0.1:8000/api/v2/admin/deleteMessage \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "id": 1 }'
```

**Response:**
```json
{
  "success": true,
  "data": null,
  "message": "Message deleted."
}
```

---

### Step 5 — User: check unread message count (for badge/notification dot)

```bash
curl -X POST http://127.0.0.1:8000/api/v2/unreadMessageCount \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json"
```

**Response:**
```json
{
  "success": true,
  "data": { "count": 2 },
  "message": "Unread count fetched."
}
```

> Poll this on app launch to show a notification badge in the UI.

---

### Step 6 — User: open inbox

```bash
curl -X POST http://127.0.0.1:8000/api/v2/myMessages \
  -H "Authorization: Bearer USER_TOKEN" \
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
        "id": 1,
        "subject": "Welcome to Tourkokan!",
        "message": "Hi Pranav, thank you for joining...",
        "is_read": false,
        "read_at": null,
        "created_at": "2026-04-25T10:30:00Z",
        "admin": { "id": 1, "name": "Admin" }
      }
    ],
    "total": 1,
    "current_page": 1,
    "last_page": 1,
    "per_page": 20
  }
}
```

---

### Step 7 — User: mark a message as read

```bash
curl -X POST http://127.0.0.1:8000/api/v2/readMessage \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "id": 1 }'
```

**Response:**
```json
{
  "success": true,
  "data": null,
  "message": "Message marked as read."
}
```

> Sets `is_read = true` and records `read_at` timestamp. Only marks messages belonging to the authenticated user.

---

## Request / Response Field Reference

### Comment (public listing)

| Field | Type | Description |
|---|---|---|
| `id` | int | Comment ID |
| `comment` | string | Comment text |
| `status` | boolean | `true` = approved (always true in public listing) |
| `parent_id` | int\|null | Parent comment ID for replies |
| `created_at` | string | ISO timestamp |
| `users` | object | `{ id, name, email, profile_picture }` |
| `comments` | array | Nested replies (max 5) |

### AdminMessage

| Field | Type | Description |
|---|---|---|
| `id` | int | Message ID |
| `subject` | string\|null | Optional subject |
| `message` | string | Message body |
| `is_read` | boolean | Whether user has read it |
| `read_at` | string\|null | ISO timestamp when read |
| `created_at` | string | ISO timestamp when sent |
| `admin` | object | `{ id, name }` — sender |
| `user` | object | `{ id, name, email }` — recipient (admin view only) |

---

## Validation Rules

### POST `/api/v2/comment`

| Field | Rule |
|---|---|
| `commentable_type` | required, string (e.g. `"Site"`, `"Event"`) |
| `commentable_id` | required, numeric |
| `comment` | required, string |
| `parent_id` | optional, numeric, must exist in comments |

### POST `/api/v2/admin/approveComment` / `rejectComment`

| Field | Rule |
|---|---|
| `id` | required, numeric, must exist in comments |

### POST `/api/v2/admin/sendMessage`

| Field | Rule |
|---|---|
| `user_id` | required, numeric, must exist in users |
| `message` | required, string, max 2000 characters |
| `subject` | optional, string, max 255 characters |

### POST `/api/v2/readMessage`

| Field | Rule |
|---|---|
| `id` | required, numeric, must exist in admin_messages |

---

## Future Improvements

| Improvement | Description |
|---|---|
| **Push notification on message receive** | When admin sends a message, trigger Firebase push via existing `push_notification_tokens` table |
| **User → Admin reply** | Add `reply_to_message_id` column and `POST /v2/replyMessage` endpoint for two-way support conversations |
| **Message type/tag** | Add `type` enum (`info`, `warning`, `offer`, `support`) for categorised display in the app |
| **Bulk messaging** | Accept `user_ids: [1,2,3]` or `role_id` in sendMessage for broadcasting to a group |
| **Soft delete** | Use `SoftDeletes` on `AdminMessage` so deleted messages can be audited |
| **Unread count in landing page** | Include `unread_messages_count` in `/v2/landingpage` response to avoid an extra request on app launch |
