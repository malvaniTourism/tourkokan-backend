# Site Onboarding — Frontend Implementation Guide

> For: App team + Website team  
> Backend APIs: All implemented. See `SITE_ONBOARDING.md` for cURL reference.

---

## Overview

Users can submit their hotel, restaurant, homestay, tour package, or any business listing via the **app only**. The website shows a "Download App" popup — no form on the website.

---

## Part 1 — Website Changes

### What to Add

A single "Add Your Place" button and a modal popup. No form, no auth required.

---

### Button Placement

| Location | Element | Behaviour |
|---|---|---|
| Main navigation (desktop) | Text button — "Add Your Place" | Opens modal |
| Hero section (mobile + desktop) | Secondary CTA button | Opens modal |

---

### Modal Content — `AddYourPlaceModal`

```
┌──────────────────────────────────────────────────────────┐
│              List Your Place on Tourkokan                 │
│                                                          │
│  Add your hotel, homestay, restaurant, tour package,     │
│  or any business — completely free.                      │
│                                                          │
│  To list your place, use the Tourkokan app:              │
│                                                          │
│  ① Download the Tourkokan app from Google Play           │
│  ② Register with your mobile number and email            │
│  ③ Go to Profile → My Listings → Add New Place           │
│  ④ Fill your details and submit                          │
│  ⑤ We review and publish within 24 hours                 │
│                                                          │
│  [ Download on Google Play ]   ← link to Play Store      │
│                                                          │
│  ──────────────────────────────────────────────────────  │
│  Need help? Our team will fill the form for you.         │
│  [ Contact Us / WhatsApp ]  ← existing WhatsApp button   │
└──────────────────────────────────────────────────────────┘
```

**Props:** `isOpen: boolean`, `onClose: () => void`

**No API calls needed** — this is a static informational modal.

---

## Part 2 — App Changes

### Auth Gate

Before showing the "Add Your Place" form, check:

```
user.isGuest === false   AND   user !== null
```

If the user is a guest or not logged in → show `GuestAuthModal` (already exists).

> **Note on verification:** A future app update will add a profile verification step (mobile OTP + email OTP) that gates the `addSite` submission. The backend will return `403` when verification is incomplete. For now, show a prompt in the form header:  
> *"Tip: Listings from verified accounts are approved faster. Verify your email and mobile in Account Settings."*  
> This is informational only — it does not block submission in the current release.

---

### New Screens / Components

| Screen / Component | Route / Location |
|---|---|
| `AddPlaceScreen` (7-step form) | `/(app)/my-listings/add` |
| `MySubmissionsScreen` | `/(app)/my-listings` |
| `SubmissionDetailScreen` | `/(app)/my-listings/[id]` |
| `MapPickerComponent` | Reusable, used in Step 3 |
| `GoogleMapsUrlInput` | Reusable, used in Step 3 |

---

### Entry Points in the App

| Location | Element | Action |
|---|---|---|
| Profile screen | "My Listings" row | Navigate to `MySubmissionsScreen` |
| `MySubmissionsScreen` | "Add New Place" FAB / button | Navigate to `AddPlaceScreen` |
| Home screen (optional) | Banner for new users | Navigate to `AddPlaceScreen` |

---

### Step-by-Step Form — `AddPlaceScreen`

Show a progress bar at the top (e.g. "Step 2 of 7"). Each step validates before moving to next. User can go back freely.

---

#### Step 1 — Category

- Fetch categories from `POST /api/v2/listcategories` (already exists)
- Show as a scrollable grid of category cards with icons
- Multi-select allowed (e.g. a homestay can also be a restaurant)
- At least 1 required

---

#### Step 2 — Basic Info

| Field | Input Type | Required |
|---|---|---|
| Place name | Text input | Yes |
| Tagline | Text input (max 100 chars) | No |
| Description | Multiline textarea (min 20 chars) | Yes |
| Website | URL input | No |

---

#### Step 3 — Location

Show three tabs: **[Pick on Map]** · **[Paste Google Maps Link]** · **[Enter Manually]**

**Tab A — Pick on Map**
- Use `react-native-maps` (or equivalent for the framework)
- Show OpenStreetMap tiles (free, no API key) or Google Maps (if already integrated)
- User drags a pin to their exact location
- Lat/lng auto-populated below the map

**Tab B — Paste Google Maps Link**
- Single text input: "Paste your Google Maps link here"
- "Get Coordinates" button
- On press → call `POST /api/v2/parseMapUrl` with the URL
- On success → show green confirmation: "✓ Coordinates found: 16.0601, 73.4662"
- On failure → show error + prompt to use manual entry

**Tab C — Enter Manually**
- Two numeric inputs: Latitude, Longitude
- Show collapsible guide: *"How to get your coordinates"*

  > 1. Open Google Maps on your phone  
  > 2. Search your place  
  > 3. Long press on the location pin  
  > 4. The coordinates appear at the top (e.g. `16.0601, 73.4662`)  
  > 5. Copy and paste each value here

Also on this step:
- **City / Taluka** dropdown — `parent_id` — fetch from sites list
- **Pin Code** — 6-digit numeric input

---

#### Step 4 — Photos

| Field | Requirement |
|---|---|
| Cover photo (`image`) | Recommended (max 2MB, jpeg/jpg/png) |
| Logo (`logo`) | Optional (max 1MB) |

Show current file size hint. Compress on device before upload if > 1MB.

---

#### Step 5 — Contact & Social

All stored as `social_media` JSON. All optional but recommended.

| Field | JSON Key |
|---|---|
| Phone number | `phone` |
| Instagram handle | `instagram` |
| Facebook page URL | `facebook` |

---

#### Step 6 — Additional Details (Optional — skippable)

**Specialities** (`speciality` JSON) — tag input
Examples: "Sea view", "Veg only", "AC rooms", "Home-cooked food", "Boat trips"

**Rules** (`rules` JSON) — tag input
Examples: "No smoking", "Check-in 12 PM", "No pets", "ID proof required"

---

#### Step 7 — Review & Submit

Show a summary card of all entered data (read-only).
Two buttons: **[Back]** and **[Submit for Review]**

On "Submit for Review":
- Call `POST /api/v2/addSite` (multipart form-data for image fields)
- On success → show success screen:  
  *"Your place has been submitted! We will review and publish it within 24 hours."*  
  Button: "View My Listings"
- On error → show error message, stay on review screen

---

### `MySubmissionsScreen`

**API:** `POST /api/v2/mySubmissions`

Show a list of submission cards. Each card shows:
- Place name + cover image thumbnail
- Status badge (see table below)
- Submitted date

**Status Badges:**

| `submission_status` | Badge Label | Colour |
|---|---|---|
| `pending` | Under Review | Amber/Yellow |
| `approved` | Live ✓ | Green |
| `rejected` | Changes Needed | Red |

**Card Actions:**

| Status | Edit | Delete | Resubmit |
|---|---|---|---|
| `pending` | ✓ | ✓ | — |
| `approved` | ✓ (sends back to pending) | — | — |
| `rejected` | ✓ | ✓ | After edit → auto-resubmits |

On a **rejected** card → show `rejection_reason` text inline below the card.  
Example: *"Please add at least one clear photo of the property."*

---

### `SubmissionDetailScreen` / Edit Screen

Same 7-step form pre-filled with existing data.

On submission:
- If `submission_status` was `rejected` → call `POST /api/v2/updateMySubmission` → backend resets to `pending` automatically
- If `submission_status` was `pending` → call `POST /api/v2/updateMySubmission` → stays `pending`
- If `submission_status` was `approved` → call `POST /api/v2/updateMySubmission` → resets to `pending` for re-review. Show warning before save: *"Editing a live listing will send it back for review and temporarily remove it from the app."*

Delete: `POST /api/v2/deleteMySubmission` — only available for `pending` and `rejected`.

---

### API Calls Summary (App)

| Action | Method | URL | Body / Fields |
|---|---|---|---|
| Parse Google Maps URL | POST | `/api/v2/parseMapUrl` | `{ url }` |
| Submit new place | POST | `/api/v2/addSite` | multipart form-data |
| My submissions | POST | `/api/v2/mySubmissions` | `{ page, per_page }` |
| Update submission | POST | `/api/v2/updateMySubmission` | multipart form-data + `id` |
| Delete submission | POST | `/api/v2/deleteMySubmission` | `{ id }` |

---

### Push Notifications (Future Release)

When admin approves or rejects a site, a Firebase push notification will be sent to the user's device. Handle these notification payloads in the app:

| Event | Notification Body | Deep Link |
|---|---|---|
| Site approved | "🎉 Your listing [Name] is now live on Tourkokan!" | `/(app)/my-listings/[id]` |
| Site rejected | "Your listing [Name] needs changes. Tap to see feedback." | `/(app)/my-listings/[id]` |

---

## Part 3 — Shared Notes

### Coordinate Methods — Priority Order

Show all three options in Step 3, but guide the user toward the easiest:

1. **Map picker** — best for users on phone, most visual
2. **Paste Google Maps link** — best for businesses already on Google Maps
3. **Manual entry** — fallback with guide steps

### `social_media` JSON Structure

Store as a flat object so parsing is simple:

```json
{
  "phone": "+919876543210",
  "instagram": "malvanstay",
  "facebook": "https://facebook.com/malvanstay"
}
```

### `speciality` and `rules` JSON Structure

Store as array of strings:

```json
["Sea view", "Veg only", "AC rooms"]
```

```json
["No smoking", "Check-in 12 PM"]
```

---

## Verification — Future App Update

> **This is a planned future feature, not blocking the current release.**

When mobile + email verification is added to the profile screen:
- Check `user.isVerified === true` AND `user.mobile` is set before entering the form
- If not verified → show inline prompt:  
  *"Verify your email and add your mobile in Account Settings to list your place."*  
  With a button → Account Settings screen
- The backend already returns `403` if unverified — the app must handle this gracefully

The OTP infrastructure is already live: `POST /api/v2/auth/sendOtp` and `POST /api/v2/auth/verifyOtp`.
