# Tourkokan Backend — Code Audit Report

> **Audited against:** Current codebase (`dev` branch) + MIGRATION_L8_TO_L12.md plan  
> **Date:** 2026-04-06  
> **Scope:** Wrong/broken implementations, logic bugs, dead code, Octane/PHP 8.2 hazards

---

## Severity Legend

| Level | Meaning |
|-------|---------|
| 🔴 CRITICAL | Will crash at runtime or silently corrupt data |
| 🟠 HIGH | Wrong behavior, security risk, or feature completely broken |
| 🟡 MEDIUM | Likely to cause subtle bugs or fail under certain conditions |
| 🟢 LOW | Code smell, deprecated API, or migration risk |

---

## 1. `BaseController::sendError` — Missing default for `$code` 🟠 HIGH

**File:** [app/Http/Controllers/BaseController.php:45](app/Http/Controllers/BaseController.php#L45)

```php
// Current — PHP will throw an ArgumentCountError if $code is omitted
public function sendError($error, $errorMessages = [], $code)
```

**Problem:** `$code` has no default value. In PHP 8.x, a required parameter after optional parameters is a deprecation error that was fatal in earlier versions, and PHP 8.1+ emits a deprecation. Any call that somehow omits `$code` will fail. The migration plan already documents this fix.

**Fix:**
```php
public function sendError($error, $errorMessages = [], $code = 400): \Illuminate\Http\JsonResponse
```

---

## 2. `BannerController` — `config('constants.image_orientation', 'code')` Bug 🔴 CRITICAL

**File:** [app/Http/Controllers/Admin/V2/BannerController.php:90](app/Http/Controllers/Admin/V2/BannerController.php#L90) and [line 165](app/Http/Controllers/Admin/V2/BannerController.php#L165)

```php
// WRONG — second arg to config() is the *default* value if key missing, not the column name
'image_orientation' => 'required|in:' . implode(',', array_column(config('constants.image_orientation', 'code'), 'code')),
```

**Problem:** `config('constants.image_orientation', 'code')` returns the string `'code'` if the config key is missing — passing a string to `array_column()` will return an empty array, making all `image_orientation` values fail validation. Even when the key exists, this passes the correct config, but the second argument is silently ignored in that case — only masking the bug.

**Fix:**
```php
array_column(config('constants.image_orientation'), 'code')
```

---

## 3. `BannerController::updateBanner` — Creates a NEW banner instead of updating 🔴 CRITICAL

**File:** [app/Http/Controllers/Admin/V2/BannerController.php:191-228](app/Http/Controllers/Admin/V2/BannerController.php#L191)

```php
// When bannerable_id + bannerable_type are present:
$banner = new Banner;
$banner->name = $request->name;
// ... sets properties ...
$banner = Banner::create(array_filter(json_decode($banner, true)));
return $this->sendResponse([645], 'Banner updated successfully...!');  // ← hardcoded [645]!
```

**Problems:**
1. The method is named `updateBanner` and validates an `id` field, but when `bannerable_id`/`bannerable_type` are present it creates a brand-new Banner record, completely ignoring `$request->id`. The original banner is never updated.
2. The response returns a hardcoded `[645]` — an obvious debug artifact left in production code.
3. `json_decode($banner, true)` on an Eloquent model does not serialize it to JSON first — this will likely return `null` and `Banner::create(null)` will throw an error.

---

## 4. `AuthController::register` / `updateProfile` / `updateEmail` / `isVerifiedEmail` — Dead `Log::error` after `throw` 🟠 HIGH

**File:** [app/Http/Controllers/AuthController.php:272-275](app/Http/Controllers/AuthController.php#L272)

```php
} catch (\Throwable $th) {
    throw $th;           // ← exception re-thrown here
    Log::error($th->getMessage()); // ← DEAD CODE, never reached
}
```

**Affected lines:** 272–275, 359–362, 388–391, 453–456

**Problem:** `Log::error` is placed after `throw $th`, making it unreachable dead code. Either the exception should be caught and logged (and a proper error response returned), or re-thrown without the dead log statement. Silently swallowing exceptions without logging means production errors are invisible.

---

## 5. `AuthController::updateEmail` — Calling methods on an integer 🔴 CRITICAL

**File:** [app/Http/Controllers/AuthController.php:427-446](app/Http/Controllers/AuthController.php#L427)

```php
$user = User::where($whereIdEmail)->update(['email' => $request->new_email]);
// $user is now an integer (rows affected count), NOT a User model

if (!$user) { ... }

$otp = random_int(100000, 999999);
$otpCreatedAt = Carbon::parse($user->otp_created_at);  // ← Fatal: Accessing property on int
$now = Carbon::now();

if ($user->otp_created_at != null && ...) { ... }      // ← Fatal

$user->update($updateStatus);                           // ← Fatal
```

**Problem:** `Eloquent::update()` returns an `int` (number of rows updated). All subsequent `$user->...` calls will throw `TypeError: Attempt to read property "otp_created_at" on int`. This entire method is broken — the email update itself may succeed, but OTP sending always crashes.

**Fix:** Fetch the user model separately after the update:
```php
User::where($whereIdEmail)->update(['email' => $request->new_email]);
$user = User::where('id', $request->id)->first();
```

---

## 6. `AuthController::getAllFavourites` — Query never executed, always returns wrong result 🔴 CRITICAL

**File:** [app/Http/Controllers/AuthController.php:611-632](app/Http/Controllers/AuthController.php#L611)

```php
$favourites = User::withCount('favourites')
    ->with('favourites')
    ->groupBy('favourites.favouritable_id')
    ->latest()
    ->whereId($id);    // ← Builder object returned, NOT executed

logger($favourites->toSql());  // ← debug logger left in production

if (is_null($favourites)) {    // ← Always false — Builder is never null
    return $this->sendError('Empty', [], 404);
}

return $this->sendResponse($favourites, 'Favourites successfully Retrieved...!');
// ← Returns a Builder object serialized as JSON — completely wrong
```

**Problems:**
1. Missing `->get()` or `->first()` — query is never executed.
2. `is_null($favourites)` always false because a Builder is returned.
3. `logger($favourites->toSql())` — debug statement left in production code.
4. `groupBy('favourites.favouritable_id')` will fail in MySQL strict mode (`GROUP BY` on a join requires all selected columns).

---

## 7. `AuthController::googleAuth` — Blocks login for users without an address 🟠 HIGH

**File:** [app/Http/Controllers/AuthController.php:727-730](app/Http/Controllers/AuthController.php#L727)

```php
if ($user) {
    if ($user->addresses->isEmpty()) {
        return $this->sendError('Something went wrong please contact us', [], 200);
    }
    // ... proceeds to login
}
```

**Problem:** Existing users with no saved address (e.g., registered via email before location was added, or address was later deleted) are permanently blocked from logging in via Google. An address should not be a prerequisite for authentication. This is a functional bug that would silently lock users out.

---

## 8. `EventObserver::creating` — Slug not unique 🟠 HIGH

**File:** [app/Observers/EventObserver.php:13](app/Observers/EventObserver.php#L13)

```php
$event->slug = Str::slug($event->title);
```

**Problem:** If two events have the same title (e.g., "Ganesh Utsav 2026"), both get the same slug `ganesh-utsav-2026`. If the `slug` column has a unique index (as it should for routing), the second insert will throw a `QueryException`. If there is no unique index, `Event::where('slug', $slug)->firstOrFail()` in `EventController::show()` will return the first match non-deterministically.

**Fix:** Ensure uniqueness:
```php
$base = Str::slug($event->title);
$slug = $base;
$i = 1;
while (Event::where('slug', $slug)->exists()) {
    $slug = $base . '-' . $i++;
}
$event->slug = $slug;
```

---

## 9. `PreMiddleware` — Octane-unsafe runtime config mutation 🟠 HIGH

**File:** [app/Http/Middleware/PreMiddleware.php:29-36](app/Http/Middleware/PreMiddleware.php#L29)

```php
config([
    'user' => $user->load(['roles']),
    'user_id' => $user->id,
    'language' => $user->language
]);

config(['app_version' => Cache::has('app_version') ? ... : AppVersion::latest()->first()->version_number]);
```

**Problem:** `config()` mutates a singleton config repository. Under Laravel Octane (Swoole/RoadRunner), the application container is kept alive across requests. If `config('user')` is set in request A and request B arrives before it's overwritten, request B may see request A's user. This is a **security vulnerability** and **data leak** in an Octane deployment.

The migration plan flags this but it is an active problem even today if Octane is tested locally.

**Fix:** Use `$request->attributes->set('user', $user)` or pass user via DI, not global config.

---

## 10. `FirebaseService` — Uses deprecated Legacy FCM API (Push Notifications broken) 🔴 CRITICAL

**File:** [app/Services/FirebaseService.php:108-111](app/Services/FirebaseService.php#L108)

```php
$response = Http::withHeaders([
    'Authorization' => 'key=' . $this->serverKey,  // ← Legacy FCM API
    'Content-Type'  => 'application/json',
])->post($this->fcmUrl, $payload);
```

**Problem:** Google deprecated the Firebase Cloud Messaging Legacy HTTP API and **shut it down on June 20, 2024**. The `key=<server_key>` authorization format and `registration_ids` payload structure are legacy and no longer accepted by FCM. All push notifications (event reminders, test notifications) are currently **non-functional**.

**Fix:** Migrate to the FCM HTTP v1 API which uses OAuth 2.0 Bearer tokens and a different payload format (`message.token`, `message.notification`, etc.).

---

## 11. `BonusTypes` Model — `$dates` property deprecated 🟢 LOW

**File:** [app/Models/BonusTypes.php:46](app/Models/BonusTypes.php#L46)

```php
protected $dates = ['deleted_at'];  // deprecated in Laravel 10+
```

**Fix:** Move to `$casts`:
```php
protected $casts = ['deleted_at' => 'datetime'];
```

**Also:** [app/Models/Wallet.php:47](app/Models/Wallet.php#L47) has `protected $dates = []` — empty and unnecessary, remove it.

---

## 12. Import Classes — `throw $th->getMessage()` Fatal Type Error 🔴 CRITICAL

**Files:**
- [app/Imports/ProductCategoryImport.php:34](app/Imports/ProductCategoryImport.php#L34)
- [app/Imports/PlaceCategoryImport.php:35](app/Imports/PlaceCategoryImport.php#L35)
- [app/Imports/PlaceImport.php:91](app/Imports/PlaceImport.php#L91)

```php
throw $th->getMessage();  // getMessage() returns a string, not a Throwable
```

**Problem:** PHP 8 requires `throw` to receive a `Throwable` — throwing a `string` causes a fatal `TypeError`. Any import failure will produce an unhandled error instead of a caught exception. Similarly, [app/Imports/SiteImport.php:115](app/Imports/SiteImport.php#L115) has `throw $th()` — calling a Throwable as a function, which is also invalid.

**Fix:** Use `throw $th;` in all four files.

---

## 13. `AuthController::deleteMyAccount` — No return on exception 🟡 MEDIUM

**File:** [app/Http/Controllers/AuthController.php:686-688](app/Http/Controllers/AuthController.php#L686)

```php
} catch (\Throwable $th) {
    Log::error($th->getMessage());
    // ← No return statement — method implicitly returns null
}
```

**Problem:** If an exception is caught, the method returns `null` implicitly. Laravel will then try to convert `null` to a Response object and throw another error. The user gets a 500 with no useful message.

---

## 14. `GalleryController` (Admin) — Validation rule `'alpha'` on search field 🟡 MEDIUM

**File:** [app/Http/Controllers/Admin/V2/GalleryController.php:22](app/Http/Controllers/Admin/V2/GalleryController.php#L22)

```php
'search' => 'sometimes|required|string|alpha|max:255',
```

**Problem:** The `alpha` rule only allows pure alphabetic characters. Any search containing spaces, numbers, or special characters (e.g., `"Ram Ghat"`, `"site 42"`) will fail validation. The user-facing `GalleryController` correctly uses no such restriction. Admin gallery search is effectively broken for any realistic query.

**Fix:** Remove `alpha` or replace with `alpha_num_spaces` / just `string`.

---

## 15. `GalleryController` (Admin) — Category filter only works when search is ALSO provided 🟡 MEDIUM

**File:** [app/Http/Controllers/Admin/V2/GalleryController.php:47-57](app/Http/Controllers/Admin/V2/GalleryController.php#L47)

```php
// Category applied only when BOTH search AND category are present
$galleryQuery->when($request->has('search') && $request->has('category') && ..., ...);

// Search applied only when search is provided WITHOUT category
$galleryQuery->when($request->has('search') && empty($request->input('category')), ...);
```

**Problem:** Filtering by `category` alone (without a search term) is silently ignored. A request with only `category=temple` returns unfiltered results. The user-facing `GalleryController` handles this correctly with independent filters.

---

## 16. `AuthController` — `config('user')` used before being set 🟠 HIGH

**File:** [app/Http/Controllers/AuthController.php:50](app/Http/Controllers/AuthController.php#L50)

```php
public function allUsers()
{
    if (in_array(config('user')->roles->code, ['superadmin', 'admin'])) {
```

**Problem:** `config('user')` is set by `PreMiddleware`. If this route is ever accessed without `premiddleware` applied (e.g., during testing or route misconfiguration), `config('user')` returns `null` and `->roles->code` throws a fatal `TypeError`. The method should use `auth()->user()` directly for role checking, consistent with the rest of the codebase.

---

## 17. `Category_old.php` — Stale model file left in codebase 🟢 LOW

**File:** [app/Models/Category_old.php](app/Models/Category_old.php)

An old model file named `Category_old.php` exists in the models directory. This is leftover dead code that should be deleted to avoid confusion and prevent it from being accidentally autoloaded or referenced.

---

## 18. `WalletController` (root namespace) — Empty stub controller routed nowhere 🟢 LOW

**File:** [app/Http/Controllers/WalletController.php](app/Http/Controllers/WalletController.php)

All methods are empty stubs with no implementation. The actual wallet logic appears to be routed through `User/V2/WalletController`. This empty file should be deleted or completed.

---

## 19. `isValidReturn()` Helper — Logic bug when `$key === null` 🟡 MEDIUM

**File:** [app/helpers.php:99-103](app/helpers.php#L99)

```php
function isValidReturn($value, $key = null, $ret = null)
{
    if ($key == null) {
        if (is_array($value) && !isset($value[$key]))   // $value[null] == $value[0]
            return $ret;
        else if (is_array($value) && isset($value[$key]))
            return $value[$key];
        ...
    }
```

**Problem:** When `$key === null`, `$value[$key]` in PHP is `$value[0]` (arrays with integer keys) or throws an error. The intent is likely to check if `$value` itself is non-empty, not to index into it with `null`. The branch `is_array($value) && !isset($value[$key])` would typically be true for most arrays (since most don't have a `null` key), causing the function to incorrectly return `$ret` for valid arrays.

In practice, this function is almost never called with `$key = null`, so the impact is low but the logic is wrong.

---

## Summary Table

| # | File | Severity | Issue |
|---|------|----------|-------|
| 1 | `BaseController.php:45` | 🟠 HIGH | `sendError` missing `$code` default |
| 2 | `BannerController.php:90,165` | 🔴 CRITICAL | `config()` bug breaks `image_orientation` validation |
| 3 | `BannerController.php:191-228` | 🔴 CRITICAL | `updateBanner` creates new record instead of updating; hardcoded `[645]` response |
| 4 | `AuthController.php:273,360,389,454` | 🟠 HIGH | `Log::error` dead code after `throw $th` |
| 5 | `AuthController.php:427-446` | 🔴 CRITICAL | `updateEmail` calls methods on an `int` — always crashes |
| 6 | `AuthController.php:611-632` | 🔴 CRITICAL | `getAllFavourites` never executes query, returns Builder object |
| 7 | `AuthController.php:727-730` | 🟠 HIGH | Google login blocks users without addresses |
| 8 | `EventObserver.php:13` | 🟠 HIGH | Event slugs not unique — DB collision on duplicate titles |
| 9 | `PreMiddleware.php:29-36` | 🟠 HIGH | Octane-unsafe config mutation — potential user data leak |
| 10 | `FirebaseService.php:108` | 🔴 CRITICAL | Legacy FCM API deprecated June 2024 — push notifications broken |
| 11 | `BonusTypes.php:46`, `Wallet.php:47` | 🟢 LOW | `$dates` deprecated, use `$casts` |
| 12 | `ProductCategoryImport.php`, `PlaceCategoryImport.php`, `PlaceImport.php`, `SiteImport.php` | 🔴 CRITICAL | `throw $th->getMessage()` / `throw $th()` — invalid PHP, fatal error |
| 13 | `AuthController.php:686-688` | 🟡 MEDIUM | `deleteMyAccount` has no return on exception path |
| 14 | `Admin/V2/GalleryController.php:22` | 🟡 MEDIUM | `alpha` validation breaks admin gallery search |
| 15 | `Admin/V2/GalleryController.php:47-57` | 🟡 MEDIUM | Category filter silently ignored when no search term |
| 16 | `AuthController.php:50` | 🟠 HIGH | `config('user')` may be null — depends on middleware order |
| 17 | `Models/Category_old.php` | 🟢 LOW | Stale model file should be deleted |
| 18 | `Http/Controllers/WalletController.php` | 🟢 LOW | Empty stub controller, dead code |
| 19 | `helpers.php:99-103` | 🟡 MEDIUM | `isValidReturn()` logic wrong when `$key === null` |

---

## Recommended Fix Priority

### Fix Before ANY Migration
1. **#10 Firebase FCM** — Push notifications are currently completely broken (not a migration issue)
2. **#3 updateBanner** — Data corruption bug live in production
3. **#5 updateEmail** — Feature crashes on every call
4. **#12 Import throw** — All imports silently fail on any error

### Fix During Migration (Required for L12/PHP 8.2)
5. **#2 config() bug** — Breaks banner orientation validation
6. **#1 sendError default** — Required for PHP 8.2 strict param rules
7. **#11 $dates deprecated** — Deprecated in L10, warning in L12
8. **#9 PreMiddleware Octane state** — Must fix before Octane goes live

### Fix After Migration
9. **#6, #7, #8, #13, #14, #15, #16** — Functional bugs
10. **#4** — Dead code cleanup
11. **#17, #18, #19** — Cleanup
