# Laravel 8.65 → Laravel 12 — Final Migration Plan

> **Branch:** `feature/laravel-12-upgrade` (from `dev`)  
> **PHP Target:** 8.2  
> **Includes:** All CODE_AUDIT bug fixes + FCM v1 migration  
> **Date:** 2026-04-06

---

## Phase 1 — Git Branch + Backup

1. `git checkout dev && git pull origin dev`
2. `git checkout -b feature/laravel-12-upgrade`
3. `git tag pre-l12-migration` — rollback reference

---

## Phase 2 — `composer.json` Changes

### require

| Package | Old | New |
|---|---|---|
| `php` | `^7.3\|^8.0` | `^8.2` |
| `laravel/framework` | `^8.65` | `^12.0` |
| `laravel/sanctum` | `^2.11` | `^4.0` |
| `tymon/jwt-auth` | `^1.0@dev` | `^2.1` |
| `fruitcake/laravel-cors` | `^3.0` | **DELETE** (native in L12) |
| `laravel/ui` | `^3.4` | **DELETE** (API-only project) |
| `saiashirwadinformatia/secure-ids` | `^1.1` | **DELETE** (call already commented out) |

Keep as-is: `doctrine/dbal`, `guzzlehttp/guzzle`, `laravel/socialite`, `laravel/tinker`, `maatwebsite/excel`, `spatie/geocoder`.

**ADD:** `google/auth` (for FCM v1 — Phase 8)

### require-dev

| Package | Old | New |
|---|---|---|
| `facade/ignition` | `^2.5` | **DELETE** |
| `spatie/laravel-ignition` | — | `^2.0` **(ADD)** |
| `nunomaduro/collision` | `^5.10` | `^8.0` |
| `phpunit/phpunit` | `^9.5.10` | `^11.0` |

Then: `composer update --with-all-dependencies`

---

## Phase 3 — Framework Structure

### 3a. Rewrite `bootstrap/app.php` (complete rewrite)

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            Route::middleware('api')
                ->prefix('admin')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
        ]);
        $middleware->alias([
            'auth'          => \App\Http\Middleware\Authenticate::class,
            'guest'         => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'admin'         => \App\Http\Middleware\AdminAccessMiddleware::class,
            'premiddleware' => \App\Http\Middleware\PreMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(fn(TokenExpiredException $e) =>
            response()->json(['success' => false, 'message' => 'Token has expired'], 401));
        $exceptions->render(fn(TokenInvalidException $e) =>
            response()->json(['success' => false, 'message' => 'Token is invalid'], 401));
        $exceptions->render(fn(JWTException $e) =>
            response()->json(['success' => false, 'message' => 'Token is absent'], 401));
    })
    ->create();
```

### 3b. Files to DELETE

| File | Reason |
|---|---|
| `app/Http/Kernel.php` | Replaced by `bootstrap/app.php` |
| `app/Console/Kernel.php` | Schedule → `routes/console.php` |
| `app/Providers/RouteServiceProvider.php` | Routes in `bootstrap/app.php` |
| `app/Providers/AuthServiceProvider.php` | Empty — no policies |
| `app/Providers/EventServiceProvider.php` | Auto-handled in L12 |
| `app/Providers/BroadcastServiceProvider.php` | Not needed |
| `app/Exceptions/Handler.php` | Logic in `bootstrap/app.php` |

### 3c. `routes/console.php` — add scheduler

```php
use Illuminate\Support\Facades\Schedule;
Schedule::command('events:send-reminders')->hourly();
Schedule::command('events:mark-completed')->dailyAt('00:30');
```

### 3d. `config/app.php` — remove deleted providers from `providers` array

Remove: `RouteServiceProvider`, `AuthServiceProvider`, `EventServiceProvider`, `BroadcastServiceProvider`

---

## Phase 4 — Config Updates

### `config/cors.php`
```php
'paths' => ['api/*', 'admin/*', 'sanctum/csrf-cookie'],
```

### `config/auth.php` — update password table name
```php
'table' => 'password_reset_tokens',  // was 'password_resets'
```

### After `composer update`:
```bash
php artisan vendor:publish --tag=sanctum-config --force
# merge custom values back
```

### Delete `config/secure_ids.php`

---

## Phase 5 — Model Fixes

### `app/Models/BonusTypes.php`
- Remove `protected $dates = ['deleted_at'];` — `SoftDeletes` already handles this

### `app/Models/Wallet.php`
- Remove empty `protected $dates = [];`

### Scan all models:
```bash
grep -rn "protected \$dates" app/Models/
```
Move any remaining entries to `$casts` as `'column' => 'datetime'`

### Delete stale files:
- `app/Models/Category_old.php`
- `app/Http/Controllers/User/V2/WalletController.php` (wrong namespace, empty stub)

---

## Phase 6 — Middleware Fixes

### `PreMiddleware.php` — Octane-safe rewrite

**Problem:** `config(['user' => ..., 'user_id' => ..., 'language' => ...])` mutates shared singleton state — data leak under Octane.

**Fix:** Use `$request->attributes->set(...)` instead:
```php
public function handle(Request $request, Closure $next)
{
    $parts    = explode('/', $request->path());
    $endpoint = end($parts);

    if (!in_array($endpoint, config('urls')['non_session_url'])) {
        $user = Auth::user();
        $request->attributes->set('auth_user',    $user->load(['roles']));
        $request->attributes->set('auth_user_id', $user->id);
        $request->attributes->set('language',     $user->language);
    }

    $appVersion = Cache::has('app_version')
        ? Cache::get('app_version')->version_number
        : AppVersion::latest()->first()->version_number;

    $request->attributes->set('app_version', $appVersion);

    return $next($request);
}
```

**After:** Search and replace all `config('user')`, `config('user_id')`, `config('language')`, `config('app_version')` usages:
```bash
grep -rn "config('user')\|config('user_id')\|config('language')\|config('app_version')" app/
```

Update `BaseController::sendResponse` to use `request()->attributes->get('app_version')` and `request()->attributes->get('language')`.

### `Authenticate.php` — update `redirectTo` signature
```php
protected function redirectTo(Request $request): ?string
{
    return $request->expectsJson() ? null : route('login');
}
```

---

## Phase 7 — Controller Bug Fixes (CODE_AUDIT)

### 7a. `BaseController::sendError` — add `$code` default
```php
public function sendError($error, $errorMessages = [], $code = 400)
```

### 7b. `BannerController` lines 90 & 165 — fix config() bug
```php
// Before
config('constants.image_orientation', 'code')
// After
config('constants.image_orientation')
```

### 7c. `BannerController::updateBanner` — fix creates new record instead of updating
Replace the `if ($request->bannerable_id && $request->bannerable_type)` block:
```php
$banner = Banner::findOrFail($request->id);  // find existing
$banner->fill(array_filter([
    'name'              => $request->name,
    'image'             => $image,
    'start_date'        => $request->start_date,
    'duration'          => $request->duration,
    'level'             => $request->level,
    'image_orientation' => $request->image_orientation,
    'status'            => $request->status,
    'meta_data'         => $request->meta_data,
], fn($v) => !is_null($v)));
$banner->bannerable()->associate($data);
$banner->save();
return $this->sendResponse($banner, 'Banner updated successfully...!');
// Remove hardcoded [645]
```

### 7d. `AuthController` — remove dead `Log::error` after `throw $th`
Lines 274, 361, 390, 455 — delete the `Log::error(...)` line after each `throw $th;`

### 7e. `AuthController::updateEmail` — fix int vs model bug
```php
// Before
$user = User::where($whereIdEmail)->update(['email' => $request->new_email]);
// After
$updated = User::where($whereIdEmail)->update(['email' => $request->new_email]);
if (!$updated) {
    return $this->sendError('Unable to change email', '', 200);
}
$user = User::where('id', $request->id)->first();
```

### 7f. `AuthController::getAllFavourites` — fix broken query
```php
public function getAllFavourites($id)
{
    $user = User::with('favourites')->find($id);
    if (is_null($user)) {
        return $this->sendError('User not found', [], 404);
    }
    $favourites = $user->favourites;
    if ($favourites->isEmpty()) {
        return $this->sendError('Empty', [], 404);
    }
    return $this->sendResponse($favourites, 'Favourites successfully Retrieved...!');
}
```

### 7g. `AuthController::googleAuth` — remove address check blocking login
Remove the `if($user->addresses->isEmpty())` block.  
Change query from `User::with('addresses')->where(...)` to `User::where(...)`.

### 7h. `AuthController::deleteMyAccount` — add return on exception
```php
} catch (\Throwable $th) {
    Log::error($th->getMessage());
    return $this->sendError('Something went wrong', [], 500);
}
```

### 7i. `EventObserver::creating` — unique slug generation
```php
$baseSlug = Str::slug($event->title);
$slug = $baseSlug;
$i = 1;
while (Event::where('slug', $slug)->exists()) {
    $slug = $baseSlug . '-' . $i++;
}
$event->slug = $slug;
```

### 7j. `Admin/V2/GalleryController` — fix search validation & category-only filter
- Change `'search' => '...alpha...'` to `'search' => 'sometimes|nullable|string|max:255'`
- Add a standalone `when()` for category without search

---

## Phase 8 — Firebase FCM v1 Migration

**Background:** Legacy FCM API (key= header) was shut down June 2024. All push notifications are currently non-functional.

### Steps:
1. Download Firebase service account JSON → store path in `.env` as `FIREBASE_CREDENTIALS`
2. `composer require google/auth`
3. Update `config/services.php`:
   ```php
   'firebase' => [
       'credentials' => env('FIREBASE_CREDENTIALS'),
       'project_id'  => env('FIREBASE_PROJECT_ID'),
       'fcm_url'     => 'https://fcm.googleapis.com/v1/projects/' . env('FIREBASE_PROJECT_ID') . '/messages:send',
   ],
   ```
4. Rewrite `FirebaseService`:
   - New `getAccessToken()` method using `google/auth` `ServiceAccountCredentials`
   - Replace `key=` header with `Bearer <oauth_token>`
   - Replace `registration_ids` payload with per-token loop (v1 is single-message only)
   - Replace `to: "/topics/{$topic}"` with `topic: $topic` in v1 message format
   - Update invalid token detection: v1 returns `error.status == 'UNREGISTERED'`

---

## Phase 9 — Import File Fixes

Fix `throw $th->getMessage()` → `throw $th` in:
- `app/Imports/ProductCategoryImport.php:34`
- `app/Imports/PlaceCategoryImport.php:35`
- `app/Imports/PlaceImport.php:91`
- `app/Imports/SiteImport.php:115` (`throw $th()` → `throw $th`)

---

## Phase 10 — Database Migrations

### `password_resets` → `password_reset_tokens`
Create migration:
```php
Schema::rename('password_resets', 'password_reset_tokens');
Schema::table('password_reset_tokens', function (Blueprint $table) {
    $table->primary('email');
});
```

Then: `php artisan migrate`

> `failed_jobs.uuid` column already exists — no action needed.

---

## Phase 11 — JWT v2 Setup

```bash
php artisan vendor:publish --provider="Tymon\JWTAuth\Providers\LaravelServiceProvider" --force
php artisan jwt:secret   # rotates JWT_SECRET — schedule for maintenance window
```

Verify `User` model has both `getJWTIdentifier()` and `getJWTCustomClaims()` (already present).

---

## Phase 12 — Verify + Commit

```bash
php artisan config:clear && php artisan cache:clear && php artisan route:clear
php artisan about          # should boot cleanly
php artisan route:list     # verify api/* and admin/* routes load
./vendor/bin/phpunit       # run test suite
```

Manual smoke tests:
- JWT login/register/logout
- Admin login with role check
- `updateBanner` — must update existing record, not create new
- Google OAuth login for user without address (should succeed now)
- Gallery search with multi-word query (should not fail `alpha` validation)
- Push notification test endpoint
- Banner `image_orientation` validation

```bash
git add .
git commit -m "feat: Laravel 8.65 → 12 migration + CODE_AUDIT fixes + FCM v1"
git push origin feature/laravel-12-upgrade
# Then open PR: feature/laravel-12-upgrade → main
```

---

## Ordered Execution Checklist

- [ ] Phase 1 — Create branch + tag
- [ ] Phase 2 — Update composer.json + `composer update`
- [ ] Phase 3 — Rewrite bootstrap/app.php, delete old providers/kernels
- [ ] Phase 4 — Config files (cors, auth, sanctum, delete secure_ids)
- [ ] Phase 5 — Model fixes + delete stale files
- [ ] Phase 6 — PreMiddleware Octane fix + update all config('user') usages
- [ ] Phase 7 — All 10 controller bug fixes
- [ ] Phase 8 — Firebase FCM v1 rewrite
- [ ] Phase 9 — Import throw fixes
- [ ] Phase 10 — Database migration (password_reset_tokens)
- [ ] Phase 11 — JWT v2 publish + secret
- [ ] Phase 12 — Clear caches, test, commit, push PR
