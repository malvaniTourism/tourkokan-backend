# DOC 1 — Website Changes

> **Audience:** Frontend developer working on the Tourkokan website (Next.js / web repo)
> **Scope:** What to remove, what to add, and what stays the same on the public website.

---

## Overview

The website is a **read-only discovery platform**. All content creation (adding places, commenting, messaging) moves exclusively to the mobile app. The website shows browsing, event listings, and route information — nothing that requires a logged-in write action except contact/query.

---

## 1. Site / Place Submission

### Remove
- Any page, form, button, or CTA that allows a user to submit a new place/site from the website.
- Any "Add Your Business" or "List Your Place" forms.

### Add
- Replace removed CTAs with a **"Download the App"** prompt/banner:
  - Text: *"Want to list your place? Download the Tourkokan app to submit your business."*
  - Include App Store and Play Store links/badges.
- If a guest user navigates to a submission URL directly, redirect to the Download App page.

---

## 2. Authentication & Registration

### Current behavior
- Website shows "Guest Login" option.
- Users can browse as guest.

### Changes
- **Remove** any registration form from the website.
- **Remove** any login form that leads to write actions.
- Guest browsing (read-only) remains unchanged.
- If a user tries any action requiring auth (comment, submit place), show the **Download App** popup/modal instead of a login form.
- Login on website is only needed for admin-linked flows — regular users authenticate via the app.

> **Note (future):** Mobile number and email verification for site submission will be enforced in the app's profile update flow. No website-side verification UI is needed.

---

## 3. Comments Section

### Current behavior
- Website may show a comment input form on place detail pages.

### Changes
- **Remove** the comment submission form/input from the website.
- **Keep** displaying approved comments (those with `status: true` from the API).
- If a user wants to comment, show: *"Comments can be posted via the Tourkokan app."*

### API for displaying comments (read-only, no change needed)
Comments returned by `POST /api/v2/comments` already only return approved comments (`status: true`). No filter change needed on the frontend — the backend handles it.

```bash
curl -X POST {{API_BASE_URL}}/api/v2/comments \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "commentable_type": "App\\Models\\Site",
    "commentable_id": 5,
    "per_page": 10
  }'
```

---

## 4. Events

### Keep
- Event listing page (read-only).
- Event detail page (read-only).

### Remove
- Any "Create Event" form or button on the website.
- Replace with Download App CTA: *"Create and manage events in the app."*

---

## 5. Ratings

### Keep
- Display average rating on place detail pages (already from API).

### Remove
- Any rating submission widget on the website.
- Replace with Download App CTA.

---

## 6. Favourites

- Remove favourite toggle/button from the website (requires login + write action).
- Keep displaying favourite counts if shown.

---

## 7. Pages / Routes to Add

| Page | Purpose |
|---|---|
| `/download` | Download App landing page with App Store + Play Store links |
| `/submit-place` | Redirects to `/download` with message about listing a business |

---

## 8. Pages / Routes That Stay Unchanged

| Page | Notes |
|---|---|
| `/` Home | No change |
| `/places` Listing | Read-only browse, no change |
| `/place/:id` Detail | Show approved comments + ratings only |
| `/events` Listing | Read-only |
| `/routes` | Read-only |
| `/contact` | Contact/query form stays (uses `POST /api/v2/addGuestQuery`) |

---

## 9. Guest Query (Contact Form) — No Change

This is the only write action that stays on the website. It does not require login.

```bash
curl -X POST {{API_BASE_URL}}/api/v2/addGuestQuery \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Rahul Patil",
    "email": "rahul@example.com",
    "message": "I want to know about listing my homestay."
  }'
```

---

## Summary of Changes

| Feature | Action |
|---|---|
| Add Place / Submit Business form | REMOVE → show Download App |
| Registration / Login (write actions) | REMOVE → show Download App |
| Comment submission form | REMOVE → show Download App |
| Create Event button/form | REMOVE → show Download App |
| Rate a place widget | REMOVE → show Download App |
| Favourite toggle | REMOVE |
| Browse places / events / routes | KEEP (no change) |
| Display approved comments | KEEP (no change needed) |
| Contact / Query form | KEEP (no change) |
| Download App page | ADD |
