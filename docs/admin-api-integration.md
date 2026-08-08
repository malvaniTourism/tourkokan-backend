# Admin Panel — Vendor Products API Reference

Every request and response below was **captured from the running application**
(`tests/Feature/AdminApiDocCaptureTest.php`), not written by hand — so the shapes are what
the server actually returns.

**Scope:** only what changed. 18 new endpoints, 2 changed, 1 breaking access change,
23 removed. The other 135 admin endpoints are untouched and need no work.

**Base URL** `{VITE_ADMIN_API_BASE}` → `/admin/v2` · **Method** all `POST` ·
**Auth** `Authorization: Bearer <token>` · **Role** `admin` or `superadmin`

---

## 1. ⚠️ Breaking — `/admin/v2/*` now requires the admin role

The group was declared without the `admin` middleware, so **every admin endpoint was
reachable by any authenticated user** — including `approveSite`, `approveRoleRequest` and
`deleteEvent`. Fixed 2026-08-05.

```diff
- ['auth:api', 'premiddleware', 'throttle:admin']
+ ['auth:api', 'premiddleware', 'admin', 'throttle:admin']
```

A token that worked before now gets **403 on every endpoint** if its user has no
`admin`/`superadmin` row in `user_roles`:

```json
{ "message": "Access Forbidden" }
```

Note this one is a **real 403 with no `success` key** — it comes from middleware, not from
`BaseController`. Handle it globally.

Find affected accounts before deploying:

```sql
SELECT u.id, u.email, GROUP_CONCAT(r.code) AS roles
FROM users u
LEFT JOIN user_roles ur ON ur.user_id = u.id
LEFT JOIN roles r ON r.id = ur.role_id
GROUP BY u.id
HAVING roles IS NULL OR roles NOT LIKE '%admin%';
```

---

## 2. Response envelope

Success — note `version` and `language` are echoed at the top level:

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Product retrieved successfully...!",
  "data": { }
}
```

Failure — **HTTP 200 in most cases**, so read `success`, never the status code:

```json
{ "success": false, "message": "Only a product awaiting review can be approved." }
```

`message` is a **string** for business errors and an **object of field → errors** for
validation failures. Paginated lists put rows at **`data.data`**; `per_page` is silently
capped at **30**.

---

## 3. Product moderation

The daily-driver screen. `pendingProducts` is the work queue, ordered **oldest first**.


### `POST /admin/v2/pendingProducts`

The review queue. Each row carries its site, owner, category, default variant and cover image.

**Request**

_No required fields — send `{}`._

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Pending products retrieved successfully...!",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "site_id": 1,
        "product_category_id": 1,
        "name": "Deluxe Sea View Room",
        "mr_name": null,
        "slug": "deluxe-sea-view-room",
        "description": "Sea-facing room with a private balcony.",
        "mr_description": null,
        "attributes": {
          "ac": true,
          "meal_plan": "CP",
          "occupancy": 3
        },
        "base_price": "2400.00",
        "sale_price": null,
        "currency": "INR",
        "hsn_code": "996311",
        "tax_rate": "12.00",
        "price_includes_tax": true,
        "unit": "per_night",
        "is_bookable": false,
        "fulfilment_type": "enquiry",
        "status": "pending",
        "rejection_reason": null,
        "is_featured": false,
        "available_from": null,
        "available_to": null,
        "views_count": 0,
        "leads_count": 0,
        "sort_order": 0,
        "created_at": "2026-08-08 12:01:27",
        "updated_at": "2026-08-08 12:01:27",
        "deleted_at": null,
        "site": {
          "id": 1,
          "name": "Sagar Resort Tarkarli",
          "user_id": 2,
          "user": {
            "id": 2,
            "name": "Miss Adah Waelchi"
          }
        },
        "product_category": {
          "id": 1,
          "name": "Room Night",
          "code": "room_night"
        },
        "default_variant": {
          "id": 1,
          "product_id": 1,
          "name": "Standard",
          "sku": "DLX-STD",
          "price": "2400.00",
          "sale_price": null,
          "stock": null,
          "min_order_qty": null,
          "max_order_qty": null,
          "attributes": null,
          "is_default": true,
          "status": true,
          "sort_order": 0,
          "created_at": "2026-08-08 12:01:27",
          "updated_at": "2026-08-08 12:01:27"
        },
        "cover": null
      }
    ],
    "last_page": 1,
    "per_page": 15,
    "total": 1,
    "_note": "paginator keys first_page_url/last_page_url/links/from/to omitted here"
  }
}
```


### `POST /admin/v2/listAllProducts`

Filters: `status`, `site_id`, `product_category_id`, `search`, `page`, `per_page`.
`status` values: `draft` · `pending` · `approved` · `rejected` · `paused`

**Request**

_No required fields — send `{}`._

```json
{
  "status": "pending"
}
```

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Products retrieved successfully...!",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "site_id": 1,
        "product_category_id": 1,
        "name": "Deluxe Sea View Room",
        "mr_name": null,
        "slug": "deluxe-sea-view-room",
        "description": "Sea-facing room with a private balcony.",
        "mr_description": null,
        "attributes": {
          "ac": true,
          "meal_plan": "CP",
          "occupancy": 3
        },
        "base_price": "2400.00",
        "sale_price": null,
        "currency": "INR",
        "hsn_code": "996311",
        "tax_rate": "12.00",
        "price_includes_tax": true,
        "unit": "per_night",
        "is_bookable": false,
        "fulfilment_type": "enquiry",
        "status": "pending",
        "rejection_reason": null,
        "is_featured": false,
        "available_from": null,
        "available_to": null,
        "views_count": 0,
        "leads_count": 0,
        "sort_order": 0,
        "created_at": "2026-08-08 12:01:27",
        "updated_at": "2026-08-08 12:01:27",
        "deleted_at": null,
        "site": {
          "id": 1,
          "name": "Sagar Resort Tarkarli",
          "user_id": 2
        },
        "product_category": {
          "id": 1,
          "name": "Room Night",
          "code": "room_night"
        },
        "default_variant": {
          "id": 1,
          "product_id": 1,
          "name": "Standard",
          "sku": "DLX-STD",
          "price": "2400.00",
          "sale_price": null,
          "stock": null,
          "min_order_qty": null,
          "max_order_qty": null,
          "attributes": null,
          "is_default": true,
          "status": true,
          "sort_order": 0,
          "created_at": "2026-08-08 12:01:27",
          "updated_at": "2026-08-08 12:01:27"
        }
      }
    ],
    "last_page": 1,
    "per_page": 15,
    "total": 1,
    "_note": "paginator keys first_page_url/last_page_url/links/from/to omitted here"
  }
}
```


### `POST /admin/v2/getProductAdmin`

Full record including `variants`, `gallery`, and the category's `attribute_schema` — pair `data.attributes` with that schema to render label/value rows for review.

**Request**

| Field | Rules | Notes |
|---|---|---|
| `id` | required · numeric · exists:products,id |  |

```json
{
  "id": 1
}
```

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Product retrieved successfully...!",
  "data": {
    "id": 1,
    "site_id": 1,
    "product_category_id": 1,
    "name": "Deluxe Sea View Room",
    "mr_name": null,
    "slug": "deluxe-sea-view-room",
    "description": "Sea-facing room with a private balcony.",
    "mr_description": null,
    "attributes": {
      "ac": true,
      "meal_plan": "CP",
      "occupancy": 3
    },
    "base_price": "2400.00",
    "sale_price": null,
    "currency": "INR",
    "hsn_code": "996311",
    "tax_rate": "12.00",
    "price_includes_tax": true,
    "unit": "per_night",
    "is_bookable": false,
    "fulfilment_type": "enquiry",
    "status": "pending",
    "rejection_reason": null,
    "is_featured": false,
    "available_from": null,
    "available_to": null,
    "views_count": 0,
    "leads_count": 0,
    "sort_order": 0,
    "created_at": "2026-08-08 12:01:27",
    "updated_at": "2026-08-08 12:01:27",
    "deleted_at": null,
    "site": {
      "id": 1,
      "name": "Sagar Resort Tarkarli",
      "user_id": 2,
      "user": {
        "id": 2,
        "name": "Miss Adah Waelchi"
      }
    },
    "product_category": {
      "id": 1,
      "name": "Room Night",
      "code": "room_night",
      "booking_type": "date_range",
      "attribute_schema": {
        "ac": {
          "type": "bool",
          "label": "Air conditioned"
        },
        "meal_plan": {
          "type": "enum",
          "label": "Meal plan",
          "options": [
            "EP",
            "CP",
            "MAP",
            "AP"
          ]
        },
        "occupancy": {
          "max": 20,
          "min": 1,
          "type": "int",
          "label": "Max guests",
          "mr_label": "पाहुणे",
          "required": true
        }
      }
    },
    "variants": [
      {
        "id": 1,
        "product_id": 1,
        "name": "Standard",
        "sku": "DLX-STD",
        "price": "2400.00",
        "sale_price": null,
        "stock": null,
        "min_order_qty": null,
        "max_order_qty": null,
        "attributes": null,
        "is_default": true,
        "status": true,
        "sort_order": 0,
        "created_at": "2026-08-08 12:01:27",
        "updated_at": "2026-08-08 12:01:27"
      }
    ],
    "gallery": []
  }
}
```


### `POST /admin/v2/approveProduct`

**Refuses if the product's site is not approved and published.** Vendors may build a catalogue while their business is still under review, so the queue legitimately contains such products. Surface the message and link to site review.

**Request**

| Field | Rules | Notes |
|---|---|---|
| `id` | required · numeric · exists:products,id |  |

```json
{
  "id": 1
}
```

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Product approved and is now live.",
  "data": {
    "id": 1,
    "site_id": 1,
    "product_category_id": 1,
    "name": "Deluxe Sea View Room",
    "mr_name": null,
    "slug": "deluxe-sea-view-room",
    "description": "Sea-facing room with a private balcony.",
    "mr_description": null,
    "attributes": {
      "ac": true,
      "meal_plan": "CP",
      "occupancy": 3
    },
    "base_price": "2400.00",
    "sale_price": null,
    "currency": "INR",
    "hsn_code": "996311",
    "tax_rate": "12.00",
    "price_includes_tax": true,
    "unit": "per_night",
    "is_bookable": false,
    "fulfilment_type": "enquiry",
    "status": "approved",
    "rejection_reason": null,
    "is_featured": false,
    "available_from": null,
    "available_to": null,
    "views_count": 0,
    "leads_count": 0,
    "sort_order": 0,
    "created_at": "2026-08-08 12:01:27",
    "updated_at": "2026-08-08 12:01:27",
    "deleted_at": null
  }
}
```

**Failure — site not live** `422`

```json
{
  "success": false,
  "message": "The product's site is not approved and live."
}
```


### `POST /admin/v2/rejectProduct`

`rejection_reason` is **shown to the vendor in the app** — treat it as user-facing copy.

**Request**

| Field | Rules | Notes |
|---|---|---|
| `id` | required · numeric · exists:products,id |  |
| `rejection_reason` | required · string · max:1000 | Shown to the vendor in the app — user-facing copy. |

```json
{
  "id": 2,
  "rejection_reason": "Images do not match the description."
}
```

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Product rejected.",
  "data": {
    "id": 2,
    "site_id": 1,
    "product_category_id": 1,
    "name": "Garden Cottage",
    "mr_name": null,
    "slug": "garden-cottage",
    "description": null,
    "mr_description": null,
    "attributes": null,
    "base_price": "1800.00",
    "sale_price": null,
    "currency": "INR",
    "hsn_code": null,
    "tax_rate": null,
    "price_includes_tax": true,
    "unit": null,
    "is_bookable": false,
    "fulfilment_type": "enquiry",
    "status": "rejected",
    "rejection_reason": "Images do not match the description.",
    "is_featured": false,
    "available_from": null,
    "available_to": null,
    "views_count": 0,
    "leads_count": 0,
    "sort_order": 0,
    "created_at": "2026-08-08 12:01:27",
    "updated_at": "2026-08-08 12:01:27",
    "deleted_at": null
  }
}
```


### `POST /admin/v2/featureProduct`

Only **approved** products can be featured. Un-featuring works from any status.

**Request**

| Field | Rules | Notes |
|---|---|---|
| `id` | required · numeric · exists:products,id |  |
| `is_featured` | required · boolean |  |

```json
{
  "id": 1,
  "is_featured": true
}
```

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Product featured.",
  "data": {
    "id": 1,
    "is_featured": true
  }
}
```


---

## 4. Product taxonomy


### `POST /admin/v2/listProductCategories`

Filters: `search`, `booking_type`, `status`, `page`. `allowed_categories_count` is how many site categories may list this product type.

**Request**

_No required fields — send `{}`._

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Product categories retrieved successfully...!",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "parent_id": null,
        "name": "Room Night",
        "mr_name": "रूम प्रति रात्र",
        "code": "room_night",
        "slug": "room-night",
        "description": null,
        "icon": null,
        "attribute_schema": {
          "ac": {
            "type": "bool",
            "label": "Air conditioned"
          },
          "meal_plan": {
            "type": "enum",
            "label": "Meal plan",
            "options": [
              "EP",
              "CP",
              "MAP",
              "AP"
            ]
          },
          "occupancy": {
            "max": 20,
            "min": 1,
            "type": "int",
            "label": "Max guests",
            "mr_label": "पाहुणे",
            "required": true
          }
        },
        "booking_type": "date_range",
        "status": true,
        "sort_order": 0,
        "meta_data": null,
        "created_at": "2026-08-08 12:01:27",
        "updated_at": "2026-08-08 12:01:27",
        "deleted_at": null,
        "allowed_categories_count": 1,
        "parent": null
      }
    ],
    "last_page": 1,
    "per_page": 15,
    "total": 1,
    "_note": "paginator keys first_page_url/last_page_url/links/from/to omitted here"
  }
}
```


### `POST /admin/v2/getProductCategory`

**Request**

| Field | Rules | Notes |
|---|---|---|
| `id` | required · numeric · exists:product_categories,id |  |

```json
{
  "id": 1
}
```

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Product category retrieved successfully...!",
  "data": {
    "id": 1,
    "parent_id": null,
    "name": "Room Night",
    "mr_name": "रूम प्रति रात्र",
    "code": "room_night",
    "slug": "room-night",
    "description": null,
    "icon": null,
    "attribute_schema": {
      "ac": {
        "type": "bool",
        "label": "Air conditioned"
      },
      "meal_plan": {
        "type": "enum",
        "label": "Meal plan",
        "options": [
          "EP",
          "CP",
          "MAP",
          "AP"
        ]
      },
      "occupancy": {
        "max": 20,
        "min": 1,
        "type": "int",
        "label": "Max guests",
        "mr_label": "पाहुणे",
        "required": true
      }
    },
    "booking_type": "date_range",
    "status": true,
    "sort_order": 0,
    "meta_data": null,
    "created_at": "2026-08-08 12:01:27",
    "updated_at": "2026-08-08 12:01:27",
    "deleted_at": null,
    "parent": null,
    "children": [],
    "site_categories": [
      {
        "id": 1,
        "name": "Hotel Rooms",
        "code": "hotel_rooms",
        "pivot": {
          "product_category_id": 1,
          "category_id": 1,
          "is_required": 0,
          "max_products": 50,
          "created_at": "2026-08-08T06:31:27.000000Z",
          "updated_at": "2026-08-08T06:31:27.000000Z"
        }
      }
    ]
  }
}
```


### `POST /admin/v2/addProductCategory`

**This is how a new vendor vertical ships** — no migration, no code, no app release.

**Request**

| Field | Rules | Notes |
|---|---|---|
| `name` | required · string · between:2,100 |  |
| `mr_name` | nullable · string · between:2,100 |  |
| `code` | required · string · max:60 · unique:product_categories,code · regex:/^[a-z][a-z0-9_]*$/ |  |
| `parent_id` | nullable · numeric · exists:product_categories,id |  |
| `description` | nullable · string · max:1000 |  |
| `icon` | nullable · mimes:jpeg,jpg,png,webp · max:2048 | Makes the request multipart/form-data. |
| `attribute_schema` | nullable · array | Drives the vendor Add-Product form in the app. See §5.1. |
| `booking_type` | nullable · in: none, date_range, slot, quantity | Dormant. Set correctly now so the booking calendar is additive later. |
| `status` | nullable · boolean |  |
| `sort_order` | nullable · integer · min:0 |  |

```json
{
  "name": "Alphonso Mango",
  "mr_name": "हापूस आंबा",
  "code": "alphonso_mango",
  "booking_type": "quantity",
  "status": true,
  "attribute_schema": {
    "grade": {
      "type": "enum",
      "label": "Grade",
      "mr_label": "दर्जा",
      "required": true,
      "options": [
        "A",
        "B",
        "C"
      ]
    },
    "dozen_count": {
      "type": "int",
      "label": "Mangoes per box",
      "required": true,
      "min": 1,
      "max": 100
    },
    "organic": {
      "type": "bool",
      "label": "Organically grown"
    }
  }
}
```

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Product category created successfully...!",
  "data": {
    "name": "Alphonso Mango",
    "mr_name": "हापूस आंबा",
    "code": "alphonso_mango",
    "attribute_schema": {
      "grade": {
        "type": "enum",
        "label": "Grade",
        "mr_label": "दर्जा",
        "required": true,
        "options": [
          "A",
          "B",
          "C"
        ]
      },
      "dozen_count": {
        "type": "int",
        "label": "Mangoes per box",
        "required": true,
        "min": 1,
        "max": 100
      },
      "organic": {
        "type": "bool",
        "label": "Organically grown"
      }
    },
    "booking_type": "quantity",
    "status": true,
    "slug": "alphonso-mango",
    "updated_at": "2026-08-08 12:01:27",
    "created_at": "2026-08-08 12:01:27",
    "id": 2
  }
}
```

**Failure — reserved key refused** `422`

```json
{
  "success": false,
  "message": {
    "attribute_schema": [
      "Attribute key 'price' is reserved — anything that varies by date belongs in pricing/availability, not in attributes (see design doc §3 R5)."
    ]
  }
}
```

**Failure — validation failure** `422`

```json
{
  "success": false,
  "message": {
    "code": [
      "The code field is required."
    ]
  }
}
```


### `POST /admin/v2/updateProductCategory`

**Request**

| Field | Rules | Notes |
|---|---|---|
| `id` | required · numeric · exists:product_categories,id |  |
| `name` | sometimes · string · between:2,100 |  |
| `mr_name` | nullable · string · between:2,100 |  |
| `code` | sometimes · string · max:60 · regex:/^[a-z][a-z0-9_]*$/ · unique:product_categories,code, |  |
| `parent_id` | nullable · numeric · exists:product_categories,id · different:id |  |
| `description` | nullable · string · max:1000 |  |
| `icon` | nullable · mimes:jpeg,jpg,png,webp · max:2048 | Makes the request multipart/form-data. |
| `attribute_schema` | nullable · array | Drives the vendor Add-Product form in the app. See §5.1. |
| `booking_type` | nullable · in: none, date_range, slot, quantity | Dormant. Set correctly now so the booking calendar is additive later. |
| `status` | nullable · boolean |  |
| `sort_order` | nullable · integer · min:0 |  |

```json
{
  "id": 1,
  "sort_order": 1
}
```

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Product category updated successfully...!",
  "data": {
    "id": 1,
    "parent_id": null,
    "name": "Room Night",
    "mr_name": "रूम प्रति रात्र",
    "code": "room_night",
    "slug": "room-night",
    "description": null,
    "icon": null,
    "attribute_schema": {
      "ac": {
        "type": "bool",
        "label": "Air conditioned"
      },
      "meal_plan": {
        "type": "enum",
        "label": "Meal plan",
        "options": [
          "EP",
          "CP",
          "MAP",
          "AP"
        ]
      },
      "occupancy": {
        "max": 20,
        "min": 1,
        "type": "int",
        "label": "Max guests",
        "mr_label": "पाहुणे",
        "required": true
      }
    },
    "booking_type": "date_range",
    "status": true,
    "sort_order": 1,
    "meta_data": null,
    "created_at": "2026-08-08 12:01:27",
    "updated_at": "2026-08-08 12:01:27",
    "deleted_at": null
  }
}
```


### `POST /admin/v2/setAllowedProductCategories`

Defines which product categories a **site** category may list — what stops a site categorised "Hospital" from listing mangoes. **Replaces the entire set**; send the full list, never a delta. `allowed: []` revokes everything.

**Request**

| Field | Rules | Notes |
|---|---|---|
| `category_id` | required · numeric · exists:categories,id |  |
| `allowed` | present · array | Replaces the whole set for that category. Send the complete list. |
| `allowed.*.product_category_id` | required · numeric · exists:product_categories,id |  |
| `allowed.*.is_required` | nullable · boolean |  |
| `allowed.*.max_products` | nullable · integer · min:1 |  |

```json
{
  "category_id": 1,
  "allowed": [
    {
      "product_category_id": 1,
      "max_products": 50
    }
  ]
}
```

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Allowed product categories updated successfully...!",
  "data": [
    {
      "id": 2,
      "category_id": 1,
      "product_category_id": 1,
      "is_required": false,
      "max_products": 50,
      "created_at": "2026-08-08 12:01:27",
      "updated_at": "2026-08-08 12:01:27",
      "product_category": {
        "id": 1,
        "name": "Room Night",
        "code": "room_night",
        "booking_type": "date_range"
      }
    }
  ]
}
```


### `POST /admin/v2/deleteProductCategory`

Refused if the category has children.

**Request**

| Field | Rules | Notes |
|---|---|---|
| `id` | required · numeric · exists:product_categories,id |  |


### 5.1 The attribute schema

The mobile app renders its whole Add-Product form from this object, so a good schema here is
what makes a new category usable without an app release.

```json
{
  "occupancy": { "type": "int",  "label": "Max guests", "mr_label": "पाहुणे",
                 "required": true, "min": 1, "max": 20 },
  "ac":        { "type": "bool", "label": "Air conditioned" },
  "meal_plan": { "type": "enum", "label": "Meal plan", "options": ["EP","CP","MAP","AP"] }
}
```

| Key | Meaning |
|---|---|
| `type` | `string` `text` `int` `decimal` `bool` `enum` `multi` `date` `time` |
| `label` | **required** — the app renders it as the field name |
| `mr_label` | optional Marathi label |
| `required` | optional boolean |
| `min` / `max` | numeric bounds, or string length for `string`/`text` |
| `options` | **required** for `enum` and `multi` |

Attribute keys must be `snake_case`. **Reserved keys are refused** — `price`, `sale_price`,
`base_price`, `stock`, `currency`, `availability`, `slots`, `booked`, `quantity_available`,
`available_dates` — because anything varying by date belongs to pricing, not attributes.

A field-builder UI (add field → pick type → set label/options) is worth more here than a raw
JSON textarea: this screen is the one that lets the business open "Boat Repair" or "Homestay
Meals" without engineering.

---

## 5b. Vendors

There is no `vendors` table — a vendor is a user holding the `vendor` role, and their
businesses are the sites they own. That keeps ownership unambiguous, but it means
"list all vendors" is a query nothing else performs, hence these two endpoints.

| Endpoint | Payload |
|---|---|
| `listVendors` | `search?`, `plan_code?`, `has_pending_products?`, `has_no_sites?`, `page?` |
| `getVendor` | `id` (the **user** id) |

`listVendors` returns one row per vendor, named after their **primary** business, with site
counts by status, product counts by status, and their current plan:

```json
{
  "id": 9, "name": "…", "email": "…", "joined_at": "…",
  "business_name": "Sagar Resort Tarkarli", "logo": "https://…",
  "sites":    { "total": 2, "approved": 2, "pending": 0 },
  "products": { "total": 3, "approved": 1, "pending": 1, "draft": 1, "rejected": 0, "paused": 0 },
  "plan":     { "code": "free", "name": "Free", "ends_at": "2027-08-08T…" }
}
```

`has_pending_products: true` is the useful filter for a moderation dashboard — it surfaces
exactly the vendors with something waiting on you.

`getVendor` returns everything about one: their sites with categories and per-site product
counts, their 50 most recent products, plan and quota usage, and engagement totals with the
10 most recent leads. It answers "who is this vendor and what have they got?" in one call.
Passing a user who is not a vendor is refused with 422 rather than returning an empty shell.

---

## 6. Plans & subscriptions

Not urgent — listing is free for the launch year. `vendorUsageReport` is the one useful today.


### `POST /admin/v2/listPlans`

Includes inactive tiers and `subscriptions_count` (active only).

**Request**

_No required fields — send `{}`._

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Plans retrieved successfully...!",
  "data": [
    {
      "id": 1,
      "code": "free",
      "name": "Free",
      "mr_name": "मोफत",
      "description": "Free listing for the launch year. All core features included.",
      "price": "0.00",
      "currency": "INR",
      "billing_period": "free",
      "limits": {
        "max_sites": 5,
        "max_products": 100,
        "featured_slots": 0,
        "max_images_per_product": 10
      },
      "is_active": true,
      "sort_order": 0,
      "created_at": "2026-08-08 12:01:27",
      "updated_at": "2026-08-08 12:01:27",
      "subscriptions_count": 1
    },
    {
      "id": 2,
      "code": "starter",
      "name": "Starter",
      "mr_name": "स्टार्टर",
      "description": "For a growing business with several outlets.",
      "price": "499.00",
      "currency": "INR",
      "billing_period": "monthly",
      "limits": {
        "max_sites": 15,
        "max_products": 500,
        "featured_slots": 2,
        "max_images_per_product": 15
      },
      "is_active": false,
      "sort_order": 1,
      "created_at": "2026-08-08 12:01:27",
      "updated_at": "2026-08-08 12:01:27",
      "subscriptions_count": 0
    },
    {
      "id": 3,
      "code": "growth",
      "name": "Growth",
      "mr_name": "ग्रोथ",
      "description": "Unlimited listings and priority placement.",
      "price": "1499.00",
      "currency": "INR",
      "billing_period": "monthly",
      "limits": {
        "max_sites": null,
        "max_products": null,
        "featured_slots": 10,
        "max_images_per_product": 25
      },
      "is_active": false,
      "sort_order": 2,
      "created_at": "2026-08-08 12:01:27",
      "updated_at": "2026-08-08 12:01:27",
      "subscriptions_count": 0
    }
  ]
}
```


### `POST /admin/v2/vendorUsageReport`

Answers "why can this vendor not add more?". Worth putting on the user detail screen.

`limit: null` means **unlimited** — render as "Unlimited", never `0`. Per-product quotas report only a `limit`, since there is no single account-wide figure.

**Request**

| Field | Rules | Notes |
|---|---|---|
| `user_id` | required · numeric · exists:users,id |  |

```json
{
  "user_id": 2
}
```

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Vendor usage retrieved successfully...!",
  "data": {
    "user": {
      "id": 2,
      "name": "Miss Adah Waelchi",
      "email": "bjakubowski@example.com"
    },
    "plan": {
      "code": "free",
      "name": "Free",
      "limits": {
        "max_sites": 5,
        "max_products": 100,
        "featured_slots": 0,
        "max_images_per_product": 10
      }
    },
    "subscription": {
      "starts_at": "2026-08-08T06:31:27.000000Z",
      "ends_at": "2027-08-08T06:31:27.000000Z",
      "status": "active"
    },
    "usage": {
      "max_sites": {
        "limit": 5,
        "used": 2,
        "remaining": 3,
        "exceeded": false
      },
      "max_products": {
        "limit": 100,
        "used": 3,
        "remaining": 97,
        "exceeded": false
      },
      "max_images_per_product": {
        "limit": 10
      },
      "featured_slots": {
        "limit": 0,
        "used": 1,
        "remaining": 0,
        "exceeded": true
      }
    }
  }
}
```


### `POST /admin/v2/listSubscriptions`

Filters: `plan_id`, `status`, `expiring_soon` (boolean — ends within 30 days).

**Request**

_No required fields — send `{}`._

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Subscriptions retrieved successfully...!",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "user_id": 2,
        "plan_id": 1,
        "starts_at": "2026-08-08 12:01:27",
        "ends_at": "2027-08-08 12:01:27",
        "status": "active",
        "price_paid": "0.00",
        "auto_renew": false,
        "meta_data": null,
        "created_at": "2026-08-08 12:01:27",
        "updated_at": "2026-08-08 12:01:27",
        "user": {
          "id": 2,
          "name": "Miss Adah Waelchi",
          "email": "bjakubowski@example.com"
        },
        "plan": {
          "id": 1,
          "code": "free",
          "name": "Free"
        }
      }
    ],
    "last_page": 1,
    "per_page": 15,
    "total": 1,
    "_note": "paginator keys first_page_url/last_page_url/links/from/to omitted here"
  }
}
```


### `POST /admin/v2/assignPlan`

**Closes the vendor's previous subscription** rather than leaving two active. Omit `months` for a subscription that never expires.

**Request**

| Field | Rules | Notes |
|---|---|---|
| `user_id` | required · numeric · exists:users,id |  |
| `plan_id` | required · numeric · exists:plans,id |  |
| `months` | nullable · integer · min:1 · max:120 | Omit for a subscription that never expires. |

```json
{
  "user_id": 2,
  "plan_id": 3,
  "months": 12
}
```

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Plan assigned successfully...!",
  "data": {
    "user_id": 2,
    "plan_id": 3,
    "starts_at": "2026-08-08 12:01:27",
    "ends_at": "2027-08-08 12:01:27",
    "status": "active",
    "price_paid": "1499.00",
    "updated_at": "2026-08-08 12:01:27",
    "created_at": "2026-08-08 12:01:27",
    "id": 2,
    "plan": {
      "id": 3,
      "code": "growth",
      "name": "Growth"
    }
  }
}
```


### `POST /admin/v2/addPlan`

`limits` accepts only the four known keys; anything else is refused, because a typo would silently stop being enforced.

**Request**

_No required fields — send `{}`._

```json
{
  "code": "pro",
  "name": "Pro",
  "price": 999,
  "billing_period": "monthly",
  "is_active": false,
  "limits": {
    "max_sites": 20,
    "max_products": 1000,
    "max_images_per_product": 20,
    "featured_slots": 5
  }
}
```

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Plan created successfully...!",
  "data": {
    "code": "pro",
    "name": "Pro",
    "price": "999.00",
    "billing_period": "monthly",
    "limits": {
      "max_sites": 20,
      "max_products": 1000,
      "max_images_per_product": 20,
      "featured_slots": 5
    },
    "is_active": false,
    "updated_at": "2026-08-08 12:01:27",
    "created_at": "2026-08-08 12:01:27",
    "id": 4
  }
}
```


### `POST /admin/v2/updatePlan`

Setting `is_active: true` is how a paid tier goes live — no code change.

**Request**

_No required fields — send `{}`._

```json
{
  "id": 3,
  "sort_order": 3
}
```

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Plan updated successfully...!",
  "data": {
    "id": 3,
    "code": "growth",
    "name": "Growth",
    "mr_name": "ग्रोथ",
    "description": "Unlimited listings and priority placement.",
    "price": "1499.00",
    "currency": "INR",
    "billing_period": "monthly",
    "limits": {
      "max_sites": null,
      "max_products": null,
      "featured_slots": 10,
      "max_images_per_product": 25
    },
    "is_active": false,
    "sort_order": 3,
    "created_at": "2026-08-08 12:01:27",
    "updated_at": "2026-08-08 12:01:27"
  }
}
```


---

## 7. Changed behaviour on existing endpoints

### `sites` — vendor businesses were invisible

`global` means "an actual place, not one of the geographic containers" (District / City /
Village), which were the only parentless rows when it was written. It filtered on
`whereNotNull('parent_id')`.

**Vendor businesses are parentless too** — `submitSite` does not ask for a geographic
parent — so `global=1` hid every vendor listing from the admin site list. A vendor could
register a business, an admin could approve it, and it would still not appear.

Now: `parent_id IS NOT NULL OR user_id IS NOT NULL`. Platform places behave exactly as
before; vendor businesses join them.

Two related fixes on the same endpoint:

- **`category_id` is now accepted.** The endpoint only understood `category` (a *code*
  string), so a `category_id` was silently ignored and you got an unfiltered list. Both work.
- **`global=0` no longer filters.** The check was `has('global')`, true for any present key
  regardless of value, so "global: off" still applied the filter. Now `boolean('global')`.
  `parent_id` and `search` likewise ignore empty strings.

Covered by `tests/Feature/AdminSiteListFilterTest.php`.

---

Payloads and response shapes are unchanged on the two below; both now do more.


### `POST /admin/v2/approveSite`

A vendor's **first approved site is now automatically marked primary** (`is_primary: true` in the response below). Worth a badge in any site list.

**Request**

| Field | Rules | Notes |
|---|---|---|
| `id` | required · numeric · exists:sites,id |  |

```json
{
  "id": 3
}
```

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Site approved and is now live.",
  "data": {
    "id": 3,
    "name": "Kokan Kirana",
    "parent_id": null,
    "user_id": 3,
    "is_primary": true,
    "bus_stop_type": null,
    "tag_line": null,
    "description": "A village grocery shop listed by its owner.",
    "domain_name": null,
    "logo": null,
    "icon": null,
    "image": null,
    "status": 1,
    "submission_status": "approved",
    "rejection_reason": null,
    "is_hot_place": 0,
    "latitude": "16.0700000",
    "longitude": "73.4800000",
    "pin_code": null,
    "speciality": null,
    "rules": null,
    "social_media": null,
    "meta_data": null,
    "created_at": "2026-08-08 12:01:27",
    "updated_at": "2026-08-08 12:01:27"
  }
}
```


### `POST /admin/v2/approveRoleRequest`

Approving a **vendor** request now also enrols the user on the **free plan** for 12 months. Idempotent and invisible in the response — but it means a newly approved vendor already has a subscription when you open `vendorUsageReport`.

**Request**

| Field | Rules | Notes |
|---|---|---|
| `id` | required · exists:user_role_requests,id |  |
| `admin_note` | nullable · string · max:500 |  |

```json
{
  "id": 1
}
```

**Response** `200`

```json
{
  "version": "1.0.0",
  "language": "en",
  "success": true,
  "message": "Role request approved. User has been assigned the role.",
  "data": {
    "id": 1,
    "user_id": 4,
    "role_id": 2,
    "status": "approved",
    "reason": "I run a resort in Tarkarli and want to list my rooms.",
    "admin_note": null,
    "reviewed_by": 1,
    "reviewed_at": "2026-08-08T06:31:27.000000Z",
    "created_at": "2026-08-08T06:31:27.000000Z",
    "updated_at": "2026-08-08T06:31:27.000000Z",
    "user": {
      "id": 4,
      "name": "Urban Feeney",
      "email": "robel.bethel@example.org"
    },
    "role": {
      "id": 2,
      "name": "Vendor",
      "code": "vendor"
    },
    "reviewer": {
      "id": 1,
      "name": "Zelma Kihn"
    }
  }
}
```


---

## 8. Removed — 23 legacy `/admin/api/*` routes

Deleted with their tables. The controllers were non-functional (empty stubs, a
`Roles::find()` inside an accommodation update), so no behaviour is lost.

```
/admin/api/productcategories        /admin/api/productcategory[/{id}]
/admin/api/allowproductcategory[/{id}]
/admin/api/products                 /admin/api/product[/{id}]
/admin/api/tourpackages             /admin/api/tourpackage[/{id}]
/admin/api/accomodationcategories   /admin/api/accomodationcategory[/{id}]
```

Grepping `admin-panel/src` found **no references to any of them**, so this should be a no-op.
Replacements are in §3 and §4, under `/admin/v2` with a different shape.

Also broken and unrelated to this work: `POST /admin/api/users` routes to
`AuthController@index`, a method that does not exist — it can only 500. Use
`/admin/v2/allUsers` or `/admin/v2/listUsers`.

---

## 9. Suggested build order

1. **Product moderation** (§3) — the only screen needed daily; nothing goes live without it
2. **Product taxonomy** (§4) — needed once, then rarely; unlocks new verticals
3. **`vendorUsageReport`** on the existing user detail screen
4. **Plans** (§6) — when charging starts

Existing role-request and site-review screens already cover the earlier steps of the vendor
flow; only the behaviour notes in §7 apply to them.

---

## 10. Deployment prerequisites

Taxonomy screens will be empty until these run on the target server:

```bash
php artisan migrate --force
php artisan db:seed --class=PlanSeeder --force
php artisan db:seed --class=ProductCategorySeeder --force
php artisan db:seed --class=VendorCategorySeeder --force
```

`VendorCategorySeeder` also adds Tour & Travel, Local Services and Shopping **site**
categories, so existing category screens will show new entries.

---

Postman collection: `docs/tourkokan-vendor-products.postman_collection.json`
App-facing contract: `docs/app-api-integration.md` · Design rationale:
`docs/VENDOR_PRODUCTS_DESIGN.md`
