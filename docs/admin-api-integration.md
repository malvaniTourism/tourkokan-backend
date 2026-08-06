# Tourkokan Admin API — Integration Reference

For the `admin-panel` repo (React 18 + Vite + CoreUI).

**Base URL** — `{VITE_ADMIN_API_BASE}` → `/admin/v2`
**Auth** — JWT. `Authorization: Bearer <token>` on every request except login.
**Method** — every endpoint is `POST` unless marked otherwise, including reads.
**Role** — every `/admin/v2/*` endpoint requires the `admin` or `superadmin` role.

Generated from the live route table with validation rules lifted from the controllers, so
the field lists below are what the server actually enforces.

---

## 0. Read this before writing any service call

### 0.1 A failed request usually returns HTTP 200

`sendResponse` and most validation paths answer **200** with `success: false`.
**Branching on `res.status` alone will treat failures as successes.** Always read `success`.

```js
// src/services/http.js
const post = async (url, body) => {
  const res  = await axios.post(url, body)
  const data = res.data
  if (!data?.success) throw new ApiError(data?.message ?? 'Request failed')
  return data.data
}
```

### 0.2 `message` is a string OR a field-errors object

```json
{ "success": false, "message": "Only pending requests can be approved." }
{ "success": false, "message": { "name": ["The name field is required."] } }
```

Business errors give a string; validation failures give `field → [errors]`. Render the
object form against the matching form inputs.

### 0.3 Paginated lists live at `data.data`

```json
{ "success": true, "data": { "current_page": 1, "data": [...], "last_page": 3, "total": 42 } }
```

Send `page` and `per_page`. **`per_page` is capped at 30** — larger values are silently
clamped, so a "show 100 per page" control will quietly return 30.

### 0.4 A 403 means the role is missing, not the token

`AdminAccessMiddleware` requires role code `admin` or `superadmin` in `user_roles`. A valid
token for a user without that role gets 403 on every admin endpoint. This group was
unguarded until 2026-08-05 — if you have an old admin account that "used to work", check its
roles.

### 0.5 Uploads are multipart

Any endpoint with an image field takes `multipart/form-data`, one file per field. Send
nested structures (`limits`, `attribute_schema`, `allowed`, `social_media`) as **JSON
strings** in that case; as real JSON when the request body is JSON.

---


## Authentication

Login and session. Everything else needs the token from here.


### `GET /admin/api/user-profile`

_No validation — send an empty body, or see the controller._


### `POST /admin/login`

| Field | Rules |
|---|---|
| `email` | required · email |
| `password` | required · string |


### `POST /admin/logout`

_No validation — send an empty body, or see the controller._


### `POST /admin/refresh`

_No validation — send an empty body, or see the controller._


### `POST /admin/register`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/allUsers`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/auth/login`

| Field | Rules |
|---|---|
| `email` | required · email |
| `password` | required · string |


### `POST /admin/v2/auth/refresh`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/auth/register`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/auth/sendOtp`

| Field | Rules |
|---|---|
| `email` | sometimes · nullable · required_without:mobile · email · exists:users,email_hash, |
| `mobile` | sometimes · nullable · required_without:email |


### `POST /admin/v2/auth/verifyOtp`

| Field | Rules |
|---|---|
| `email` | sometimes · nullable · required_without:mobile · email |
| `mobile` | sometimes · nullable · required_without:email |
| `otp` | required |
| `delete` | nullable · boolean |


### `POST /admin/v2/listUsers`

| Field | Rules |
|---|---|
| `apitype` | required · string · in:list,dropdown |
| `search` | nullable · string · max:100 |
| `per_page` | nullable · integer · min:1 · max:30 |


## Sites (places & businesses)

The core entity. Vendor businesses and platform-curated places are both sites, distinguished by `user_id` being set.


### `POST /admin/v2/addSite`

| Field | Rules |
|---|---|
| `name` | ['required', 'string', 'between:2,100', $nameRule] |
| `parent_id` | nullable · exists:sites,id |
| `user_id` | nullable · exists:users,id |
| `categories` | required · array |
| `categories.*` | exists:categories,id |
| `bus_stop_type` | nullable · in:Stop,Depo |
| `tag_line` | required · string · between:2,100 |
| `description` | required · string |
| `domain_name` | nullable · string |
| `logo` | nullable · mimes:jpeg,jpg,png,webp · max:1024 |
| `icon` | ['nullable', 'mimes:jpeg,jpg,png,webp', new ImageGuideline('icon')] |
| `image` | ['nullable', 'mimes:jpeg,jpg,png,webp', new ImageGuideline('hero_site')] |
| `status` | boolean:true,false |
| `latitude` | nullable · required_with:longitude · between:-90,90 |
| `longitude` | nullable · required_with:latitude · between:-90,90 |
| `pin_code` | nullable · numeric |
| `speciality` | nullable · json |
| `rules` | nullable · json |
| `social_media` | nullable · json |
| `meta_data` | nullable · json |


### `POST /admin/v2/allSubmissions`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/approveSite`

| Field | Rules |
|---|---|
| `id` | required · numeric · exists:sites,id |


### `POST /admin/v2/deleteSite`

| Field | Rules |
|---|---|
| `id` | required · exists:sites,id |


### `POST /admin/v2/getSite`

| Field | Rules |
|---|---|
| `id` | required · exists:sites,id |


### `POST /admin/v2/pendingSites`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/rejectSite`

| Field | Rules |
|---|---|
| `id` | required · numeric · exists:sites,id |
| `rejection_reason` | required · string · max:1000 |


### `POST /admin/v2/sites`

| Field | Rules |
|---|---|
| `search` | sometimes · nullable · string · alpha · max:255 |
| `type` | sometimes · required · string · max:255 · in:bus |
| `apitype` | required · string · max:255 · in:list,dropdown |
| `category` | nullable · exists:categories,code |
| `parent_id` | nullable · exists:sites,parent_id |
| `global` | sometimes · boolean |


### `POST /admin/v2/updateSite`

| Field | Rules |
|---|---|
| `id` | required · exists:sites,id |
| `name` | ['sometimes', 'required', 'string', 'between:2,100', Rule::unique('sites', 'name')->ignore($request->id)] |
| `parent_id` | sometimes · required · exists:sites,id |
| `user_id` | sometimes · required · exists:users,id |
| `categories` | nullable · array |
| `categories.*` | exists:categories,id |
| `bus_stop_type` | sometimes · required · in:Stop,Depo |
| `tag_line` | sometimes · required · string · between:2,100 |
| `description` | sometimes · required · string |
| `domain_name` | sometimes · required · string |
| `logo` | sometimes · nullable · mimes:jpeg,jpg,png,webp · max:1024 |
| `icon` | ['sometimes', 'nullable', 'mimes:jpeg,jpg,png,webp', new ImageGuideline('icon')] |
| `image` | ['sometimes', 'nullable', 'mimes:jpeg,jpg,png,webp', new ImageGuideline('hero_site')] |
| `status` | sometimes · required · boolean:true,false |
| `latitude` | sometimes · required · required_with:longitude · between:-90,90 |
| `longitude` | sometimes · required · required_with:latitude · between:-90,90 |
| `pin_code` | sometimes · required · numeric |
| `is_hot_place` | sometimes · required · boolean:true,false |
| `speciality` | sometimes · required · json |
| `rules` | sometimes · required · json |
| `social_media` | sometimes · required · json |
| `meta_data` | sometimes · required · json |


## Categories

The site taxonomy — Accommodation, Food, Local Services, Shopping, Tour & Travel.


### `POST /admin/v2/addCategory`

| Field | Rules |
|---|---|
| `name` | ['required', 'string', 'between:2,100', Rule::unique('categories', 'name')->whereNull('deleted_at')] |
| `parent_id` | sometimes · integer · exists:categories,id |
| `description` | required · string |
| `icon` | ['nullable', 'mimes:jpeg,jpg,png,webp', new ImageGuideline('icon')] |
| `status` | boolean |
| `meta_data` | nullable · json |


### `POST /admin/v2/deleteCategory`

| Field | Rules |
|---|---|
| `id` | required · exists:categories,id |


### `POST /admin/v2/getCategory`

| Field | Rules |
|---|---|
| `id` | required · exists:categories,id |


### `POST /admin/v2/listcategories`

| Field | Rules |
|---|---|
| `parent_id` | nullable · exists:categories,id |
| `status` | nullable · boolean:true,false |
| `apitype` | required · string · in:list,dropdown |


### `POST /admin/v2/updateCategory`

| Field | Rules |
|---|---|
| `id` | required · exists:categories,id |
| `name` | ['sometimes', 'string', 'between:2,100', Rule::unique('categories', 'name')->ignore($request->id)->whereNul… |
| `parent_id` | sometimes · integer · exists:categories,id |
| `description` | sometimes · string |
| `icon` | ['sometimes', 'nullable', 'mimes:jpeg,jpg,png,webp', new ImageGuideline('icon')] |
| `status` | sometimes · boolean |
| `meta_data` | sometimes · nullable · json |


## Product taxonomy

Creating a product category **ships a new vendor vertical** — no migration, no app release.


### `POST /admin/v2/addProductCategory`

| Field | Rules |
|---|---|
| `name` | required · string · between:2,100 |
| `mr_name` | nullable · string · between:2,100 |
| `code` | required · string · max:60 · unique:product_categories,code · regex:/^[a-z][a-z0-9_]*$/ |
| `parent_id` | nullable · numeric · exists:product_categories,id |
| `description` | nullable · string · max:1000 |
| `icon` | nullable · mimes:jpeg,jpg,png,webp · max:2048 |
| `attribute_schema` | nullable · array |
| `booking_type` | ['nullable', 'string', 'in:' . implode(',', ProductCategory::BOOKING_TYPES)] |
| `status` | nullable · boolean |
| `sort_order` | nullable · integer · min:0 |


### `POST /admin/v2/deleteProductCategory`

| Field | Rules |
|---|---|
| `id` | required · numeric · exists:product_categories,id |


### `POST /admin/v2/getProductCategory`

| Field | Rules |
|---|---|
| `id` | required · numeric · exists:product_categories,id |


### `POST /admin/v2/listProductCategories`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/setAllowedProductCategories`

| Field | Rules |
|---|---|
| `category_id` | required · numeric · exists:categories,id |
| `allowed` | present · array |
| `allowed.*.product_category_id` | required · numeric · exists:product_categories,id |
| `allowed.*.is_required` | nullable · boolean |
| `allowed.*.max_products` | nullable · integer · min:1 |


### `POST /admin/v2/updateProductCategory`

| Field | Rules |
|---|---|
| `id` | required · numeric · exists:product_categories,id |
| `name` | sometimes · string · between:2,100 |
| `mr_name` | nullable · string · between:2,100 |
| `code` | sometimes · string · max:60 · regex:/^[a-z][a-z0-9_]*$/ · unique:product_categories,code, |
| `parent_id` | nullable · numeric · exists:product_categories,id · different:id |
| `description` | nullable · string · max:1000 |
| `icon` | nullable · mimes:jpeg,jpg,png,webp · max:2048 |
| `attribute_schema` | nullable · array |
| `booking_type` | ['nullable', 'string', 'in:' . implode(',', ProductCategory::BOOKING_TYPES)] |
| `status` | nullable · boolean |
| `sort_order` | nullable · integer · min:0 |


## Product moderation

Vendor listings awaiting review.


### `POST /admin/v2/approveProduct`

| Field | Rules |
|---|---|
| `id` | required · numeric · exists:products,id |


### `POST /admin/v2/featureProduct`

| Field | Rules |
|---|---|
| `id` | required · numeric · exists:products,id |
| `is_featured` | required · boolean |


### `POST /admin/v2/getProductAdmin`

| Field | Rules |
|---|---|
| `id` | required · numeric · exists:products,id |


### `POST /admin/v2/listAllProducts`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/pendingProducts`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/rejectProduct`

| Field | Rules |
|---|---|
| `id` | required · numeric · exists:products,id |
| `rejection_reason` | required · string · max:1000 |


## Plans & subscriptions

Vendor quotas. Going paid is a data change: activate a tier, assign vendors onto it.


### `POST /admin/v2/addPlan`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/assignPlan`

| Field | Rules |
|---|---|
| `user_id` | required · numeric · exists:users,id |
| `plan_id` | required · numeric · exists:plans,id |
| `months` | nullable · integer · min:1 · max:120 |


### `POST /admin/v2/listPlans`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/listSubscriptions`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/updatePlan`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/vendorUsageReport`

| Field | Rules |
|---|---|
| `user_id` | required · numeric · exists:users,id |


## Vendor role requests

Approving a `vendor` request also enrols the user on the free plan.


### `POST /admin/v2/approveRoleRequest`

| Field | Rules |
|---|---|
| `id` | required · exists:user_role_requests,id |
| `admin_note` | nullable · string · max:500 |


### `POST /admin/v2/rejectRoleRequest`

| Field | Rules |
|---|---|
| `id` | required · exists:user_role_requests,id |
| `admin_note` | required · string · max:500 |


### `POST /admin/v2/userRoleRequests`

_No validation — send an empty body, or see the controller._


## Events


### `POST /admin/v2/approveEvent`

| Field | Rules |
|---|---|
| `id` | required · exists:events,id |
| `admin_notes` | nullable · string |
| `is_featured` | nullable · boolean |
| `featured_until` | nullable · date |
| `send_notification` | nullable · boolean |


### `POST /admin/v2/createEvent`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/deleteEvent`

| Field | Rules |
|---|---|
| `id` | required · exists:events,id |


### `POST /admin/v2/deleteEventGallery`

| Field | Rules |
|---|---|
| `gallery_id` | required · exists:galleries,id |


### `POST /admin/v2/eventAnalytics`

| Field | Rules |
|---|---|
| `id` | required · exists:events,id |


### `POST /admin/v2/eventTypeDD`

| Field | Rules |
|---|---|
| `search` | nullable · string · max:100 |
| `is_hot_type` | nullable · boolean |
| `top_level` | nullable · boolean |
| `parent_id` | nullable · integer · exists:event_types,id |
| `status` | nullable |


### `POST /admin/v2/featureEvent`

| Field | Rules |
|---|---|
| `id` | required · exists:events,id |
| `is_featured` | required · boolean |
| `featured_until` | nullable · date |


### `POST /admin/v2/getEvent`

| Field | Rules |
|---|---|
| `id` | required · exists:events,id |


### `POST /admin/v2/getEventGallery`

| Field | Rules |
|---|---|
| `event_id` | required · exists:events,id |


### `POST /admin/v2/listEvents`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/pendingEvents`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/rejectEvent`

| Field | Rules |
|---|---|
| `id` | required · exists:events,id |
| `rejection_reason` | required · string |


### `POST /admin/v2/updateEvent`

| Field | Rules |
|---|---|
| `banner_image` | ['sometimes', 'nullable', new ImageGuideline('event')] |


### `POST /admin/v2/uploadEventGallery`

| Field | Rules |
|---|---|
| `event_id` | required · exists:events,id |
| `images` | required · array · min:1 · max:20 |
| `images.*` | ['required', 'image', 'mimes:jpeg,jpg,png,webp', new ImageGuideline('gallery')] |
| `title` | nullable · string · max:255 |
| `description` | nullable · string · max:500 |


## Banners & advertising

Packages, placements, and the banners themselves.


### `POST /admin/v2/addBanner`

| Field | Rules |
|---|---|
| `name` | required · string · unique:banners · between:2,40 |
| `image` | ['required', 'mimes:jpeg,jpg,png,webp', image-guideline rule] |
| `mr_image` | ['nullable', 'mimes:jpeg,jpg,png,webp', image-guideline rule] |
| `start_date` | required · date_format:Y-m-d H:i:s |
| `duration` | required · in: |
| `level` | required · in: |
| `image_orientation` | required · in: |
| `status` | boolean |
| `bannerable_type` | required · string |
| `bannerable_id` | required · numeric |
| `redirect_url` | nullable · url |
| `meta_data` | nullable · json |


### `POST /admin/v2/addBannerPackage`

| Field | Rules |
|---|---|
| `name` | required · string |
| `duration_days` | required · integer · min:1 |
| `price` | required · numeric · min:0 |
| `allowed_placements` | required · array |
| `allowed_placements.*` | string · exists:banner_placements,code |
| `is_active` | boolean |


### `POST /admin/v2/addBannerPlacement`

| Field | Rules |
|---|---|
| `code` | required · string · unique:banner_placements,code |
| `description` | nullable · string |
| `screen` | nullable · string |
| `width` | nullable · integer |
| `height` | nullable · integer |
| `is_active` | boolean |


### `POST /admin/v2/bannerFormDD`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/changeBannerStatus`

| Field | Rules |
|---|---|
| `banner_id` | required · exists:banners,id |
| `status` | required · in:approved,rejected,pending,expired |


### `POST /admin/v2/deleteBanner`

| Field | Rules |
|---|---|
| `id` | required · exists:banners,id |


### `POST /admin/v2/deleteBannerPackage`

| Field | Rules |
|---|---|
| `id` | required · exists:banner_packages,id |


### `POST /admin/v2/deleteBannerPlacement`

| Field | Rules |
|---|---|
| `id` | required · exists:banner_placements,id |


### `POST /admin/v2/getBanner`

| Field | Rules |
|---|---|
| `id` | required · exists:banners,id |


### `POST /admin/v2/getBannerPackage`

| Field | Rules |
|---|---|
| `id` | required · exists:banner_packages,id |


### `POST /admin/v2/getBannerPlacement`

| Field | Rules |
|---|---|
| `id` | required · exists:banner_placements,id |


### `POST /admin/v2/listBannerPackages`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/listBannerPlacements`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/listBanners`

| Field | Rules |
|---|---|
| `per_page` | sometimes · integer · min:1 · max:30 |
| `status` | sometimes · boolean |
| `is_active` | sometimes · boolean |
| `banner_placement_id` | sometimes · exists:banner_placements,id |
| `banner_package_id` | sometimes · exists:banner_packages,id |
| `level` | sometimes · in: |
| `search` | sometimes · string · max:100 |


### `POST /admin/v2/updateBanner`

| Field | Rules |
|---|---|
| `id` | required · exists:banners,id |
| `image` | ['nullable', 'mimes:jpeg,jpg,png,webp', image-guideline rule] |
| `mr_image` | ['nullable', 'mimes:jpeg,jpg,png,webp', image-guideline rule] |
| `start_date` | nullable · date_format:Y-m-d H:i:s |
| `duration` | nullable · in: |
| `level` | nullable · in: |
| `image_orientation` | nullable · in: |
| `status` | boolean |
| `bannerable_type` | nullable · required_with:bannerable_id · string |
| `bannerable_id` | nullable · required_with:bannerable_type · numeric |
| `redirect_url` | nullable · url |
| `meta_data` | nullable · json |


### `POST /admin/v2/updateBannerPackage`

| Field | Rules |
|---|---|
| `id` | required · exists:banner_packages,id |
| `name` | sometimes · string |
| `duration_days` | sometimes · integer · min:1 |
| `price` | sometimes · numeric · min:0 |
| `allowed_placements` | sometimes · array |
| `allowed_placements.*` | string · exists:banner_placements,code |
| `is_active` | sometimes · boolean |


### `POST /admin/v2/updateBannerPlacement`

| Field | Rules |
|---|---|
| `id` | required · exists:banner_placements,id |
| `code` | sometimes · string · unique:banner_placements,code, |
| `description` | sometimes · nullable · string |
| `screen` | sometimes · nullable · string |
| `width` | sometimes · nullable · integer |
| `height` | sometimes · nullable · integer |
| `is_active` | sometimes · boolean |


## Galleries


### `POST /admin/v2/deleteSiteGallery`

| Field | Rules |
|---|---|
| `gallery_id` | required · exists:galleries,id |


### `POST /admin/v2/getGallery`

| Field | Rules |
|---|---|
| `site_id` | sometimes · required · exists:sites,id |
| `search` | sometimes · nullable · string · max:255 |
| `category` | sometimes · required · exists:categories,code |


### `POST /admin/v2/getSiteGallery`

| Field | Rules |
|---|---|
| `site_id` | required · exists:sites,id |


### `POST /admin/v2/updateGallery`

| Field | Rules |
|---|---|
| `id` | required · exists:galleries,id |
| `title` | sometimes · required · string · between:2,100 |
| `description` | sometimes · required · string · between:2,500 |
| `path` | ['sometimes', 'nullable', 'mimes:jpeg,jpg,png,webp', new ImageGuideline('gallery')] |
| `is_url` | sometimes · boolean:true,false |
| `status` | sometimes · boolean:true,false |


### `POST /admin/v2/uploadSiteGallery`

| Field | Rules |
|---|---|
| `site_id` | required · exists:sites,id |
| `images` | required · array · min:1 · max:20 |
| `images.*` | ['required', 'image', 'mimes:jpeg,jpg,png,webp', new ImageGuideline('gallery')] |
| `title` | nullable · string · max:255 |
| `description` | nullable · string · max:500 |


## Comments moderation

Comments are hidden until approved — this is where they become visible.


### `POST /admin/v2/approveComment`

| Field | Rules |
|---|---|
| `id` | required · numeric · exists:comments,id |


### `POST /admin/v2/listComments`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/pendingComments`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/rejectComment`

| Field | Rules |
|---|---|
| `id` | required · numeric · exists:comments,id |


## Contact / enquiries


### `POST /admin/v2/getQueries`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/getQuery`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/replyQuery`

| Field | Rules |
|---|---|
| `id` | required · exists:contacts,id |
| `reply` | required · string |


### `POST /admin/v2/updateQuery`

| Field | Rules |
|---|---|
| `id` | required · exists:contacts,id |
| `status` | required · string · max:255 · in:read,unread,replied |


## Messaging

Admin → user direct messages.


### `POST /admin/v2/deleteMessage`

| Field | Rules |
|---|---|
| `id` | required · numeric · exists:admin_messages,id |


### `POST /admin/v2/listMessages`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/sendMessage`

| Field | Rules |
|---|---|
| `user_id` | required · numeric · exists:users,id |
| `message` | required · string · max:2000 |
| `subject` | nullable · string · max:255 |


## Analytics

Platform-wide dashboards.


### `POST /admin/v2/analytics/activeUsers`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/analytics/activityLogs`

| Field | Rules |
|---|---|
| `per_page` | sometimes · integer · min:1 · max:30 |
| `user_id` | sometimes · exists:users,id |
| `event_type` | sometimes · string · max:50 |
| `entity_type` | sometimes · string · max:50 |
| `entity_id` | sometimes · integer |
| `platform` | sometimes · in:mobile,web,admin |
| `success` | sometimes · boolean |
| `date_from` | sometimes · date |
| `date_to` | sometimes · date |
| `ip_address` | sometimes · string · max:45 |
| `search` | sometimes · string · max:100 |


### `POST /admin/v2/analytics/dashboardStats`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/analytics/eventTypeSummary`

| Field | Rules |
|---|---|
| `date_from` | sometimes · date |
| `date_to` | sometimes · date |
| `platform` | sometimes · in:mobile,web,admin |


### `POST /admin/v2/analytics/favouriteActivity`

| Field | Rules |
|---|---|
| `user_id` | sometimes · exists:users,id |
| `entity_type` | sometimes · in:site,event |
| `per_page` | sometimes · integer · min:1 · max:30 |
| `date_from` | sometimes · date |
| `date_to` | sometimes · date |


### `POST /admin/v2/analytics/loginHistory`

| Field | Rules |
|---|---|
| `user_id` | sometimes · exists:users,id |
| `per_page` | sometimes · integer · min:1 · max:30 |
| `date_from` | sometimes · date |
| `date_to` | sometimes · date |
| `platform` | sometimes · in:mobile,web,admin |


### `POST /admin/v2/analytics/platformBreakdown`

| Field | Rules |
|---|---|
| `date_from` | sometimes · date |
| `date_to` | sometimes · date |


### `POST /admin/v2/analytics/topEvents`

| Field | Rules |
|---|---|
| `limit` | sometimes · integer · min:1 · max:100 |
| `date_from` | sometimes · date |
| `date_to` | sometimes · date |
| `platform` | sometimes · in:mobile,web,admin |


### `POST /admin/v2/analytics/topRoutes`

| Field | Rules |
|---|---|
| `limit` | sometimes · integer · min:1 · max:100 |
| `date_from` | sometimes · date |
| `date_to` | sometimes · date |


### `POST /admin/v2/analytics/topSites`

| Field | Rules |
|---|---|
| `limit` | sometimes · integer · min:1 · max:100 |
| `date_from` | sometimes · date |
| `date_to` | sometimes · date |
| `platform` | sometimes · in:mobile,web,admin |


### `POST /admin/v2/analytics/userInterests`

| Field | Rules |
|---|---|
| `user_id` | sometimes · exists:users,id |
| `date_from` | sometimes · date |
| `date_to` | sometimes · date |


### `POST /admin/v2/analytics/userTimeline`

| Field | Rules |
|---|---|
| `user_id` | required · exists:users,id |
| `per_page` | sometimes · integer · min:1 · max:30 |
| `date_from` | sometimes · date |
| `date_to` | sometimes · date |


## Routes & stops

Bus routes.


### `POST /admin/v2/addRoute`

| Field | Rules |
|---|---|
| `route_no` | nullable · integer · min:1 · unique:routes,route_no |
| `name` | required · string · max:255 |
| `source_place_id` | required · integer · exists:sites,id |
| `destination_place_id` | required · integer · exists:sites,id · different:source_place_id |
| `bus_type_id` | required · integer · exists:bus_types,id |
| `start_time` | required · date_format:H:i:s |
| `end_time` | required · date_format:H:i:s |
| `total_time` | nullable · date_format:H:i:s |
| `delayed_time` | nullable · date_format:H:i:s |
| `distance` | nullable · numeric · min:0 |
| `working_days` | nullable · string · max:255 |
| `description` | nullable · string |
| `meta_data` | nullable · array |
| `status` | nullable · boolean |


### `POST /admin/v2/deleteRoute`

| Field | Rules |
|---|---|
| `id` | required · exists:routes,id |


### `POST /admin/v2/massRouteStopsUpdate`

| Field | Rules |
|---|---|
| `route_stops` | required · array · min:1 |
| `route_stops.*.id` | required · integer · exists:route_stops,id |
| `route_stops.*.serial_no` | required · integer · min:1 |
| `route_stops.*.route_id` | required · integer · exists:routes,id |
| `route_stops.*.site_id` | required · integer · exists:sites,id |
| `route_stops.*.arr_time` | required · date_format:H:i:s |
| `route_stops.*.dept_time` | required · date_format:H:i:s |
| `route_stops.*.total_time` | required · date_format:H:i:s |
| `route_stops.*.delayed_time` | required · date_format:H:i:s |
| `route_stops.*.distance` | nullable · numeric · min:0 |


### `POST /admin/v2/routeDetails`

| Field | Rules |
|---|---|
| `id` | required · exists:routes,id |


### `POST /admin/v2/routes`

| Field | Rules |
|---|---|
| `source_place_id` | nullable · required_with:destination_place_id · exists:sites,id |
| `destination_place_id` | nullable · required_with:source_place_id · exists:sites,id |
| `search` | nullable · string · alpha · max:255 |
| `apitype` | required · string · max:255 · in:list,dropdown |
| `with_stops` | sometimes · required · boolean:true,false |
| `per_page` | nullable · integer · max:30 · min:1 |


### `POST /admin/v2/routesUpdate`

| Field | Rules |
|---|---|
| `id` | required · exists:routes,id |
| `source_place_id` | sometimes · required · integer · exists:sites,id |
| `destination_place_id` | sometimes · required · integer · exists:sites,id |
| `bus_type_id` | sometimes · required · integer · exists:bus_types,id |
| `name` | sometimes · required · string · max:255 |
| `description` | sometimes · nullable · string |
| `distance` | sometimes · required · regex:/^\d+(\.\d{1,2})?$/ |
| `meta_data` | sometimes · nullable · array |
| `start_time` | sometimes · required · date_format:H:i:s |
| `end_time` | sometimes · required · date_format:H:i:s |
| `delayed_time` | sometimes · nullable · date_format:H:i:s |


## Reference data


### `POST /admin/api/bustype`

| Field | Rules |
|---|---|
| `type` | required · string · unique:bus_types · between:2,100 |
| `logo` | required · mimes:jpeg,jpg,png,webp · max:2048 |
| `meta_data` | json |


### `GET /admin/api/bustype/{id}`

_No validation — send an empty body, or see the controller._


### `POST /admin/api/bustype/{id}`

_No validation — send an empty body, or see the controller._


### `DELETE /admin/api/bustype/{id}`

_No validation — send an empty body, or see the controller._


### `GET /admin/api/bustypes`

_No validation — send an empty body, or see the controller._


### `GET /admin/api/cities`

_No validation — send an empty body, or see the controller._


### `POST /admin/api/city`

| Field | Rules |
|---|---|
| `name` | required · string · unique:cities |
| `tag_line` | required · string |
| `description` | required · string |
| `image_url` | required · mimes:jpeg,jpg,png · max:2048 |
| `bg_image_url` | required · mimes:jpeg,jpg,png · max:2048 |
| `url` | string |


### `GET /admin/api/city/{id}`

_No validation — send an empty body, or see the controller._


### `POST /admin/api/city/{id}`

| Field | Rules |
|---|---|
| `name` | string · unique:cities,name, |
| `tag_line` | string |
| `description` | string |
| `image_url` | mimes:jpeg,jpg,png · max:2048 |
| `bg_image_url` | mimes:jpeg,jpg,png · max:2048 |
| `url` | string |


### `DELETE /admin/api/city/{id}`

_No validation — send an empty body, or see the controller._


### `GET /admin/api/city/{id}/detail`

_No validation — send an empty body, or see the controller._


### `POST /admin/api/food`

| Field | Rules |
|---|---|
| `name` | required · string · between:2,100 |
| `food_type` | required · string |
| `description` | required · string |
| `nuetritional_info` | json |
| `image_url` | required · mimes:jpeg,jpg,png · max:2048 |
| `visitor_count` | numeric |
| `meta_data` | json |


### `GET /admin/api/food/{id}`

_No validation — send an empty body, or see the controller._


### `POST /admin/api/food/{id}`

| Field | Rules |
|---|---|
| `name` | string · between:2,100 |
| `food_type` | string |
| `description` | string |
| `nuetritional_info` | json |
| `image_url` | mimes:jpeg,jpg,png · max:2048 |
| `visitor_count` | numeric |
| `meta_data` | json |


### `DELETE /admin/api/food/{id}`

_No validation — send an empty body, or see the controller._


### `GET /admin/api/foods`

_No validation — send an empty body, or see the controller._


### `POST /admin/api/place`

| Field | Rules |
|---|---|
| `name` | required · string · between:2,100 |
| `city_id` | required · numeric · exists:cities,id |
| `description` | required · string |
| `rules` | json |
| `image_url` | required · mimes:jpeg,jpg,png · max:2048 |
| `bg_image_url` | required · mimes:jpeg,jpg,png · max:2048 |
| `price` | json |
| `rating` | numeric |
| `visitors_count` | numeric |
| `social_media` | json |
| `contact_details` | json |
| `place_category_id` | required · string · exists:place_categories,id |


### `GET /admin/api/place/{id}`

_No validation — send an empty body, or see the controller._


### `POST /admin/api/place/{id}`

| Field | Rules |
|---|---|
| `name` | string · between:2,100 |
| `city_id` | numeric |
| `description` | string |
| `rules` | json |
| `image_url` | mimes:jpeg,jpg,png · max:2048 |
| `bg_image_url` | mimes:jpeg,jpg,png · max:2048 |
| `price` | json |
| `rating` | numeric |
| `visitors_count` | numeric |
| `social_media` | json |
| `contact_details` | json |


### `DELETE /admin/api/place/{id}`

_No validation — send an empty body, or see the controller._


### `GET /admin/api/placecategories`

_No validation — send an empty body, or see the controller._


### `POST /admin/api/placecategory`

| Field | Rules |
|---|---|
| `name` | required · string · between:2,100 |
| `icon` | required · mimes:jpeg,jpg,png · max:2048 |
| `meta_data` | json |


### `GET /admin/api/placecategory/places/{place_categories_id}`

_No validation — send an empty body, or see the controller._


### `GET /admin/api/placecategory/{id}`

_No validation — send an empty body, or see the controller._


### `POST /admin/api/placecategory/{id}`

| Field | Rules |
|---|---|
| `name` | string · between:2,100 |
| `icon` | mimes:jpeg,jpg,png · max:2048 |
| `meta_data` | json |


### `DELETE /admin/api/placecategory/{id}`

_No validation — send an empty body, or see the controller._


### `GET /admin/api/places`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/addAppVersion`

| Field | Rules |
|---|---|
| `platform` | required · string |
| `version_number` | required · string · unique:app_versions |
| `release_date` | required · date_format:Y-m-d H:i:s |
| `release_notes` | nullable · string |
| `update_url` | nullable · string |
| `meta_data` | nullable · json |


### `POST /admin/v2/addBonusType`

| Field | Rules |
|---|---|
| `name` | required · string · unique:bonus_types,name |
| `code` | required · string · unique:bonus_types,code |
| `amount` | required · numeric · min:0 |
| `description` | nullable · string |


### `POST /admin/v2/bannerDaysDD`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/bannerImageOrientationDD`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/bannerLevelsDD`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/deleteAppVersion`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/deleteBonusType`

| Field | Rules |
|---|---|
| `id` | required · exists:bonus_types,id |


### `POST /admin/v2/getAppVersion`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/getBonusType`

| Field | Rules |
|---|---|
| `id` | required · exists:bonus_types,id |


### `POST /admin/v2/listAppVersions`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/listBonusTypes`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/roleDD`

_No validation — send an empty body, or see the controller._


### `POST /admin/v2/updateAppVersion`

| Field | Rules |
|---|---|
| `platform` | sometimes · required · string |
| `version_number` | sometimes · required · string · unique:app_versions,version_number, |
| `release_date` | sometimes · required · date_format:Y-m-d H:i:s |
| `release_notes` | nullable · string |
| `update_url` | nullable · string |
| `meta_data` | nullable · json |


### `POST /admin/v2/updateBonusType`

| Field | Rules |
|---|---|
| `id` | required · exists:bonus_types,id |
| `name` | nullable · string · unique:bonus_types,name |
| `code` | nullable · string · unique:bonus_types,code |
| `amount` | nullable · numeric · min:0 |
| `description` | nullable · string |


---

## Legacy surface — `/admin/api/*`

The endpoints under `/admin/api/` (not `/admin/v2/`) are the original 2022 admin API. They
still work and are documented above, but new work should target `/admin/v2`, which is where
everything since 2023 lives.

One of them is **broken**: `POST /admin/api/users` routes to `AuthController@index`, a method
that does not exist, so it can only return a 500. Use `POST /admin/v2/allUsers` or
`/admin/v2/listUsers` instead.

---

## Build order for the product feature

The vendor catalog is the newest area and the one with no admin UI yet. In dependency order:

1. **Vendor role requests** — `userRoleRequests`, `approveRoleRequest`, `rejectRoleRequest`.
   Nothing else in the vendor flow can happen until someone can grant the role.
2. **Site review** — `pendingSites`, `approveSite`, `rejectSite`. A vendor's business must be
   approved before its listings can go live.
3. **Product moderation** — `pendingProducts`, `approveProduct`, `rejectProduct`. This is the
   daily-driver screen; `pendingProducts` is ordered oldest-first as a work queue.
4. **Product taxonomy** — `listProductCategories`, `addProductCategory`,
   `setAllowedProductCategories`. Rarely used but high leverage: see below.
5. **Plans** — `listPlans`, `assignPlan`, `vendorUsageReport`. Not needed until charging starts.

### The taxonomy screen is worth building properly

`addProductCategory` carries an `attribute_schema` — a JSON object describing the fields a
vendor fills in for that kind of product. The mobile app renders its Add-Product form from
it, so **adding a category here ships a new vendor vertical with no code and no app
release**.

```json
{
  "occupancy": { "type": "int",  "label": "Max guests", "mr_label": "पाहुणे",
                 "required": true, "min": 1, "max": 20 },
  "meal_plan": { "type": "enum", "label": "Meal plan", "options": ["EP","CP","MAP","AP"] }
}
```

Types: `string`, `text`, `int`, `decimal`, `bool`, `enum`, `multi`, `date`, `time`.
`enum`/`multi` must declare `options`. Keys must be `snake_case`. Keys like `price` or
`stock` are **refused** — anything varying by date belongs to pricing, not attributes.

A schema builder UI (add field → pick type → set label/options) is more valuable than a raw
JSON textarea, because this is the screen that lets the business open a new category without
engineering.

### `setAllowedProductCategories` is the guardrail

It defines which product categories a given **site** category may list. This is what stops a
site categorised "Hospital" from listing mangoes. It **replaces** the whole set for that
category — send the complete list, not a delta.

```json
{ "category_id": 12, "allowed": [ { "product_category_id": 3, "max_products": 50 },
                                  { "product_category_id": 7 } ] }
```

`max_products` doubles as a per-site quota; omit it for unlimited.

---

## Notes on specific endpoints

- **`approveProduct`** refuses if the product's *site* is not approved and published. The
  error explains it — surface the message rather than a generic failure.
- **`featureProduct`** only accepts approved products.
- **`rejectProduct` / `rejectSite`** require `rejection_reason`; it is shown to the vendor,
  so treat it as user-facing copy.
- **`approveRoleRequest`** for a `vendor` request also enrols the user on the free plan.
- **`assignPlan`** closes the vendor's previous subscription rather than leaving two active.
- **`vendorUsageReport`** answers "why can this vendor not add more?" — plan, dates, and
  usage against every quota. Useful on a support screen.
- **Analytics endpoints** read `product_daily_stats`, populated by a nightly job. **Today's
  activity is not included**, and everything reads zero until `php artisan
  products:rollup-stats` has run. Label these "updated daily".

---

## Testing

A Postman collection covering the vendor and product endpoints is at
`docs/tourkokan-vendor-products.postman_collection.json` — 65 requests, login saves the
token automatically. The full wire format for the app-facing side is in
`docs/vendor-products-api.md`, and the design rationale in
`docs/VENDOR_PRODUCTS_DESIGN.md`.
