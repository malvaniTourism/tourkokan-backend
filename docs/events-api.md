# Events API — Integration Reference

**Base URL:** `http://<your-domain>/api/v2/`  
**Auth:** All endpoints require `Authorization: Bearer <jwt_token>` header  
**Content-Type:** `application/json`  
**All responses use HTTP 200** — check `"success": true/false` in body

---

## Response Envelope

Every response follows this wrapper:

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "...",
  "data": { ... }
}
```

### Validation failure response
```json
{
  "success": false,
  "message": {
    "field_name": ["Error message here"]
  }
}
```

### Invalid / non-existent `id` response
All endpoints that accept `id` use `exists:events,id` validation. If the event does not exist:
```json
{
  "success": false,
  "message": {
    "id": ["The selected id is invalid."]
  }
}
```

---

## Enums / Allowed Values

| Field | Allowed Values |
|---|---|
| `status` | `draft` `pending` `approved` `rejected` `cancelled` `completed` |
| `taluka` | `Devgad` `Kudal` `Malvan` `Sawantwadi` `Vengurla` `Dodamarg` `Kankavli` `Vaibhavvadi` |
| `interaction_type` | `view` `like` `going` `interested` `share` |

---

# USER APIs

Base: `http://<your-domain>/api/v2/`  
All require user JWT token.

---

## 1. List Events

**`POST /api/v2/listEvents`**

Returns paginated approved upcoming events. Includes per-event `user_interaction` flags.

### Request Body (all optional)

| Field | Type | Description |
|---|---|---|
| `search` | `string` | Search in title, description, venue_name |
| `taluka` | `string` | Filter by taluka (see enum above) |
| `event_type_id` | `integer` | Filter by event type ID |
| `is_free` | `boolean` | `true` = free events only |
| `start_date` | `string` `YYYY-MM-DD` | Events on or after this date |
| `end_date` | `string` `YYYY-MM-DD` | Events on or before this date |
| `is_featured` | `boolean` | `true` = featured events only |
| `per_page` | `integer` | Results per page (default: `15`) |

### Sample Request
```json
{
  "taluka": "Malvan",
  "is_free": true,
  "per_page": 10
}
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
        "id": 5,
        "user_id": 12,
        "site_id": null,
        "event_type_id": 2,
        "status": "approved",
        "title": "Malvan Seafood Festival",
        "slug": "malvan-seafood-festival",
        "description": "Annual seafood festival at the beach.",
        "organizer_name": "Rahul Patil",
        "organizer_phone": "9876543210",
        "organizer_email": "rahul@example.com",
        "contact_person_name": null,
        "contact_person_phone": null,
        "venue_name": "Malvan Beach",
        "address": "Near Sindhudurg Fort, Malvan",
        "taluka": "Malvan",
        "latitude": "16.0601",
        "longitude": "73.4676",
        "start_date": "2026-05-15",
        "end_date": "2026-05-17",
        "start_time": "10:00:00",
        "end_time": "22:00:00",
        "is_multi_day": true,
        "banner_image": "events/banners/abc123.jpg",
        "gallery": ["events/gallery/1.jpg", "events/gallery/2.jpg"],
        "video_url": null,
        "is_free": true,
        "entry_fee": null,
        "registration_required": false,
        "registration_link": null,
        "registration_deadline": null,
        "max_participants": null,
        "tags": ["food", "festival", "beach"],
        "view_count": 120,
        "like_count": 45,
        "going_count": 18,
        "interested_count": 30,
        "favourite_count": 10,
        "share_count": 5,
        "is_featured": true,
        "featured_until": "2026-05-18T00:00:00.000000Z",
        "is_sponsored": false,
        "is_active": true,
        "countdown_days": 16,
        "created_at": "2026-04-29T10:00:00.000000Z",
        "updated_at": "2026-04-29T10:00:00.000000Z",
        "event_type": {
          "id": 2,
          "name": "Food & Cuisine",
          "code": "food-cuisine",
          "icon": "icons/food.svg"
        },
        "site": { "id": 3, "name": "Malvan" },
        "user_interaction": {
          "has_liked": false,
          "is_going": true,
          "is_interested": false,
          "has_favourited": false
        }
      }
    ],
    "per_page": 15,
    "total": 42,
    "last_page": 3,
    "next_page_url": "http://.../api/v2/listEvents?page=2",
    "prev_page_url": null
  }
}
```

---

## 2. Get Single Event

**`GET /api/v2/events/{slug}`**

No request body. Pass the event slug in the URL. Increments view count on each call.

### Sample Request
```
GET /api/v2/events/malvan-seafood-festival
```

### Sample Response
Same structure as a single item in List Events, plus:
- `user` relation: `{ "id": 12, "name": "Rahul Patil" }`
- `user_interaction` block (when authenticated)

---

## 3. Create Event

**`POST /api/v2/createEvent`**

Submitted event status is always `pending` — admin must approve before it appears publicly.

### Request Body

| Field | Type | Required | Validation |
|---|---|---|---|
| `title` | `string` | **Yes** | max:255, must be unique (slug-checked) |
| `description` | `string` | **Yes** | max:5000 |
| `address` | `string` | **Yes** | |
| `taluka` | `string` | **Yes** | One of 8 allowed values |
| `start_date` | `string` `YYYY-MM-DD` | **Yes** | Must be today or future |
| `end_date` | `string` `YYYY-MM-DD` | **Yes** | Must be >= start_date |
| `site_id` | `integer` | No | Must exist in sites table |
| `event_type_id` | `integer` | No | Must exist in event_types table |
| `venue_name` | `string` | No | max:255 |
| `latitude` | `numeric` | No | -90 to 90 |
| `longitude` | `numeric` | No | -180 to 180 |
| `start_time` | `string` `HH:MM:SS` | No | e.g. `"10:00:00"` |
| `end_time` | `string` `HH:MM:SS` | No | e.g. `"22:00:00"` |
| `banner_image` | `string` | No | File path after upload |
| `gallery` | `array of strings` | No | Array of file paths |
| `video_url` | `string` | No | Must be valid URL |
| `is_free` | `boolean` | No | Default: `true` |
| `entry_fee` | `numeric` | Conditional | **Required if** `is_free` is `false`, min:0 |
| `registration_required` | `boolean` | No | Default: `false` |
| `registration_link` | `string` | Conditional | **Required if** `registration_required` is `true`, must be valid URL |
| `registration_deadline` | `string` `YYYY-MM-DD` | No | |
| `max_participants` | `integer` | No | min:1 |
| `tags` | `array of strings` | No | e.g. `["food", "music"]` |
| `organizer_name` | `string` | No | max:255, auto-filled from user if blank |
| `organizer_phone` | `string` | No | max:20, auto-filled from user mobile if blank |
| `organizer_email` | `string` | No | Must be valid email, auto-filled from user email if blank |
| `contact_person_name` | `string` | No | max:255 |
| `contact_person_phone` | `string` | No | max:20 |

### Sample Request
```json
{
  "title": "Sawantwadi Art Exhibition",
  "description": "Annual art showcase by local artists of Sindhudurg.",
  "address": "Town Hall, Sawantwadi",
  "taluka": "Sawantwadi",
  "start_date": "2026-06-01",
  "end_date": "2026-06-03",
  "start_time": "09:00:00",
  "end_time": "18:00:00",
  "event_type_id": 8,
  "is_free": false,
  "entry_fee": 50,
  "tags": ["art", "culture", "exhibition"],
  "banner_image": "events/banners/xyz.jpg"
}
```

### Sample Response
```json
{
  "success": true,
  "message": "Event submitted for admin approval",
  "data": {
    "id": 11,
    "slug": "sawantwadi-art-exhibition",
    "status": "pending",
    "created_at": "2026-04-29T11:00:00.000000Z"
  }
}
```

---

## 4. Update Event

**`POST /api/v2/updateEvent`**

Owner only. Send only the fields you want to change — all fields are optional except `id`.  
Blocked if status is `cancelled` or `completed`.  
If title, dates, or venue change on an already-`approved` event, it resets to `pending`.

### Request Body

| Field | Type | Required | Validation |
|---|---|---|---|
| `id` | `integer` | **Yes** | Must exist in events table |
| *(any create field)* | — | No | Same types and rules as Create |

### Sample Request
```json
{
  "id": 11,
  "entry_fee": 100,
  "max_participants": 200
}
```

### Sample Response
```json
{
  "success": true,
  "message": "Event updated successfully",
  "data": {
    "id": 11,
    "status": "pending"
  }
}
```

---

## 5. Cancel Event

**`POST /api/v2/cancelEvent`**

Owner only. Cannot cancel an already `cancelled` or `completed` event.

### Request Body

| Field | Type | Required | Validation |
|---|---|---|---|
| `id` | `integer` | **Yes** | Must exist in events table |

### Sample Request
```json
{ "id": 11 }
```

### Sample Response
```json
{
  "success": true,
  "message": "Event cancelled successfully",
  "data": null
}
```

---

## 6. Delete Event

**`POST /api/v2/deleteEvent`**

Owner only. Only works if status is `draft`, `rejected`, or `cancelled`.

### Request Body

| Field | Type | Required | Validation |
|---|---|---|---|
| `id` | `integer` | **Yes** | Must exist in events table |

### Sample Response
```json
{
  "success": true,
  "message": "Event deleted successfully",
  "data": null
}
```

---

## 7. My Events

**`POST /api/v2/myEvents`**

Returns the logged-in user's own submitted events.

### Request Body (all optional)

| Field | Type | Description |
|---|---|---|
| `status` | `string` | Filter: `draft` `pending` `approved` `rejected` `cancelled` `completed` |
| `per_page` | `integer` | Default: `15` |

### Sample Request
```json
{ "status": "pending", "per_page": 10 }
```

### Sample Response
Same paginated structure as List Events. No `user_interaction` block.

---

---

# INTERACTION APIs

All interaction endpoints:
- Method: `POST`
- Auth: `Authorization: Bearer <token>` required
- `id` must be a valid event ID that exists in the DB — invalid ID returns a validation error, not a 404
- Work only on `approved` events

---

## 8. Like Event (Toggle)

**`POST /api/v2/likeEvent`**

Calling again removes the like.

### Request Body

| Field | Type | Required | Validation |
|---|---|---|---|
| `id` | `integer` | **Yes** | Must exist in events table |

### Sample Response
```json
{
  "success": true,
  "message": "Like updated",
  "data": {
    "liked": true,
    "like_count": 46
  }
}
```

---

## 9. Going Event (Toggle)

**`POST /api/v2/goingEvent`**

### Request Body

| Field | Type | Required | Validation |
|---|---|---|---|
| `id` | `integer` | **Yes** | Must exist in events table |

### Sample Response
```json
{
  "success": true,
  "message": "Going updated",
  "data": {
    "is_going": true,
    "going_count": 19
  }
}
```

---

## 10. Interested Event (Toggle)

**`POST /api/v2/interestedEvent`**

### Request Body

| Field | Type | Required | Validation |
|---|---|---|---|
| `id` | `integer` | **Yes** | Must exist in events table |

### Sample Response
```json
{
  "success": true,
  "message": "Interested updated",
  "data": {
    "is_interested": true,
    "interested_count": 31
  }
}
```

---

## 11. Favourite Event (Toggle)

**`POST /api/v2/favouriteEvent`**

### Request Body

| Field | Type | Required | Validation |
|---|---|---|---|
| `id` | `integer` | **Yes** | Must exist in events table |

### Sample Response
```json
{
  "success": true,
  "message": "Added to favourites",
  "data": {
    "favourited": true,
    "favourite_count": 11
  }
}
```

---

## 12. Share Event

**`POST /api/v2/shareEvent`**

Not a toggle — every call records a new share.

### Request Body

| Field | Type | Required | Validation |
|---|---|---|---|
| `id` | `integer` | **Yes** | Must exist in events table |
| `device_type` | `string` | No | e.g. `"android"`, `"ios"` |
| `platform` | `string` | No | e.g. `"whatsapp"`, `"instagram"` |

### Sample Request
```json
{
  "id": 5,
  "device_type": "android",
  "platform": "whatsapp"
}
```

### Sample Response
```json
{
  "success": true,
  "message": "Share recorded",
  "data": {
    "share_count": 6
  }
}
```

---

---

# ADMIN APIs

Base: `http://<your-domain>/api/v2/admin/`  
All require admin JWT token.

---

## 13. Get Single Event (Admin)

**`POST /api/v2/admin/getEvent`**

Returns full event detail including organizer, approver, and linked relations.

### Request Body

| Field | Type | Required | Validation |
|---|---|---|---|
| `id` | `integer` | **Yes** | Must exist in events table |

### Sample Response
```json
{
  "success": true,
  "message": "Event fetched successfully",
  "data": {
    "id": 11,
    "status": "pending",
    "title": "Sawantwadi Art Exhibition",
    "user": { "id": 22, "name": "Pranav Kamble", "email": "kamblepranav460@gmail.com", "mobile": null },
    "event_type": { "id": 8, "name": "Exhibition", "code": "exhibition" },
    "site": null,
    "approved_by": null
  }
}
```

---

## 14. Create Event (Admin)

**`POST /api/v2/admin/createEvent`**

Admin-created events are immediately `approved` — no pending step.  
Same fields and validation rules as user Create Event.

### Request Body

| Field | Type | Required | Validation |
|---|---|---|---|
| `title` | `string` | **Yes** | max:255, must be unique |
| `description` | `string` | **Yes** | max:5000 |
| `address` | `string` | **Yes** | |
| `taluka` | `string` | **Yes** | One of 8 allowed values |
| `start_date` | `string` `YYYY-MM-DD` | **Yes** | Today or future |
| `end_date` | `string` `YYYY-MM-DD` | **Yes** | >= start_date |
| *(all other create fields)* | — | No | Same as user Create Event |

### Sample Request
```json
{
  "title": "Devgad Alphonso Mango Festival",
  "description": "Celebrate the famous Devgad Hapus mango season.",
  "address": "Devgad Beach Road, Devgad",
  "taluka": "Devgad",
  "start_date": "2026-05-10",
  "end_date": "2026-05-12",
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
    "site": null
  }
}
```

---

## 15. Update Any Event (Admin)

**`POST /api/v2/admin/updateEvent`**

Admin can edit any event regardless of owner or current status.  
Can also directly change the `status` field (unlike user update).

### Request Body

| Field | Type | Required | Validation |
|---|---|---|---|
| `id` | `integer` | **Yes** | Must exist in events table |
| `status` | `string` | No | `draft` `pending` `approved` `rejected` `cancelled` `completed` |
| *(any event field)* | — | No | Same types as Create |

### Sample Request
```json
{
  "id": 15,
  "title": "Devgad Hapus Mango Festival 2026",
  "status": "approved",
  "is_featured": true,
  "featured_until": "2026-05-13"
}
```

### Sample Response
```json
{
  "success": true,
  "message": "Event updated successfully",
  "data": {
    "id": 15,
    "status": "approved"
  }
}
```

---

## 16. Delete Any Event (Admin)

**`POST /api/v2/admin/deleteEvent`**

Admin can delete any event regardless of status. Soft-deleted (recoverable from DB).

### Request Body

| Field | Type | Required | Validation |
|---|---|---|---|
| `id` | `integer` | **Yes** | Must exist in events table |

### Sample Response
```json
{
  "success": true,
  "message": "Event deleted successfully",
  "data": null
}
```

---

## 17. List All Events (Admin)

**`POST /api/v2/admin/listEvents`**

Returns ALL events (any status). Includes `user`, `eventType`, `site` relations.

### Request Body (all optional)

| Field | Type | Description |
|---|---|---|
| `status` | `string` | Filter by status (see enum) |
| `taluka` | `string` | Filter by taluka |
| `event_type_id` | `integer` | Filter by event type |
| `search` | `string` | Search in title, organizer_name |
| `per_page` | `integer` | Default: `20` |

### Sample Response
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 11,
        "status": "pending",
        "title": "Sawantwadi Art Exhibition",
        "organizer_name": "Pranav Kamble",
        "taluka": "Sawantwadi",
        "start_date": "2026-06-01",
        "end_date": "2026-06-03",
        "user": { "id": 22, "name": "Pranav Kamble", "email": "kamblepranav460@gmail.com" },
        "event_type": { "id": 8, "name": "Exhibition" },
        "site": null
      }
    ],
    "total": 11,
    "per_page": 20
  }
}
```

---

## 18. Pending Events (Admin)

**`POST /api/v2/admin/pendingEvents`**

Shortcut for `status=pending`, ordered oldest first (approval queue).

### Request Body (optional)

| Field | Type | Description |
|---|---|---|
| `per_page` | `integer` | Default: `20` |

---

## 19. Approve Event (Admin)

**`POST /api/v2/admin/approveEvent`**

### Request Body

| Field | Type | Required | Validation |
|---|---|---|---|
| `id` | `integer` | **Yes** | Must exist in events table |
| `admin_notes` | `string` | No | Visible to organizer |
| `is_featured` | `boolean` | No | Feature on approval |
| `featured_until` | `string` `YYYY-MM-DD` | No | Expiry for featured status |
| `send_notification` | `boolean` | No | Queue push notification to users in that taluka |

### Sample Request
```json
{
  "id": 11,
  "admin_notes": "Looks good! Approved.",
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

---

## 20. Reject Event (Admin)

**`POST /api/v2/admin/rejectEvent`**

### Request Body

| Field | Type | Required | Validation |
|---|---|---|---|
| `id` | `integer` | **Yes** | Must exist in events table |
| `rejection_reason` | `string` | **Yes** | Shown to the organizer |

### Sample Request
```json
{
  "id": 11,
  "rejection_reason": "Event details are incomplete. Please add venue address and correct dates."
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
    "rejection_reason": "Event details are incomplete..."
  }
}
```

---

## 21. Feature / Unfeature Event (Admin)

**`POST /api/v2/admin/featureEvent`**

### Request Body

| Field | Type | Required | Validation |
|---|---|---|---|
| `id` | `integer` | **Yes** | Must exist in events table |
| `is_featured` | `boolean` | **Yes** | `true` to feature, `false` to remove |
| `featured_until` | `string` `YYYY-MM-DD` | No | Leave blank = feature indefinitely |

### Sample Request
```json
{
  "id": 5,
  "is_featured": true,
  "featured_until": "2026-05-20"
}
```

### Sample Response
```json
{
  "success": true,
  "message": "Event featured",
  "data": null
}
```

---

## 22. Event Analytics (Admin)

**`POST /api/v2/admin/eventAnalytics`**

### Request Body

| Field | Type | Required | Validation |
|---|---|---|---|
| `id` | `integer` | **Yes** | Must exist in events table |

### Sample Response
```json
{
  "success": true,
  "message": "Analytics fetched",
  "data": {
    "event_id": 5,
    "title": "Malvan Seafood Festival",
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

---

# Event Object — Full Field Reference

| Field | Type | Description |
|---|---|---|
| `id` | `integer` | Primary key |
| `user_id` | `integer` | Event creator |
| `site_id` | `integer\|null` | Linked site/place |
| `event_type_id` | `integer\|null` | Category of event |
| `status` | `string` | `draft` `pending` `approved` `rejected` `cancelled` `completed` |
| `rejection_reason` | `string\|null` | Set on reject |
| `title` | `string` | Event title |
| `slug` | `string` | URL-friendly title (auto-generated, used in GET detail URL) |
| `description` | `string` | Full description (max 5000 chars) |
| `organizer_name` | `string\|null` | |
| `organizer_phone` | `string\|null` | |
| `organizer_email` | `string\|null` | |
| `contact_person_name` | `string\|null` | |
| `contact_person_phone` | `string\|null` | |
| `venue_name` | `string\|null` | Name of venue |
| `address` | `string` | Full address |
| `taluka` | `string` | One of 8 talukas |
| `latitude` | `numeric\|null` | |
| `longitude` | `numeric\|null` | |
| `start_date` | `date` `YYYY-MM-DD` | |
| `end_date` | `date` `YYYY-MM-DD` | |
| `start_time` | `time\|null` `HH:MM:SS` | |
| `end_time` | `time\|null` `HH:MM:SS` | |
| `is_multi_day` | `boolean` | Auto-set: `true` if end_date > start_date |
| `banner_image` | `string\|null` | File path |
| `gallery` | `array\|null` | Array of file paths |
| `video_url` | `string\|null` | |
| `is_free` | `boolean` | Default: `true` |
| `entry_fee` | `numeric\|null` | Required when `is_free = false` |
| `registration_required` | `boolean` | Default: `false` |
| `registration_link` | `string\|null` | URL |
| `registration_deadline` | `date\|null` | |
| `max_participants` | `integer\|null` | |
| `tags` | `array\|null` | Array of strings |
| `view_count` | `integer` | Total views |
| `like_count` | `integer` | Total likes |
| `going_count` | `integer` | Total going |
| `interested_count` | `integer` | Total interested |
| `favourite_count` | `integer` | Total favourites |
| `share_count` | `integer` | Total shares |
| `is_featured` | `boolean` | |
| `featured_until` | `datetime\|null` | Null = no expiry |
| `is_sponsored` | `boolean` | Set by admin only |
| `is_active` | `boolean` | Computed: approved + end_date >= today |
| `countdown_days` | `integer` | Days until start_date |
| `admin_notes` | `string\|null` | Admin message to organizer |
| `approved_by` | `integer\|null` | Admin user ID |
| `approved_at` | `datetime\|null` | |
| `created_at` | `datetime` | |
| `updated_at` | `datetime` | |

---

# API Endpoint Summary

## User APIs (`/api/v2/`)

| # | Method | Endpoint | Purpose |
|---|---|---|---|
| 1 | POST | `/listEvents` | Browse approved upcoming events |
| 2 | GET | `/events/{slug}` | Event detail by slug |
| 3 | POST | `/createEvent` | Submit new event (→ pending) |
| 4 | POST | `/updateEvent` | Edit own event |
| 5 | POST | `/cancelEvent` | Cancel own event |
| 6 | POST | `/deleteEvent` | Delete own draft/rejected/cancelled event |
| 7 | POST | `/myEvents` | My submitted events |
| 8 | POST | `/likeEvent` | Toggle like |
| 9 | POST | `/goingEvent` | Toggle going |
| 10 | POST | `/interestedEvent` | Toggle interested |
| 11 | POST | `/favouriteEvent` | Toggle favourite |
| 12 | POST | `/shareEvent` | Record share |

## Admin APIs (`/api/v2/admin/`)

| # | Method | Endpoint | Purpose |
|---|---|---|---|
| 13 | POST | `/getEvent` | Single event detail |
| 14 | POST | `/createEvent` | Create event (→ auto approved) |
| 15 | POST | `/updateEvent` | Edit any event, any status |
| 16 | POST | `/deleteEvent` | Delete any event |
| 17 | POST | `/listEvents` | All events with filters |
| 18 | POST | `/pendingEvents` | Approval queue |
| 19 | POST | `/approveEvent` | Approve + optional feature/notify |
| 20 | POST | `/rejectEvent` | Reject with reason |
| 21 | POST | `/featureEvent` | Feature / unfeature |
| 22 | POST | `/eventAnalytics` | Engagement stats |

---

# Quick Integration Checklist

- [ ] Store JWT token on login, attach as `Authorization: Bearer <token>` on every request
- [ ] Use `slug` (not `id`) for deep links to event detail screen → `GET /events/{slug}`
- [ ] All endpoints that take `id` validate it with `exists:events,id` — handle the `"The selected id is invalid."` error in the app
- [ ] After user creates event, show "Submitted for approval" state — event won't appear in the public list until admin approves
- [ ] Check `user_interaction` flags from list/show response to render correct button states (liked, going, etc.)
- [ ] Interaction buttons are toggles — same endpoint called again reverses the action
- [ ] For the event form, `taluka` must be one of exactly 8 values (show as dropdown)
- [ ] `start_time` / `end_time` must be sent in `HH:MM:SS` format
- [ ] `gallery` and `tags` must be sent as JSON arrays, not comma-separated strings
- [ ] `entry_fee` is required when `is_free = false` — enforce this in UI before submit
- [ ] `registration_link` is required when `registration_required = true`
- [ ] Admin-created events are immediately live (`approved`) — no approval step needed
- [ ] Admin update can change `status` directly; user update cannot
