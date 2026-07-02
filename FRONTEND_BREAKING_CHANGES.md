# Frontend Breaking Changes — Backend API Updates

This document covers all backend changes that require frontend updates.
Share this file with the `tourkokan` (Next.js), `admin-panel` (React), and `tourkokan-v2` (React Native) repos before making changes.

---

## 1. What Changed on the Backend

### 1.1 Register — `role_id` replaced by `role_code`

**Endpoint:** `POST /api/v2/auth/register`

The register payload no longer accepts `role_id` (integer). It now expects `role_code` (string).

```diff
- role_id: 3
+ role_code: "user"
```

Available codes: `user`, `vendor`, `admin`, `superadmin`
Default if omitted: `user`

---

### 1.2 Auth response — `roles` is now an array

Every auth response (login, register, refresh, google auth) previously returned a single role object or `role_id`. It now returns a `roles` array.

```diff
- user.role_id: 3
- user.roles: { id: 3, name: "User" }
+ user.roles: [{ id: 3, name: "User", code: "user" }]
```

A user can now have multiple roles simultaneously (e.g., `user` + `vendor`).

**How to check role in frontend:**
```js
// Old
user.roles.code === 'vendor'

// New
user.roles.some(r => r.code === 'vendor')

// Helper function (add once, use everywhere)
const hasRole = (user, code) => user.roles?.some(r => r.code === code) ?? false
```

---

### 1.3 Vendor-gated write routes return 403

The following routes now return `403` if the authenticated user does not have the `vendor` role:

**User API (`/api/v2/`):**
- `POST createEvent`
- `POST updateEvent`
- `POST deleteEvent`
- `POST cancelEvent`
- `POST addSite`
- `POST updateMySubmission`
- `POST deleteMySubmission`

**403 response shape:**
```json
{
  "success": false,
  "message": "Access denied. You need the Vendor role to perform this action. Please request the Vendor role from your profile."
}
```

Frontend must handle this response and show the message to the user — do not treat it as a generic error.

---

### 1.4 Comment create/update — returns pending message, not comment object

**Endpoints:** `POST comment`, `POST updateComment`

Previously returned the created/updated comment object. Now returns:
```json
{
  "success": true,
  "message": "Your comment will be visible after admin approval."
}
```

Do not optimistically insert the comment into the list. Show the message to the user instead.

---

### 1.5 Rate limiting — 429 responses

All APIs now enforce rate limits. When exceeded, the response is:
```json
{
  "success": false,
  "message": "Too many requests. Please slow down and try again later."
}
```
HTTP status: `429` with a `Retry-After` header.

| Route group | Limit |
|---|---|
| Login, register, google auth | 10 requests/min per IP |
| Send OTP, verify OTP | 3 requests/min per IP |
| Create, update, delete (write ops) | 30 requests/min per user |
| File uploads | 5 requests/min per user |
| All other authenticated reads | 60 requests/min per user |

Frontend must catch `429` status and show the message rather than a generic network error.

---

### 1.6 New APIs — Role Request System

Two new endpoints for users to request the Vendor role:

**Request a role:**
```
POST /api/v2/requestRole
Auth: Bearer token required

Body:
{
  "role_code": "vendor",
  "reason": "I want to list my hotel on the platform"   // optional
}

Success response:
{
  "success": true,
  "message": "Role request submitted successfully. An admin will review it shortly.",
  "data": { ...request object }
}

Error — already has role:
{ "success": false, "message": "You already have the vendor role." }

Error — pending request exists:
{ "success": false, "message": "You already have a pending request for this role." }
```

**List user's own requests:**
```
GET /api/v2/myRoleRequests
Auth: Bearer token required

Response:
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "role_id": 2,
        "status": "pending",          // pending | approved | rejected
        "reason": "...",
        "admin_note": null,
        "reviewed_at": null,
        "role": { "id": 2, "name": "Vendor", "code": "vendor" }
      }
    ],
    "current_page": 1,
    ...pagination
  }
}
```

---

## 2. Admin Panel (React + Vite + CoreUI)

### 2.1 Auth response — update role checks

The admin panel checks if the logged-in user is admin/superadmin. Update all role checks from single object/id to array lookup.

**Files to update:** `src/store.js`, `src/context/AuthContext.js` (or wherever user role is read after login)

```js
// Old
user.roles?.code === 'admin' || user.roles?.code === 'superadmin'

// New
user.roles?.some(r => ['admin', 'superadmin'].includes(r.code))
```

### 2.2 User list — role display

Anywhere the admin panel displays a user's role (user list, user detail page), update from reading `user.role_id` or `user.roles.name` to rendering the roles array.

```jsx
// Old
<span>{user.roles?.name}</span>

// New — user can have multiple roles
<span>{user.roles?.map(r => r.name).join(', ')}</span>
```

### 2.3 New screen — User Role Requests

Add a new admin screen to list and act on pending vendor role requests.

**Admin APIs available:**
```
POST /admin/v2/userRoleRequests
Body: { status: "pending" | "approved" | "rejected", search: "" }  // all optional

POST /admin/v2/approveRoleRequest
Body: { request_id: 1, admin_note: "Approved" }   // admin_note optional

POST /admin/v2/rejectRoleRequest
Body: { request_id: 1, admin_note: "Reason for rejection" }
```

**Steps to add the screen:**
1. Add route to `src/routes.js`
2. Add nav item to `src/_nav.js` (e.g., under Users section)
3. Create view at `src/views/userRoleRequests/UserRoleRequests.jsx`
4. Add API calls to `src/services/userRoleRequestService.js`

**UI should show:** user name, requested role, reason, status badge, approve/reject buttons with optional admin note input.

### 2.4 Event/Site pending re-approval indicators

When a user edits an approved event or site, it goes back to `pending` status and a `meta_data.resubmission` field is added. The admin panel should indicate this is a resubmission (not a brand new submission).

Check for `item.meta_data?.resubmission` in the event/site detail view and show a label like "Resubmitted for review".

### 2.5 Handle 429 responses globally

In your Axios instance (likely `src/services/axiosInstance.js` or similar), add an interceptor to handle `429`:

```js
axios.interceptors.response.use(
  response => response,
  error => {
    if (error.response?.status === 429) {
      // show toast: error.response.data.message
    }
    return Promise.reject(error)
  }
)
```

---

## 3. Next.js Web Frontend (`tourkokan`)

### 3.1 Register — send `role_code` not `role_id`

Find the register API call and update the payload:

```diff
- role_id: selectedRoleId
+ role_code: "user"
```

If guests can register with a specific role, pass the code string directly.

### 3.2 Auth response — update role checks everywhere

After login/register/refresh, the user object now has `roles: [{id, name, code}]`.

Update anywhere `user.roles?.code` or `user.role_id` is used:

```js
// Helper — add to src/lib/auth.ts
export const hasRole = (user: User, code: string): boolean =>
  user.roles?.some((r) => r.code === code) ?? false

export const isVendor = (user: User) => hasRole(user, 'vendor')
export const isAdmin  = (user: User) => hasRole(user, 'admin') || hasRole(user, 'superadmin')
```

### 3.3 Guest login — no role changes needed

Guest login does not call register, so the `role_code` change does not affect guest flow. Guest users won't have vendor-gated features available by design.

### 3.4 Vendor-gated UI

If the site allows users to create events or submit sites, gate these buttons/pages behind the vendor role check:

```tsx
{isVendor(user) ? (
  <CreateEventButton />
) : (
  <RequestVendorRoleButton />
)}
```

When a vendor write API returns 403, show the message from the response — do not show a generic error.

### 3.5 Comment posting — show pending message

After `POST comment` or `POST updateComment` succeeds, do not insert the comment into the list. Show a toast or inline message:

> "Your comment will be visible after admin approval."

### 3.6 Role request flow (new feature)

Add a "Request Vendor Access" section in the user profile page:

- If user already has vendor role → show "You are a verified vendor"
- If user has a pending request → show "Vendor request pending review"
- If user has no request → show a button "Request Vendor Access" with an optional reason textarea
- Calls `POST /api/v2/requestRole` with `{ role_code: "vendor", reason: "..." }`
- On submit, show the success message from the response

Check request status via `GET /api/v2/myRoleRequests`.

### 3.7 Handle 429 globally

In your API client (`src/lib/api.ts` or `src/lib/axiosInstance.ts`), handle `429`:

```ts
if (response.status === 429) {
  throw new Error(data.message) // "Too many requests..."
}
```

---

## 4. React Native App (`tourkokan-v2`)

### 4.1 Register — `role_id` → `role_code`

**File:** `src/Services/AuthService.js` (or wherever register is called)

```diff
- role_id: 3
+ role_code: "user"
```

If role options are shown to the user at registration, store and pass the `code` field (string) from the roles dropdown, not the `id`.

### 4.2 Login / Google Auth — roles array in response

After login or Google auth, the user object in the response now has:
```json
"roles": [{ "id": 2, "name": "User", "code": "user" }]
```

**Update Redux store / user reducer** to store `roles` as an array.

Any place in the app that checks `user.roles?.code` or `user.role_id` must be updated:

```js
// Old
user.roles?.code === 'vendor'

// New
user.roles?.some(r => r.code === 'vendor')
```

Add a selector:
```js
// src/Reducers/authSlice.js or similar
export const selectHasRole = (user, code) =>
  user?.roles?.some(r => r.code === code) ?? false
```

### 4.3 Google Auth — same role array change

Google sign-in returns the same auth response shape. Update the Google auth handler wherever it reads `user.roles` from the response.

### 4.4 Guest login — no changes needed

Guest login does not call register so `role_code` change does not apply. Guest users will get 403 on vendor-gated routes — show appropriate message.

### 4.5 Vendor-gated screens

Screens or buttons for creating events / adding sites must check vendor role before showing:

```js
const isVendor = user?.roles?.some(r => r.code === 'vendor')

if (!isVendor) {
  // show "You need Vendor access" UI
  // show "Request Vendor Access" button
}
```

When a vendor write API call returns `403`, read `error.response.data.message` and show it to the user — do not show a generic "Something went wrong".

### 4.6 Comment posting — pending message

After `POST comment` succeeds, do not add the comment to the list. Show the message from the response:
> "Your comment will be visible after admin approval."

Same for `POST updateComment`.

### 4.7 Rate limit — handle 429

In your Axios interceptor (`src/Services/axiosInstance.js` or similar):

```js
axiosInstance.interceptors.response.use(
  response => response,
  error => {
    if (error.response?.status === 429) {
      Alert.alert('Slow down', error.response.data.message)
    }
    return Promise.reject(error)
  }
)
```

OTP screens are especially affected — 3 attempts/min per IP. Show a countdown or "Try again in 60 seconds" message.

### 4.8 New feature — Request Vendor Role

Add a "Request Vendor Access" option in the user profile screen:

**Flow:**
1. Check `GET /api/v2/myRoleRequests` on profile load
2. If no pending vendor request and user doesn't have vendor role → show "Request Vendor Access" button
3. On tap → optional reason input → call `POST /api/v2/requestRole` with `{ role_code: "vendor", reason: "..." }`
4. On success → show message from response and refresh request status
5. If pending → show "Vendor request pending admin review"
6. If rejected → show `admin_note` and allow re-requesting

---

## Summary Table

| Change | Admin Panel | Next.js | React Native |
|---|---|---|---|
| Register `role_code` | N/A | Update payload | Update payload |
| Roles array in auth response | Update role checks | Update role checks | Update role checks + Redux |
| Vendor 403 handling | N/A | Show message, gate UI | Show message, gate UI |
| Comment pending message | N/A | Don't insert, show msg | Don't insert, show msg |
| 429 rate limit handling | Add interceptor | Add interceptor | Add interceptor |
| User Role Requests screen | Build new screen | Add profile section | Add profile section |
| Resubmission indicator | Show on event/site | Optional | Optional |
