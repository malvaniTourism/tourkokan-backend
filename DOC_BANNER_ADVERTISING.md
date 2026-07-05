# Banner Advertising — Admin Panel Implementation Guide

## System Overview

The banner advertising system has **3 entities** that must be set up in order:

```
BannerPlacement  →  BannerPackage  →  Banner (Campaign)
(where it shows)    (price/duration)   (actual ad)
```

**Flow:**
1. Admin creates **Placements** (screens where banners can appear, with dimensions)
2. Admin creates **Packages** (pricing + duration + which placements are allowed)
3. Admin creates **Banner Campaigns** linked to a package + placement
4. Admin **approves / rejects** submitted campaigns
5. App fetches active approved banners by placement code

---

## Base URL

```
https://api-test.tourkokan.com/admin/v2
```

All requests require:
```
Authorization: Bearer <token>
Content-Type: application/json
```

---

## Auth

### Login
```
POST /admin/v2/auth/login
```
```json
{ "email": "admin@example.com", "password": "password" }
```
Response gives `token` — store in localStorage, include in every subsequent request.

---

## Section 1 — Banner Placements

Placements define **where** on the screen a banner appears and its dimensions.

### 1.1 List All Placements
```
POST /admin/v2/listBannerPlacements
```
No body required.

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "code": "home_carousel",
      "description": "Home screen top carousel",
      "screen": "home",
      "width": 1080,
      "height": 400,
      "is_active": true
    }
  ]
}
```

---

### 1.2 Get Single Placement
```
POST /admin/v2/getBannerPlacement
```
| Field | Type | Required |
|-------|------|----------|
| `id` | integer | ✅ |

---

### 1.3 Create Placement
```
POST /admin/v2/addBannerPlacement
```
| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `code` | string | ✅ | Unique. Snake_case. e.g. `home_carousel` |
| `description` | string | ❌ | Human-readable label |
| `screen` | string | ❌ | Which screen: `home`, `site_detail`, `listing` |
| `width` | integer | ❌ | Recommended width in px |
| `height` | integer | ❌ | Recommended height in px |
| `is_active` | boolean | ❌ | Default true |

**Example:**
```json
{
  "code": "home_carousel",
  "description": "Home screen top carousel banner",
  "screen": "home",
  "width": 1080,
  "height": 400,
  "is_active": true
}
```

---

### 1.4 Update Placement
```
POST /admin/v2/updateBannerPlacement
```
| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `id` | integer | ✅ | |
| `code` | string | ❌ | Must be unique (ignores own id) |
| `description` | string | ❌ | |
| `screen` | string | ❌ | |
| `width` | integer | ❌ | |
| `height` | integer | ❌ | |
| `is_active` | boolean | ❌ | |

---

### 1.5 Delete Placement
```
POST /admin/v2/deleteBannerPlacement
```
| Field | Type | Required |
|-------|------|----------|
| `id` | integer | ✅ |

---

## Section 2 — Banner Packages (Pricing)

Packages define **how much** a campaign costs, **how long** it runs, and **which placements** it includes.

### 2.1 List All Packages
```
POST /admin/v2/listBannerPackages
```
No body required.

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "7-Day Standard",
      "duration_days": 7,
      "price": 499.00,
      "allowed_placements": ["home_carousel", "site_detail_top"],
      "is_active": true
    }
  ]
}
```

---

### 2.2 Get Single Package
```
POST /admin/v2/getBannerPackage
```
| Field | Type | Required |
|-------|------|----------|
| `id` | integer | ✅ |

---

### 2.3 Create Package
```
POST /admin/v2/addBannerPackage
```
| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `name` | string | ✅ | e.g. `7-Day Standard` |
| `duration_days` | integer | ✅ | Min: 1 |
| `price` | numeric | ✅ | Min: 0 |
| `allowed_placements` | array | ✅ | Array of placement codes that exist in banner_placements |
| `is_active` | boolean | ❌ | Default true |

**Example:**
```json
{
  "name": "7-Day Standard",
  "duration_days": 7,
  "price": 499,
  "allowed_placements": ["home_carousel", "site_detail_top"],
  "is_active": true
}
```

> ⚠️ `allowed_placements` values must exist as `code` in the placements table. Create placements **before** packages.

---

### 2.4 Update Package
```
POST /admin/v2/updateBannerPackage
```
| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `id` | integer | ✅ | |
| `name` | string | ❌ | |
| `duration_days` | integer | ❌ | Min: 1 |
| `price` | numeric | ❌ | Min: 0 |
| `allowed_placements` | array | ❌ | Array of placement codes |
| `is_active` | boolean | ❌ | |

---

### 2.5 Delete Package
```
POST /admin/v2/deleteBannerPackage
```
| Field | Type | Required |
|-------|------|----------|
| `id` | integer | ✅ |

---

## Section 3 — Banner Campaigns (Ads)

Banners are the actual ad campaigns. Two creation methods exist:

- **New system** (`addBanner` in admin): image file upload, linked to site/place via `bannerable`
- **Package-based system** (`changeBannerStatus`): campaigns created by users via app, admin only approves/rejects

> Use `listBanners` to see all. Use `changeBannerStatus` to approve/reject. Use `addBanner` for admin-created banners.

---

### 3.1 List All Banners
```
POST /admin/v2/listBanners
```

All filters are optional. Returns paginated list with `package` and `placement` relations included.

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `per_page` | integer | ❌ | Default 10, max 100 |
| `status` | boolean | ❌ | `1` = active, `0` = inactive |
| `is_active` | boolean | ❌ | `1` or `0` |
| `banner_placement_id` | integer | ❌ | Filter by placement |
| `banner_package_id` | integer | ❌ | Filter by package |
| `level` | string | ❌ | `carousel`, `middle`, `footer` |
| `search` | string | ❌ | Search by banner name |

**Response includes:**
```json
{
  "success": true,
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "name": "Pranav Lodge Banner",
        "image": "https://...",
        "duration": "7",
        "level": "carousel",
        "image_orientation": "landscape",
        "status": 1,
        "is_active": true,
        "start_date": "2026-05-01T18:30:00.000000Z",
        "end_date": "2026-05-08T18:30:00.000000Z",
        "impressions": 1240,
        "clicks": 53,
        "redirect_url": "https://tourkokan.com",
        "bannerable_type": "site",
        "bannerable_id": 5,
        "bannerable": { "id": 5, "name": "Pranav Lodge" },
        "package": { "id": 9, "name": "7-Day Standard", "duration_days": 7, "price": 499 },
        "placement": { "id": 21, "code": "home_carousel", "description": "Home carousel", "screen": "home", "width": 1080, "height": 400 },
        "meta_data": null
      }
    ]
  }
}
```

---

### 3.2 Get Single Banner
```
POST /admin/v2/getBanner
```
| Field | Type | Required |
|-------|------|----------|
| `id` | integer | ✅ |

---

### 3.3 Create Banner (Admin-created)
```
POST /admin/v2/addBanner
Content-Type: multipart/form-data
```

> ⚠️ `level`, `duration`, and `image_orientation` are **standalone fields** selected by the admin. They are NOT derived from package or placement. Fetch their options from the dropdown APIs listed in Section 4.

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `name` | string | ✅ | Unique, 2–40 chars |
| `image` | file | ✅ | jpeg / jpg / png / webp |
| `mr_image` | file | ❌ | Marathi creative (same formats). Served automatically to users with Marathi language setting; falls back to `image` if absent |
| `start_date` | string | ✅ | Format: `Y-m-d H:i:s` e.g. `2026-06-01 00:00:00` |
| `duration` | string | ✅ | Days the banner runs. Values from `bannerDaysDD`: `1`, `3`, `5`, `7` |
| `level` | string | ✅ | Position on screen. Values from `bannerLevelsDD`: `carousel`, `middle`, `footer` |
| `image_orientation` | string | ✅ | Image layout. Values from `bannerImageOrientationDD`: `potrait`, `landscape` |
| `bannerable_type` | string | ✅ | Entity type the banner is linked to. Values: `Site` or `Place` |
| `bannerable_id` | integer | ✅ | ID of the linked Site or Place |
| `redirect_url` | string | ❌ | URL to open when banner is tapped |
| `status` | boolean | ❌ | Default `false` (inactive until approved) |
| `meta_data` | JSON string | ❌ | Extra metadata |

**Dropdowns needed on the form (fetch on mount in parallel):**

| Form field | Dropdown API | Send value | Display label |
|---|---|---|---|
| `level` | `POST /admin/v2/bannerLevelsDD` | `code` | `name` |
| `duration` | `POST /admin/v2/bannerDaysDD` | `code` | `name` |
| `image_orientation` | `POST /admin/v2/bannerImageOrientationDD` | `code` | `name` |
| `banner_package_id` | `POST /admin/v2/bannerFormDD` | `id` | `name + " — ₹" + price` |
| `banner_placement_id` | from selected package's `allowed_placements` | `id` | `code + " (" + width + "×" + height + ")"` |
| `bannerable_type` | hardcoded | `Site` or `Place` | `Site` or `Place` |
| `bannerable_id` | depends on `bannerable_type` | `id` | `name` |

> After a package is selected, filter the placement dropdown to only show placements whose `code` is in the package's `allowed_placements` array. Show the recommended dimensions (`width × height`) next to each placement option.

---

### 3.4 Update Banner
```
POST /admin/v2/updateBanner
Content-Type: multipart/form-data
```
| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `id` | integer | ✅ | |
| `image` | file | ❌ | Replaces old image if provided |
| `mr_image` | file | ❌ | Marathi creative — replaces old one if provided (old file deleted from storage) |
| `start_date` | string | ❌ | Format: `Y-m-d H:i:s` |
| `duration` | string | ❌ | `1`, `3`, `5`, `7` |
| `level` | string | ❌ | `carousel`, `middle`, `footer` |
| `image_orientation` | string | ❌ | `potrait`, `landscape` |
| `status` | boolean | ❌ | |
| `bannerable_type` | string | ❌ | Required if `bannerable_id` sent. `Site` or `Place` |
| `bannerable_id` | integer | ❌ | Required if `bannerable_type` sent |
| `redirect_url` | string | ❌ | URL to open when banner is tapped |
| `meta_data` | JSON string | ❌ | |

---

### 3.5 Delete Banner
```
POST /admin/v2/deleteBanner
```
| Field | Type | Required |
|-------|------|----------|
| `id` | integer | ✅ |

---

### 3.6 Change Banner Status (Approve / Reject)
```
POST /admin/v2/changeBannerStatus
```
| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `banner_id` | integer | ✅ | |
| `status` | string | ✅ | One of: `approved`, `rejected`, `pending`, `expired` |

**Example — approve a banner:**
```json
{ "banner_id": 3, "status": "approved" }
```

---

## Section 4 — Dropdown Helper APIs

Call these to populate select inputs in forms.

### Banner Form Dropdown (Packages + Placements — merged)
```
POST /admin/v2/bannerFormDD
```
Returns only **active** packages. Each package's `allowed_placements` array contains full placement objects (ordered by ID desc) instead of plain codes — call this **once** on form mount to populate both the package selector and the placement selector.

```json
{
  "data": [
    {
      "id": 1,
      "name": "Basic Starter",
      "duration_days": 7,
      "price": "499.00",
      "allowed_placements": [
        { "id": 10, "code": "CITY_MIDDLE", "description": "Middle banner on city page", "screen": "CityDetail", "width": 1200, "height": 400 },
        { "id": 8,  "code": "HOME_MIDDLE", "description": "Middle banner on home screen", "screen": "Home", "width": 1080, "height": 400 }
      ]
    },
    {
      "id": 2,
      "name": "Premium",
      "duration_days": 30,
      "price": "1499.00",
      "allowed_placements": [
        { "id": 12, "code": "APP_SPLASH", "description": "Full screen splash on app load", "screen": "App", "width": 1080, "height": 1920 }
      ]
    }
  ]
}
```

**Frontend usage:**
1. On form mount → call `bannerFormDD` once, store result in state
2. Render **Package** select from `data` array
3. On package select → render **Placement** select from `selectedPackage.allowed_placements`
4. On placement select → show `width × height` as recommended image size hint to admin

---

### Banner Duration Options
```
POST /admin/v2/bannerDaysDD
```
```json
{
  "data": [
    { "id": 1, "name": "1 Day",  "code": "1" },
    { "id": 2, "name": "3 Day",  "code": "3" },
    { "id": 3, "name": "5 Day",  "code": "5" },
    { "id": 4, "name": "7 Day",  "code": "7" }
  ]
}
```

### Banner Level Options
```
POST /admin/v2/bannerLevelsDD
```
```json
{
  "data": [
    { "id": 1, "name": "Carousel", "code": "carousel" },
    { "id": 2, "name": "Middle",   "code": "middle"   },
    { "id": 3, "name": "Footer",   "code": "footer"   }
  ]
}
```

### Image Orientation Options
```
POST /admin/v2/bannerImageOrientationDD
```
```json
{
  "data": [
    { "id": 1, "name": "Potrait",   "code": "potrait"   },
    { "id": 2, "name": "Landscape", "code": "landscape" }
  ]
}
```

---

## React Admin Panel — Implementation Steps

### Folder Structure

```
src/
  views/
    banners/
      BannerList.jsx          ← main list with approve/reject
      BannerForm.jsx          ← add / edit banner
      BannerPlacements.jsx    ← placement CRUD
      BannerPackages.jsx      ← package CRUD
  services/
    bannerService.js          ← all API calls
```

---

### Step 1 — Add Routes in `src/routes.js`

```js
import BannerList        from './views/banners/BannerList'
import BannerForm        from './views/banners/BannerForm'
import BannerPlacements  from './views/banners/BannerPlacements'
import BannerPackages    from './views/banners/BannerPackages'

{ path: '/banners',             element: <BannerList /> },
{ path: '/banners/add',         element: <BannerForm /> },
{ path: '/banners/edit/:id',    element: <BannerForm /> },
{ path: '/banner-placements',   element: <BannerPlacements /> },
{ path: '/banner-packages',     element: <BannerPackages /> },
```

---

### Step 2 — Add Nav Items in `src/_nav.js`

```js
{
  component: CNavItem,
  name: 'Banners',
  to: '/banners',
  icon: <CIcon icon={cilImage} />,
},
{
  component: CNavItem,
  name: 'Banner Placements',
  to: '/banner-placements',
  icon: <CIcon icon={cilLayers} />,
},
{
  component: CNavItem,
  name: 'Banner Packages',
  to: '/banner-packages',
  icon: <CIcon icon={cilTag} />,
},
```

---

### Step 3 — Create `src/services/bannerService.js`

```js
import axios from 'axios'

const BASE = '/admin/v2'

const authHeader = () => ({
  headers: { Authorization: `Bearer ${localStorage.getItem('token')}` }
})

// ── Banners ──────────────────────────────────────────────────────
// filters: { per_page, status, is_active, banner_placement_id, banner_package_id, level, search }
export const listBanners        = (filters = {}) => axios.post(`${BASE}/listBanners`, filters, authHeader())
export const getBanner          = (id)     => axios.post(`${BASE}/getBanner`, { id }, authHeader())
export const deleteBanner       = (id)     => axios.post(`${BASE}/deleteBanner`, { id }, authHeader())
export const changeBannerStatus = (banner_id, status) =>
  axios.post(`${BASE}/changeBannerStatus`, { banner_id, status }, authHeader())

export const addBanner = (formData) =>
  axios.post(`${BASE}/addBanner`, formData, {
    headers: { ...authHeader().headers, 'Content-Type': 'multipart/form-data' }
  })

export const updateBanner = (formData) =>
  axios.post(`${BASE}/updateBanner`, formData, {
    headers: { ...authHeader().headers, 'Content-Type': 'multipart/form-data' }
  })

// ── Packages ─────────────────────────────────────────────────────
export const listPackages   = ()      => axios.post(`${BASE}/listBannerPackages`, {}, authHeader())
export const getPackage     = (id)    => axios.post(`${BASE}/getBannerPackage`, { id }, authHeader())
export const addPackage     = (data)  => axios.post(`${BASE}/addBannerPackage`, data, authHeader())
export const updatePackage  = (data)  => axios.post(`${BASE}/updateBannerPackage`, data, authHeader())
export const deletePackage  = (id)    => axios.post(`${BASE}/deleteBannerPackage`, { id }, authHeader())

// ── Placements ────────────────────────────────────────────────────
export const listPlacements   = ()      => axios.post(`${BASE}/listBannerPlacements`, {}, authHeader())
export const getPlacement     = (id)    => axios.post(`${BASE}/getBannerPlacement`, { id }, authHeader())
export const addPlacement     = (data)  => axios.post(`${BASE}/addBannerPlacement`, data, authHeader())
export const updatePlacement  = (data)  => axios.post(`${BASE}/updateBannerPlacement`, data, authHeader())
export const deletePlacement  = (id)    => axios.post(`${BASE}/deleteBannerPlacement`, { id }, authHeader())

// ── Dropdowns ─────────────────────────────────────────────────────
export const getBannerDaysDD         = () => axios.post(`${BASE}/bannerDaysDD`, {}, authHeader())
export const getBannerLevelsDD       = () => axios.post(`${BASE}/bannerLevelsDD`, {}, authHeader())
export const getBannerOrientationsDD = () => axios.post(`${BASE}/bannerImageOrientationDD`, {}, authHeader())
export const getBannerFormDD         = () => axios.post(`${BASE}/bannerFormDD`, {}, authHeader())
```

---

### Step 4 — BannerList.jsx

Key things to implement:

```jsx
import { listBanners, deleteBanner, changeBannerStatus } from '../../services/bannerService'

// State
const [banners, setBanners] = useState([])

// Load
useEffect(() => {
  listBanners().then(r => setBanners(r.data.data.data))
}, [])

// Approve / Reject buttons in table row
<CButton color="success" onClick={() => handleStatus(banner.id, 'approved')}>Approve</CButton>
<CButton color="danger"  onClick={() => handleStatus(banner.id, 'rejected')}>Reject</CButton>

const handleStatus = async (id, status) => {
  await changeBannerStatus(id, status)
  // reload list
}
```

**Table columns:**
| Column | Field |
|--------|-------|
| Name | `name` |
| Image | `image` (thumbnail) |
| Level | `level` |
| Duration | `duration` days |
| Orientation | `image_orientation` |
| Package | `package.name` |
| Placement | `placement.code` |
| Linked To | `bannerable.name` |
| Start Date | `start_date` |
| End Date | `end_date` |
| Impressions | `impressions` |
| Clicks | `clicks` |
| Active | `is_active` toggle |
| Status | `status` badge (`pending` / `approved` / `rejected` / `expired`) |
| Actions | Edit / Delete / Approve / Reject |

**Filter bar UI (wire to `listBanners` filters):**
- Search input → `search`
- Level select → `level` (options from `bannerLevelsDD`)
- Placement select → `banner_placement_id` (options from `listBannerPlacements`)
- Package select → `banner_package_id` (options from `listBannerPackages`)
- Status toggle → `status` (`1` / `0`)
- Active toggle → `is_active` (`1` / `0`)

---

### Step 5 — BannerForm.jsx

```jsx
// On mount, fetch all dropdowns in parallel
useEffect(() => {
  Promise.all([
    getBannerDaysDD(),
    getBannerLevelsDD(),
    getBannerOrientationsDD(),
  ]).then(([days, levels, orientations]) => {
    setDays(days.data.data)
    setLevels(levels.data.data)
    setOrientations(orientations.data.data)
  })

  if (id) {
    getBanner(id).then(r => {
      // populate form fields from r.data.data
    })
  }
}, [])

// On mount: fetch all 3 dropdowns in parallel
useEffect(() => {
  Promise.all([
    getBannerDaysDD(),
    getBannerLevelsDD(),
    getBannerOrientationsDD(),
  ]).then(([days, levels, orientations]) => {
    setDays(days.data.data)
    setLevels(levels.data.data)
    setOrientations(orientations.data.data)
  })

  if (id) {
    getBanner(id).then(r => {
      const b = r.data.data
      setForm({
        name: b.name,
        start_date: b.start_date,
        duration: String(b.duration),
        level: b.level,
        image_orientation: b.image_orientation,
        bannerable_type: b.bannerable_type,
        bannerable_id: b.bannerable_id,
        redirect_url: b.redirect_url || '',
        status: b.status,
      })
    })
  }
}, [])

// When bannerable_type changes, fetch the entity list for bannerable_id dropdown
useEffect(() => {
  if (form.bannerable_type === 'Site') {
    // fetch sites list → setSiteOptions(...)
  } else if (form.bannerable_type === 'Place') {
    // fetch places list → setPlaceOptions(...)
  }
}, [form.bannerable_type])

// Submit — always FormData because of file upload
const handleSubmit = async (e) => {
  e.preventDefault()
  const fd = new FormData()
  fd.append('name', form.name)
  fd.append('start_date', form.start_date)          // "2026-06-01 00:00:00"
  fd.append('duration', form.duration)               // "1" | "3" | "5" | "7"
  fd.append('level', form.level)                     // "carousel" | "middle" | "footer"
  fd.append('image_orientation', form.image_orientation) // "potrait" | "landscape"
  fd.append('bannerable_type', form.bannerable_type) // "Site" | "Place"
  fd.append('bannerable_id', form.bannerable_id)
  if (form.redirect_url) fd.append('redirect_url', form.redirect_url)
  if (form.image) fd.append('image', form.image)     // only when new file selected
  if (id) {
    fd.append('id', id)
    await updateBanner(fd)
  } else {
    await addBanner(fd)
  }
  navigate('/banners')
}
```

**Form fields:**

| Field | Input type | Source |
|---|---|---|
| `name` | text | — |
| `image` | file (jpg/png/webp) | — |
| `start_date` | datetime picker | format to `Y-m-d H:i:s` |
| `duration` | select | `getBannerDaysDD()` → use `code` as value |
| `level` | select | `getBannerLevelsDD()` → use `code` as value |
| `image_orientation` | select | `getBannerOrientationsDD()` → use `code` as value |
| `bannerable_type` | select | hardcoded: `Site`, `Place` |
| `bannerable_id` | select | fetch sites/places based on `bannerable_type` selection |
| `redirect_url` | url text input | — (optional) |
| `status` | toggle | boolean |

> `bannerable_type` and `bannerable_id` together identify which site or place this banner is attached to. When `bannerable_type` changes, re-fetch the entity list and reset `bannerable_id`.

---

### Step 6 — BannerPlacements.jsx

Simple table + modal form. No file uploads.

**Form fields for create/edit:**
- `code` — text (snake_case, e.g. `home_carousel`) — disabled on edit
- `description` — text
- `screen` — text (e.g. `home`, `site_detail`)
- `width` — number input
- `height` — number input
- `is_active` — toggle

```jsx
const handleSave = async () => {
  if (editId) {
    await updatePlacement({ id: editId, ...form })
  } else {
    await addPlacement(form)
  }
  loadPlacements()
  setModalOpen(false)
}
```

---

### Step 7 — BannerPackages.jsx

**Form fields for create/edit:**
- `name` — text (e.g. `7-Day Standard`)
- `duration_days` — number input
- `price` — number input (₹)
- `allowed_placements` — **multi-select** from placement codes (call `listPlacements()` to populate)
- `is_active` — toggle

```jsx
// Load placements for multi-select options
useEffect(() => {
  listPlacements().then(r => {
    setPlacementOptions(r.data.data.map(p => ({ value: p.code, label: p.description || p.code })))
  })
}, [])

// allowed_placements sent as array
const handleSave = async () => {
  await addPackage({
    name: form.name,
    duration_days: Number(form.duration_days),
    price: Number(form.price),
    allowed_placements: form.allowed_placements,  // string[]
    is_active: form.is_active,
  })
}
```

---

## Implementation Order

Follow this exact order — each step depends on the previous:

1. **Create `bannerService.js`** — all API functions in one place
2. **Build `BannerPlacements` page** — create at least 2–3 placements (needed for packages)
3. **Build `BannerPackages` page** — create packages using those placement codes
4. **Build `BannerList` page** — list all banners with approve/reject actions
5. **Build `BannerForm` page** — add/edit banner with file upload
6. **Wire routes + nav** — add to `routes.js` and `_nav.js`

---

## Status Reference

| Status | Meaning |
|--------|---------|
| `pending` | Just created, waiting for admin review |
| `approved` | Live — shown in app if within date range |
| `rejected` | Not shown, admin rejected |
| `expired` | Past end date |

Only `approved` banners with `is_active: true` and within `start_date`–`end_date` are served to the app.

---

## Common Mistakes to Avoid

- Always create **Placements before Packages** — packages validate placement codes
- `start_date` for `addBanner` must be in exact format: `2026-06-01 00:00:00`
- `allowed_placements` in package must be an array of strings matching `code` in placements table
- `addBanner` and `updateBanner` require `multipart/form-data` — use `FormData`, not JSON
- `changeBannerStatus` uses `banner_id` not `id`
