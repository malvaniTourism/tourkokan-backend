**tourkokan-backend** 

# Advertising Banner & Paid Promotion System – Requirement Documentation

## 1. Objective
Introduce a **paid advertising banner system** in the mobile app to allow admins to create and manage multiple advertising packages and placements. The system should support time-based packages, screen-based placements, popup ads, and city-detail page banners, with proper ownership (`user_id`) for advertisers.

This document is intended to be **implementation-ready** and suitable for **agentic AI / developer handoff**.
Payload:
```json
{
  "banner_id": 1,
  "status": "approved"
}
```


## 2. Business Use Case
- Enable **monetization** through paid banners.
- Allow **local businesses / users** to advertise via banner packages.
- Provide **flexible placement control** across app screens.
- Support **time-limited campaigns** (1 day, 1 week, 1 month).
- Maintain **auditability & ownership** of ads.

---

## 3. Existing System Assumption
- There is an existing `banners` table.
- Banners are currently static / admin-managed.
- No ownership, pricing, duration, or placement logic exists.

This feature **refines and extends** the existing banner system.

---

## 4. High-Level Feature Scope
### Admin Capabilities
- Create advertising packages
- Define banner placements
- Assign duration & price
- Approve / reject user-submitted banners
- Activate / deactivate campaigns

### User (Advertiser) Capabilities (Future-ready)
- Purchase banner packages
- Upload creatives
- Select target placement
- View campaign status

---

## 5. Banner Placement Types
### 5.1 Home Screen
| Placement Code | Description | Size Type |
|---------------|------------|----------|
| HOME_TOP | Top banner | Landscape |
| HOME_MIDDLE | Middle banner | Short height |
| HOME_FOOTER | Footer banner | Short height |

### 5.2 Popup Advertising
| Placement Code | Description | Behavior |
|---------------|------------|----------|
| POPUP_ONCE | Popup on app load | Shown only once per install/session |

### 5.3 City Detail Page
| Placement Code | Description | Size Type |
|---------------|------------|----------|
| CITY_AFTER_INFO | After city info section | Short banner |
| CITY_FOOTER | Footer banner | Short banner |

---

## 6. Advertising Packages
### 6.1 Duration Types
| Code | Duration |
|----|----------|
| DAY_1 | 1 Day |
| WEEK_1 | 7 Days |
| MONTH_1 | 30 Days |

### 6.2 Package Definition
Each package defines:
- Duration
- Allowed placements
- Price
- Banner size constraints

---

## 7. Database Schema Design

### 7.1 Users Table (Existing)
Used for advertisers

Already existing table

---

### 7.2 Banner Packages
Defines sellable advertising products

```
banner_packages
- id
- name
- duration_days
- price
- allowed_placements (JSON)
- is_active
- created_at
- updated_at
```

Example `allowed_placements`:
```json
["HOME_TOP", "HOME_MIDDLE"]
```

---

### 7.3 Banner Placements (Master)

```
banner_placements
- id
- code
- description
- screen
- width
- height
- is_active
```

---

### 7.4 Banners (Refined Existing Table)

```
banners
- id
- user_id (FK -> users.id)
- banner_package_id (FK -> banner_packages.id)
- banner_placement_id (FK -> banner_placements.id)
- title
- image_url
- redirect_url
- start_date
- end_date
- status (pending | approved | rejected | expired)
- impressions
- clicks
- is_active
- created_at
- updated_at
```

---

## 8. Relationships (Laravel Eloquent)

### User
- hasMany Banners

### Banner
- belongsTo User
- belongsTo BannerPackage
- belongsTo BannerPlacement

### BannerPackage
- hasMany Banners

### BannerPlacement
- hasMany Banners

---

## 9. API Design

### 9.1 Admin APIs

#### Create Banner Package
```
POST /api/admin/banner-packages
```
Payload:
```json
{
  "name": "Home Top – 1 Week",
  "duration_days": 7,
  "price": 1500,
  "allowed_placements": ["HOME_TOP"],
  "is_active": true
}
```

---

#### Create Banner Placement
```
POST /api/admin/banner-placements
```

---

#### Approve / Reject Banner
```
PATCH /api/admin/banners/{id}/status
```

---

### 9.2 User APIs

#### Create Banner Campaign
```
POST /api/banners
```
Payload:
```json
{
  "banner_package_id": 1,
  "banner_placement_id": 2,
  "title": "Hotel Promotion",
  "image_url": "...",
  "redirect_url": "...",
  "start_date": "2026-02-01"
}
```

---

### 9.3 Public APIs (App Consumption)

#### Fetch Active Banners
```
GET /api/banners?placement=HOME_TOP
```

Filtering Logic:
- current date between start & end
- status = approved
- is_active = true

---

## 10. Business Rules
- Only **approved banners** are visible
- One popup banner per user session
- Banner auto-expires after end_date
- Admin controls pricing & placements
- A banner must match its package’s allowed placements

---

## 11. Non-Functional Requirements
- Optimized for fast banner loading
- CDN-ready image URLs
- Impression & click tracking ready
- Scalable for future programmatic ads

---

## 12. Future Enhancements (Out of Scope)
- Payment gateway integration
- Campaign analytics dashboard
- Geo-targeting
- A/B banner testing

---

## 13. Implementation Readiness Checklist
- [ ] Create new migrations
- [ ] Refactor existing banner table
- [ ] Add Eloquent relationships
- [ ] Implement admin APIs
- [ ] Implement app banner fetch logic
- [ ] Add expiry & popup logic

---

**Status:** Requirement finalized – ready for implementation planning