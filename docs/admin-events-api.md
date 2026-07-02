# Admin — Events API

**Base URL:** `http://<domain>/admin/v2/`  
**Auth:** `Authorization: Bearer <admin_jwt_token>` on every request  
**Content-Type:** `application/json`  
**Method:** All endpoints are `POST`

---

## Response Format

```json
{ "success": true,  "message": "...", "data": { ... } }
{ "success": false, "message": { "field": ["error"] } }
```

**Invalid `id`:**
```json
{ "success": false, "message": { "id": ["The selected id is invalid."] } }
```

---

## Enums

| Field | Values |
|---|---|
| `status` | `draft` `pending` `approved` `rejected` `cancelled` `completed` |
| `taluka` | `Devgad` `Kudal` `Malvan` `Sawantwadi` `Vengurla` `Dodamarg` `Kankavli` `Vaibhavvadi` |

---

## Endpoints

| # | Endpoint | Purpose |
|---|---|---|
| 1 | `POST /listEvents` | All events with filters |
| 2 | `POST /getEvent` | Single event detail |
| 3 | `POST /createEvent` | Create event (auto-approved) |
| 4 | `POST /updateEvent` | Edit any event |
| 5 | `POST /deleteEvent` | Delete any event |
| 6 | `POST /pendingEvents` | Approval queue |
| 7 | `POST /approveEvent` | Approve event |
| 8 | `POST /rejectEvent` | Reject event |
| 9 | `POST /featureEvent` | Feature / unfeature |
| 10 | `POST /eventAnalytics` | Engagement stats |

---

---

## 1. List Events

`POST /admin/v2/listEvents`

### Request (all optional)

| Field | Type | Description |
|---|---|---|
| `status` | `string` | Filter by status |
| `taluka` | `string` | Filter by taluka |
| `event_type_id` | `integer` | Filter by event type |
| `search` | `string` | Search in title, organizer_name |
| `per_page` | `integer` | Default: `20` |

### Sample Request
```json
{ "status": "pending", "taluka": "Malvan", "per_page": 10 }
```

### Sample Response
```json
{
  "success": true,
  "message": "Events fetched successfully",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 11,
        "status": "pending",
        "title": "Sawantwadi Art Exhibition",
        "organizer_name": "Pranav Kamble",
        "taluka": "Sawantwadi",
        "start_date": "2026-06-01",
        "end_date": "2026-06-03",
        "is_free": false,
        "entry_fee": "50.00",
        "is_featured": false,
        "view_count": 0,
        "like_count": 0,
        "going_count": 0,
        "user": { "id": 22, "name": "Pranav Kamble", "email": "pranav@example.com" },
        "event_type": { "id": 8, "name": "Exhibition" },
        "site": null
      }
    ],
    "total": 11,
    "per_page": 20,
    "last_page": 1
  }
}
```

---

## 2. Get Single Event

`POST /admin/v2/getEvent`

### Request

| Field | Type | Required |
|---|---|---|
| `id` | `integer` | **Yes** |

### Sample Request
```json
{ "id": 11 }
```

### Sample Response
```json
{
  "success": true,
  "message": "Event fetched successfully",
  "data": {
    "id": 11,
    "status": "pending",
    "title": "Sawantwadi Art Exhibition",
    "slug": "sawantwadi-art-exhibition",
    "description": "Annual art showcase by local artists of Sindhudurg.",
    "organizer_name": "Pranav Kamble",
    "organizer_phone": "9876543210",
    "organizer_email": "pranav@example.com",
    "contact_person_name": null,
    "contact_person_phone": null,
    "venue_name": null,
    "address": "Town Hall, Sawantwadi",
    "taluka": "Sawantwadi",
    "latitude": null,
    "longitude": null,
    "start_date": "2026-06-01",
    "end_date": "2026-06-03",
    "start_time": "09:00:00",
    "end_time": "18:00:00",
    "is_multi_day": true,
    "banner_image": "events/banners/xyz.jpg",
    "gallery": null,
    "video_url": null,
    "is_free": false,
    "entry_fee": "50.00",
    "registration_required": false,
    "registration_link": null,
    "registration_deadline": null,
    "max_participants": null,
    "tags": ["art", "culture"],
    "view_count": 0,
    "like_count": 0,
    "going_count": 0,
    "interested_count": 0,
    "favourite_count": 0,
    "share_count": 0,
    "is_featured": false,
    "featured_until": null,
    "admin_notes": null,
    "rejection_reason": null,
    "approved_at": null,
    "user": { "id": 22, "name": "Pranav Kamble", "email": "pranav@example.com", "mobile": null },
    "event_type": { "id": 8, "name": "Exhibition", "code": "exhibition" },
    "site": null,
    "approved_by": null
  }
}
```

---

## 3. Create Event

`POST /admin/v2/createEvent`

Event is immediately **`approved`** — no pending step.  
`organizer_name` is auto-fetched from `user_id` if provided.

### Request

| Field | Type | Required | Notes |
|---|---|---|---|
| `user_id` | `integer` | No | Must exist in users table. If provided, `organizer_name` is auto-filled from this user |
| `title` | `string` max:255 | **Yes** | Must be unique |
| `description` | `string` max:5000 | **Yes** | |
| `address` | `string` | **Yes** | |
| `taluka` | `string` | **Yes** | One of 8 allowed values |
| `start_date` | `date` `YYYY-MM-DD` | **Yes** | Today or future |
| `end_date` | `date` `YYYY-MM-DD` | **Yes** | >= start_date |
| `site_id` | `integer` | No | Must exist in sites table |
| `event_type_id` | `integer` | No | Must exist in event_types table |
| `venue_name` | `string` max:255 | No | |
| `latitude` | `numeric` | No | -90 to 90 |
| `longitude` | `numeric` | No | -180 to 180 |
| `start_time` | `string` `HH:MM:SS` | No | e.g. `"09:00:00"` |
| `end_time` | `string` `HH:MM:SS` | No | e.g. `"18:00:00"` |
| `banner_image` | `string` | No | File path after upload |
| `gallery` | `array of strings` | No | Array of file paths |
| `video_url` | `string` | No | Valid URL |
| `is_free` | `boolean` | No | Default: `true` |
| `entry_fee` | `numeric` min:0 | Conditional | **Required if** `is_free` is `false` |
| `registration_required` | `boolean` | No | Default: `false` |
| `registration_link` | `string` | Conditional | **Required if** `registration_required` is `true` |
| `registration_deadline` | `date` `YYYY-MM-DD` | No | |
| `max_participants` | `integer` min:1 | No | |
| `tags` | `array of strings` | No | e.g. `["food","music"]` |
| `organizer_name` | `string` max:255 | No | Auto-filled from `user_id` if provided |
| `organizer_phone` | `string` max:20 | No | Falls back to user's mobile if `user_id` given |
| `organizer_email` | `string` | No | Falls back to user's email if `user_id` given |
| `contact_person_name` | `string` max:255 | No | |
| `contact_person_phone` | `string` max:20 | No | |

### Sample Request
```json
{
  "user_id": 22,
  "title": "Devgad Alphonso Mango Festival",
  "description": "Celebrate the famous Devgad Hapus mango season with local farmers and vendors.",
  "address": "Devgad Beach Road, Devgad",
  "taluka": "Devgad",
  "start_date": "2026-06-01",
  "end_date": "2026-06-03",
  "start_time": "09:00:00",
  "end_time": "20:00:00",
  "event_type_id": 1,
  "is_free": true,
  "tags": ["mango", "festival", "devgad"]
}
```

### Sample Response
```json
{
  "success": true,
  "message": "Event created and published successfully",
  "data": {
    "id": 15,
    "slug": "devgad-alphonso-mango-festival",
    "status": "approved",
    "approved_at": "2026-04-29T12:00:00.000000Z",
    "event_type": { "id": 1, "name": "Festival" },
    "site": null,
    "user": { "id": 22, "name": "Pranav Kamble", "email": "pranav@example.com" }
  }
}
```

---

## 4. Update Event

`POST /admin/v2/updateEvent`

Can edit any event regardless of owner or status. Can directly set `status`.

### Request

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | `integer` | **Yes** | Must exist in events table |
| `status` | `string` | No | Any status value allowed |
| `title` | `string` max:255 | No | |
| `description` | `string` max:5000 | No | |
| `address` | `string` | No | |
| `taluka` | `string` | No | One of 8 values |
| `start_date` | `date` `YYYY-MM-DD` | No | |
| `end_date` | `date` `YYYY-MM-DD` | No | >= start_date |
| `start_time` | `string` `HH:MM:SS` | No | |
| `end_time` | `string` `HH:MM:SS` | No | |
| `is_free` | `boolean` | No | |
| `entry_fee` | `numeric` | No | |
| `is_featured` | `boolean` | No | |
| `featured_until` | `date` `YYYY-MM-DD` | No | |
| *(any other event field)* | — | No | |

### Sample Request
```json
{
  "id": 15,
  "title": "Devgad Hapus Mango Festival 2026",
  "status": "approved",
  "is_featured": true,
  "featured_until": "2026-06-05"
}
```

### Sample Response
```json
{
  "success": true,
  "message": "Event updated successfully",
  "data": { "id": 15, "status": "approved" }
}
```

---

## 5. Delete Event

`POST /admin/v2/deleteEvent`

Deletes any event regardless of status. Soft-deleted (recoverable from DB).

### Request

| Field | Type | Required |
|---|---|---|
| `id` | `integer` | **Yes** |

### Sample Request
```json
{ "id": 15 }
```

### Sample Response
```json
{
  "success": true,
  "message": "Event deleted successfully",
  "data": null
}
```

---

## 6. Pending Events

`POST /admin/v2/pendingEvents`

Shortcut for approval queue — returns pending events oldest first.

### Request (optional)

| Field | Type | Default |
|---|---|---|
| `per_page` | `integer` | `20` |

### Sample Response
Same structure as List Events, filtered to `status = pending`.

---

## 7. Approve Event

`POST /admin/v2/approveEvent`

### Request

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | `integer` | **Yes** | |
| `admin_notes` | `string` | No | Note visible to organizer |
| `is_featured` | `boolean` | No | Feature on approval |
| `featured_until` | `date` `YYYY-MM-DD` | No | Null = indefinite |
| `send_notification` | `boolean` | No | Queue push notification to users in event's taluka |

### Sample Request
```json
{
  "id": 11,
  "admin_notes": "Looks great, approved!",
  "is_featured": true,
  "featured_until": "2026-06-04",
  "send_notification": true
}
```

### Sample Response
```json
{
  "success": true,
  "message": "Event approved successfully",
  "data": {
    "id": 11,
    "status": "approved",
    "approved_by": "Admin Name",
    "approved_at": "2026-04-29T12:00:00.000000Z"
  }
}
```

> Cannot approve an already `approved` event — returns error.

---

## 8. Reject Event

`POST /admin/v2/rejectEvent`

### Request

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | `integer` | **Yes** | |
| `rejection_reason` | `string` | **Yes** | Shown to the organizer |

### Sample Request
```json
{
  "id": 11,
  "rejection_reason": "Incomplete venue details. Please add full address and timings."
}
```

### Sample Response
```json
{
  "success": true,
  "message": "Event rejected",
  "data": {
    "id": 11,
    "status": "rejected",
    "rejection_reason": "Incomplete venue details..."
  }
}
```

> Cannot reject `cancelled` or `completed` events.

---

## 9. Feature / Unfeature

`POST /admin/v2/featureEvent`

### Request

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | `integer` | **Yes** | |
| `is_featured` | `boolean` | **Yes** | `true` = feature, `false` = remove |
| `featured_until` | `date` `YYYY-MM-DD` | No | Null = no expiry |

### Sample Request
```json
{ "id": 11, "is_featured": true, "featured_until": "2026-06-10" }
```

### Sample Response
```json
{ "success": true, "message": "Event featured", "data": null }
```

---

## 10. Event Analytics

`POST /admin/v2/eventAnalytics`

### Request

| Field | Type | Required |
|---|---|---|
| `id` | `integer` | **Yes** |

### Sample Request
```json
{ "id": 11 }
```

### Sample Response
```json
{
  "success": true,
  "message": "Analytics fetched",
  "data": {
    "event_id": 11,
    "title": "Sawantwadi Art Exhibition",
    "status": "approved",
    "view_count": 120,
    "click_count": 0,
    "share_count": 6,
    "like_count": 46,
    "favourite_count": 11,
    "going_count": 19,
    "interested_count": 31,
    "interaction_breakdown": {
      "view": 95,
      "like": 46,
      "going": 19,
      "interested": 31,
      "share": 6
    }
  }
}
```

---

## cURL Examples

Replace `YOUR_TOKEN` with the admin JWT.

```bash
# 1. List Events
curl -X POST http://127.0.0.1:8000/admin/v2/listEvents \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status":"pending","per_page":10}'

# 2. Get Event
curl -X POST http://127.0.0.1:8000/admin/v2/getEvent \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":1}'

# 3. Create Event
curl -X POST http://127.0.0.1:8000/admin/v2/createEvent \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 22,
    "title": "Devgad Alphonso Mango Festival",
    "description": "Celebrate the famous Devgad Hapus mango season.",
    "address": "Devgad Beach Road, Devgad",
    "taluka": "Devgad",
    "start_date": "2026-06-01",
    "end_date": "2026-06-03",
    "start_time": "09:00:00",
    "end_time": "20:00:00",
    "event_type_id": 1,
    "is_free": true,
    "tags": ["mango","festival","devgad"]
  }'

# 4. Update Event
curl -X POST http://127.0.0.1:8000/admin/v2/updateEvent \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":1,"is_featured":true,"featured_until":"2026-06-10"}'

# 5. Delete Event
curl -X POST http://127.0.0.1:8000/admin/v2/deleteEvent \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":1}'

# 6. Pending Events
curl -X POST http://127.0.0.1:8000/admin/v2/pendingEvents \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"per_page":20}'

# 7. Approve Event
curl -X POST http://127.0.0.1:8000/admin/v2/approveEvent \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":1,"admin_notes":"Approved!","is_featured":true,"send_notification":true}'

# 8. Reject Event
curl -X POST http://127.0.0.1:8000/admin/v2/rejectEvent \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":1,"rejection_reason":"Incomplete details."}'

# 9. Feature Event
curl -X POST http://127.0.0.1:8000/admin/v2/featureEvent \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":1,"is_featured":true,"featured_until":"2026-06-10"}'

# 10. Analytics
curl -X POST http://127.0.0.1:8000/admin/v2/eventAnalytics \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":1}'
```
