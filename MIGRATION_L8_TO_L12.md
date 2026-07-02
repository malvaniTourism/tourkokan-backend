# Tourkokan — Laravel 8 → Laravel 12 + Octane Migration Plan

> **Project:** Tourkokan Backend  
> **Current Stack:** Laravel 8.65, PHP 7.3/8.0, JWT Auth, MySQL, Sync Queue  
> **Target Stack:** Laravel 12, PHP 8.2+, JWT Auth v2, MySQL, Redis Queue, Laravel Octane (Swoole/RoadRunner)  
> **Migration Style:** In-place (branch-based, not rewrite)

---

## Table of Contents

1. [Pre-Migration Checklist](#1-pre-migration-checklist)
2. [PHP Version Upgrade](#2-php-version-upgrade)
3. [Composer & Package Upgrades](#3-composer--package-upgrades)
4. [Framework Breaking Changes](#4-framework-breaking-changes)
5. [Middleware Migration](#5-middleware-migration)
6. [Routing Changes](#6-routing-changes)
7. [Authentication & JWT Migration](#7-authentication--jwt-migration)
8. [Database & Migrations](#8-database--migrations)
9. [Model Changes](#9-model-changes)
10. [Controller Changes](#10-controller-changes)
11. [API Payload & Response Compatibility](#11-api-payload--response-compatibility)
12. [Service Providers](#12-service-providers)
13. [Queue & Jobs Migration](#13-queue--jobs-migration)
14. [Console Commands & Scheduler](#14-console-commands--scheduler)
15. [Mail & Notifications](#15-mail--notifications)
16. [Helper Functions](#16-helper-functions)
17. [Configuration Files](#17-configuration-files)
18. [Environment Variables](#18-environment-variables)
19. [Laravel Octane Setup](#19-laravel-octane-setup)
20. [Testing Strategy](#20-testing-strategy)
21. [Deployment Steps](#21-deployment-steps)
22. [Rollback Plan](#22-rollback-plan)

---

## 1. Pre-Migration Checklist

Before touching any code:

- [ ] Create a new git branch: `git checkout -b feature/laravel-12-migration`
- [ ] Take a full database dump: `mysqldump -u root -p tourkokan > backup_$(date +%Y%m%d).sql`
- [ ] Document current PHP version: `php -v`
- [ ] Run existing test suite (even if minimal) to capture current behavior baseline
- [ ] Pin down all current API response structures (use the existing Postman collection)
- [ ] Ensure Redis is available on the server (needed for Octane + queue)
- [ ] Confirm server supports PHP 8.2+ (Swoole extension for Octane)

---

## 2. PHP Version Upgrade

### Required: PHP 8.2+

**Breaking changes to fix in this codebase:**

### 2.1 Deprecated `${var}` string interpolation
PHP 8.2 deprecates (8.3 removes) `"${varName}"` — search and replace:
```bash
# Find all occurrences
grep -rn '\${' app/ --include="*.php"
```
Replace with `"{$varName}"` syntax.

### 2.2 Dynamic properties deprecated
PHP 8.2 raises deprecation warnings for dynamic (undeclared) properties on classes.  
The `updateBanner()` method in `BannerController` uses:
```php
$banner = new Banner;
$banner->name = $request->name;  // dynamic property if not in $fillable
```
Ensure all assigned properties are declared in `$fillable` or as typed properties.

### 2.3 `null` passed to non-nullable parameters
PHP 8.1+ is strict about passing `null` to typed parameters. Audit all helpers:
```php
// helpers.php - add null checks
function getData($id, $model): mixed { ... }
```

### 2.4 `array_column` with null second arg
The existing validation in `BannerController`:
```php
array_column(config('constants.image_orientation', 'code'), 'code')
// Bug: 'code' is the default, not the column — fix to:
array_column(config('constants.image_orientation'), 'code')
```

### 2.5 `composer.json` PHP constraint
```json
"require": {
    "php": "^8.2"
}
```

---

## 3. Composer & Package Upgrades

### 3.1 Core Framework

```json
"require": {
    "php": "^8.2",
    "laravel/framework": "^12.0",
    "laravel/octane": "^2.0",
    "laravel/sanctum": "^4.0",
    "laravel/socialite": "^5.16",
    "laravel/tinker": "^2.9",
    "guzzlehttp/guzzle": "^7.8",
    "tymon/jwt-auth": "^2.1",
    "maatwebsite/excel": "^3.1",
    "doctrine/dbal": "^3.7",
    "spatie/geocoder": "^3.15"
}
```

### 3.2 Remove / Replace

| Package (L8) | Action | Replacement (L12) |
|---|---|---|
| `fruitcake/laravel-cors` | **Remove** | Native Laravel CORS (`config/cors.php` built-in) |
| `laravel/ui` | **Remove** | Not needed for API-only backend |
| `saiashirwadinformatia/secure-ids` | **Verify** compatibility or replace with `hashids/hashids` |
| `facade/ignition` | **Replace** | `spatie/laravel-ignition ^2.0` |
| `nunomaduro/collision` | **Update** | `^8.0` |
| `phpunit/phpunit` | **Update** | `^11.0` |

### 3.3 Require Dev

```json
"require-dev": {
    "spatie/laravel-ignition": "^2.0",
    "fakerphp/faker": "^1.23",
    "laravel/sail": "^1.29",
    "mockery/mockery": "^1.6",
    "nunomaduro/collision": "^8.0",
    "phpunit/phpunit": "^11.0"
}
```

### 3.4 After Updating composer.json

```bash
composer update --with-all-dependencies
# If conflicts:
composer install --ignore-platform-reqs  # diagnose first
```

---

## 4. Framework Breaking Changes

### 4.1 `RouteServiceProvider` — Removed in L11+

Laravel 11/12 ships with a flat `bootstrap/app.php` approach. The `RouteServiceProvider` no longer exists by default.

**Current (L8):** `app/Providers/RouteServiceProvider.php` registers routes with prefixes.

**Migration:** Move route registration to `bootstrap/app.php`:

```php
// bootstrap/app.php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->prefix('admin')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // see Section 5
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // see Handler migration below
    })
    ->create();
```

**Delete:** `app/Providers/RouteServiceProvider.php`

### 4.2 HTTP Kernel — Removed in L11+

`app/Http/Kernel.php` no longer exists. Middleware is registered in `bootstrap/app.php`.

**Current middleware stack to migrate:**

```php
// bootstrap/app.php → withMiddleware()
->withMiddleware(function (Middleware $middleware) {
    // Global middleware
    $middleware->use([
        \Illuminate\Http\Middleware\TrustProxies::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Http\Middleware\ValidatePostSize::class,
        \Illuminate\Foundation\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    ]);

    // API middleware group
    $middleware->group('api', [
        \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ]);

    // Named (route) middleware
    $middleware->alias([
        'admin'         => \App\Http\Middleware\AdminAccessMiddleware::class,
        'premiddleware' => \App\Http\Middleware\PreMiddleware::class,
        'auth'          => \App\Http\Middleware\Authenticate::class,
        'guest'         => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'throttle'      => \Illuminate\Routing\Middleware\ThrottleRequests::class,
    ]);
})
```

### 4.3 Exception Handler — Changed in L11+

`app/Exceptions/Handler.php` no longer extends `Illuminate\Foundation\Exceptions\Handler`.  
Move exception handling to `bootstrap/app.php`:

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(function (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
        return response()->json(['success' => false, 'message' => 'Token expired'], 401);
    });
    $exceptions->render(function (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
        return response()->json(['success' => false, 'message' => 'Token invalid'], 401);
    });
    $exceptions->render(function (\Tymon\JWTAuth\Exceptions\JWTException $e) {
        return response()->json(['success' => false, 'message' => 'Token absent'], 401);
    });
})
```

### 4.4 `EventServiceProvider` — Removed in L11+

**Delete** `app/Providers/EventServiceProvider.php`.  
Register event listeners in `bootstrap/app.php` or use auto-discovery:

```php
// routes/console.php or AppServiceProvider
Event::listen(
    \Illuminate\Auth\Events\Registered::class,
    \Illuminate\Auth\Listeners\SendEmailVerificationNotification::class,
);
```

### 4.5 `BroadcastServiceProvider` — Removed in L11+

Broadcasting now auto-boots. Remove `app/Providers/BroadcastServiceProvider.php`.  
Ensure `routes/channels.php` exists (it does).

### 4.6 `AuthServiceProvider` — Removed in L11+

Move any gate/policy definitions to `AppServiceProvider::boot()`.  
Current `AuthServiceProvider` is empty — safe to delete.

### 4.7 Castable / Enum Columns (New L12 behavior)

If you add PHP 8.1 enums to models later, use `$casts` with enum class. No current impact but be aware.

### 4.8 `Model::unguard()` behavior

No changes needed — existing `$fillable` arrays are already defined on models.

---

## 5. Middleware Migration

### 5.1 `PreMiddleware` — Review Required

```php
// app/Http/Middleware/PreMiddleware.php
// This loads: app version check, user config, language setting
// OCTANE RISK: if it stores state in static properties — must be stateless
```

**Action:** Open `PreMiddleware.php` and ensure:
- No static properties that persist between requests
- No singleton state that isn't reset between requests
- App version config loaded fresh per request (or cached with request-scoped TTL)

### 5.2 `AdminAccessMiddleware`

No breaking changes. Verify role check against `auth:api` guard still works with JWT v2:
```php
// Should still work — verify $request->user() resolves correctly
public function handle($request, Closure $next)
{
    $user = $request->user();
    if (!in_array($user->role, ['admin', 'superadmin'])) {
        return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
    }
    return $next($request);
}
```

### 5.3 CORS — Remove Fruitcake, Use Native

Remove `fruitcake/laravel-cors` from composer.  
Update `config/cors.php` (already exists in L8, L12 uses same structure):

```php
// config/cors.php — verify these settings
return [
    'paths'               => ['api/*', 'admin/*'],
    'allowed_methods'     => ['*'],
    'allowed_origins'     => ['*'],  // tighten for production
    'allowed_headers'     => ['*'],
    'exposed_headers'     => [],
    'max_age'             => 0,
    'supports_credentials'=> false,
];
```

Remove from `bootstrap/app.php` global middleware if you added `HandleCors` — L12 includes it automatically.

### 5.4 Throttle Middleware

L12 uses named limiters defined in `AppServiceProvider`:

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

public function boot(): void
{
    RateLimiter::for('api', function (Request $request) {
        return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
    });

    RateLimiter::for('admin', function (Request $request) {
        return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
    });
}
```

---

## 6. Routing Changes

### 6.1 Route File Structure — No Changes Needed

`routes/api.php`, `routes/admin.php`, `routes/web.php` content stays the same.  
Only the registration mechanism changes (in `bootstrap/app.php` — see Section 4.1).

### 6.2 Current Route Groups Summary

```
/api/v2/*           → routes/api.php  (middleware: api, premiddleware)
/api/v2/auth/*      → routes/api.php  (middleware: premiddleware)
/api/v2/*           → routes/api.php  (middleware: auth:api, premiddleware) [protected]
/admin/api/*        → routes/admin.php (middleware: admin, auth:api)
/admin/v2/auth/*    → routes/admin.php (middleware: api)
/admin/v2/*         → routes/admin.php (middleware: auth:api, premiddleware)
```

### 6.3 `Route::prefix` Behavior

No breaking changes. All `Route::group`, `Route::prefix`, `Route::middleware` work identically in L12.

### 6.4 Remove Deprecated Route Binding

Laravel 12 removes implicit route model binding from string type hints without `#[RouteParam]` or explicit binding. No current routes use implicit model binding — safe.

### 6.5 Fallback / Catch-All Route (web.php)

```php
// routes/web.php — ensure this is still last
Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '.*');
```

No changes needed.

---

## 7. Authentication & JWT Migration

### 7.1 tymon/jwt-auth: ^1.0 → ^2.1

This is the **highest-risk package upgrade** in this migration.

**Breaking changes in JWT v2:**

#### Config (`config/jwt.php`)
```php
// OLD (v1) — key names
'ttl' => env('JWT_TTL', 60),
'refresh_ttl' => env('JWT_REFRESH_TTL', 20160),

// NEW (v2) — same keys, but new options added
// Run: php artisan vendor:publish --provider="Tymon\JWTAuth\Providers\LaravelServiceProvider"
// after upgrading to regenerate config
```

#### Auth Guard Setup
```php
// config/auth.php — verify this exists
'guards' => [
    'api' => [
        'driver'   => 'jwt',
        'provider' => 'users',
    ],
],
```

#### `AuthController` — verify method signatures
JWT v2 changes some method signatures:
```php
// VERIFY these still work after upgrade:
auth()->attempt($credentials)        // OK
auth()->login($user)                 // OK
auth()->user()                       // OK
auth()->logout()                     // OK — but now requires token in header
JWTAuth::parseToken()->authenticate()// May change — test explicitly
```

#### Re-generate JWT Secret
```bash
php artisan jwt:secret
# This rotates the secret — all existing tokens will be invalidated
# Schedule this for a maintenance window
```

### 7.2 `AuthController` Changes

```php
// Current response shape — must remain unchanged for API consumers:
{
    "version": "...",
    "success": true,
    "message": "Login successful",
    "data": {
        "token": "eyJ...",
        "token_type": "bearer",
        "expires_in": 3613651200,
        "user": { ... }
    }
}
// Verify this exact shape is preserved after upgrade
```

### 7.3 Token Blacklisting

`JWT_BLACKLIST_ENABLED=true` is set. JWT v2 requires Redis or database for blacklist:
```bash
# Add to .env
JWT_BLACKLIST_GRACE_PERIOD=30
```

Configure blacklist storage (Redis recommended with Octane):
```php
// config/jwt.php
'blacklist_storage' => 'Tymon\JWTAuth\Blacklist\Storage\IlluminateCacheStorage',
```

---

## 8. Database & Migrations

### 8.1 No Schema Changes Required

All 52 existing migrations are forward-compatible. Do NOT modify them.

### 8.2 `doctrine/dbal` — Still Required

Used for column modification in migrations. Keep at `^3.7`. Laravel 12 still supports it, but note that `change()` method now works without doctrine/dbal for basic types — however keep it for safety.

### 8.3 MySQL Strict Mode

Laravel 12 enables MySQL strict mode by default. Verify `config/database.php`:

```php
'mysql' => [
    'strict' => true,  // was this false in L8? Check!
    'modes'  => [
        'STRICT_TRANS_TABLES',
        'NO_ZERO_IN_DATE',
        'NO_ZERO_DATE',
        'ERROR_FOR_DIVISION_BY_ZERO',
        'NO_ENGINE_SUBSTITUTION',
    ],
],
```

**Action:** Run all existing queries in strict mode locally and fix any that fail (common issue: `GROUP BY` queries without all selected columns in the group).

### 8.4 Timezone

`DB_TIMEZONE=+05:30` is set. Also set in `config/app.php`:
```php
'timezone' => 'Asia/Kolkata',
```
No changes needed.

### 8.5 `password_resets` → `password_reset_tokens` Table

Laravel 9+ renamed this table. If not already migrated:
```bash
php artisan make:migration rename_password_resets_to_password_reset_tokens
```
```php
Schema::rename('password_resets', 'password_reset_tokens');
```

### 8.6 `failed_jobs` Table Update

Laravel 10+ adds a `uuid` column to `failed_jobs`. Add migration if not present:
```bash
php artisan queue:failed-table
php artisan migrate
```

### 8.7 Soft Deletes — No Changes

All soft-deleted models (`User`, `Event`, `Category`, `BonusTypes`, `CategorySite`) use `SoftDeletes` trait. This is fully compatible with L12.

---

## 9. Model Changes

### 9.1 `User` Model

```php
// REQUIRED: Update Authenticatable import
use Illuminate\Foundation\Auth\User as Authenticatable;

// REQUIRED for JWT v2: implement JWTSubject
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    // These two methods must exist:
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }
}
```

### 9.2 `$dates` Property — Deprecated in L10+

Replace `$dates` with `$casts`:
```php
// OLD
protected $dates = ['start_date', 'end_date', 'deleted_at'];

// NEW
protected $casts = [
    'start_date' => 'datetime',
    'end_date'   => 'datetime',
    'deleted_at' => 'datetime',
];
```

**Search all models:**
```bash
grep -rn 'protected \$dates' app/Models/
```

### 9.3 `Banner` Model — `bannerable` Polymorphic

Already added `bannerable()` morphTo — confirm `bannerable_type` stores full class name:
```
App\Models\Place
App\Models\Hotel
App\Models\Site
```
These must match exactly what's stored in DB. In L12 morph map is still optional but recommended:
```php
// AppServiceProvider::boot()
Relation::morphMap([
    'place'  => \App\Models\Place::class,
    'site'   => \App\Models\Site::class,
    'hotel'  => \App\Models\Accomodation::class,
]);
```
If you add this, run a migration to update existing `bannerable_type` values in DB.

### 9.4 `Hashidable` Trait

```php
// app/Traits/Hashidable.php — verify serializeDate still works
protected function serializeDate(\DateTimeInterface $date): string
{
    return $date->format('Y-m-d H:i:s');  // explicit format — OK in L12
}
```

### 9.5 Mass Assignment — `$guarded` vs `$fillable`

Laravel 12 keeps same behavior. No changes needed. All models already have `$fillable`.

---

## 10. Controller Changes

### 10.1 `BaseController` — Fully Compatible

`sendResponse()` and `sendError()` are plain methods — no L12 breaking changes.

**One fix needed:** `sendError` signature has optional `$code` without default:
```php
// Current — will cause error if called without $code arg
public function sendError($error, $errorMessages = [], $code)

// Fix — add default
public function sendError($error, $errorMessages = [], $code = 400): \Illuminate\Http\JsonResponse
```

### 10.2 `updateBanner` — Missing Import (Already Fixed)

`Log` and `Storage` facades already added. No further action.

### 10.3 `AuthController` — Return Types

Add return type hints for PHP 8.2 compatibility:
```php
public function login(Request $request): \Illuminate\Http\JsonResponse
public function register(Request $request): \Illuminate\Http\JsonResponse
public function logout(): \Illuminate\Http\JsonResponse
```

### 10.4 Remove Legacy V1 Controllers

`app/Http/Controllers/API/V1/` — these are legacy. Do not delete yet — wait until confirmed no clients use these. After L12 migration is stable, plan a deprecation notice.

### 10.5 `FormRequest` Classes

None currently exist — all validation is inline. Consider extracting to `FormRequest` classes for L12, but not required for migration. Do not do this during migration to minimize scope.

---

## 11. API Payload & Response Compatibility

All existing API responses flow through `BaseController::sendResponse()`. The response envelope **must not change**:

```json
{
    "version": "1.0.0",
    "language": "en",
    "success": true,
    "message": "...",
    "data": { ... }
}
```

### 11.1 Pagination Response

`listBanners` and other paginated endpoints return Laravel's default paginator shape:
```json
{
    "data": {
        "current_page": 1,
        "data": [...],
        "first_page_url": "...",
        "from": 1,
        "last_page": 5,
        "last_page_url": "...",
        "links": [...],
        "next_page_url": "...",
        "path": "...",
        "per_page": 10,
        "prev_page_url": null,
        "to": 10,
        "total": 50
    }
}
```
Laravel 12 paginator response shape is identical to L8. No breaking change.

### 11.2 Date Format

The `Hashidable` trait explicitly formats dates as `Y-m-d H:i:s`. The `Banner` model casts `start_date`/`end_date` as `date` (not `datetime`). Verify output:
```php
// Banner model
protected $casts = [
    'start_date' => 'date',       // outputs: "2026-04-05"
    'end_date'   => 'date',       // outputs: "2026-04-05"
    'is_active'  => 'boolean',
];
// If clients expect datetime format, change to 'datetime'
```

### 11.3 `null` vs Missing Keys in JSON

PHP 8.2 + L12 serialization behavior is consistent with L8 for `null` fields. No breaking change.

### 11.4 Validation Error Shape

Current validation error response:
```json
{
    "success": false,
    "message": { "field": ["The field is required."] },
    "data": ""
}
```
This shape comes from `sendError($validator->errors(), '', 200)` — unchanged in L12.

---

## 12. Service Providers

### 12.1 Keep Only `AppServiceProvider`

**Delete the following** (merged into `bootstrap/app.php` in L12):
- `app/Providers/RouteServiceProvider.php`
- `app/Providers/EventServiceProvider.php`
- `app/Providers/BroadcastServiceProvider.php`
- `app/Providers/AuthServiceProvider.php`

**Keep:**
- `app/Providers/AppServiceProvider.php`

### 12.2 `AppServiceProvider` After Migration

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Rate limiters (moved from RouteServiceProvider)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Model observers
        \App\Models\Event::observe(\App\Observers\EventObserver::class);

        // Morph map (optional but recommended)
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
            'place'         => \App\Models\Place::class,
            'site'          => \App\Models\Site::class,
            'accomodation'  => \App\Models\Accomodation::class,
        ]);
    }
}
```

---

## 13. Queue & Jobs Migration

### 13.1 Current State

- Queue driver: `sync` (runs inline, no worker needed)
- Jobs: `ProcessRouteImport`, `SendEmailOTP`

### 13.2 Recommended: Migrate to Redis Queue (especially needed for Octane)

With Octane, long-running jobs in the request lifecycle block the worker. Use async queues:

```bash
# Install Redis if not present
composer require predis/predis

# .env
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

```php
// config/queue.php — verify redis connection
'redis' => [
    'driver'      => 'redis',
    'connection'  => 'default',
    'queue'       => env('REDIS_QUEUE', 'default'),
    'retry_after' => 90,
    'block_for'   => null,
],
```

### 13.3 Job Classes — No Breaking Changes

Both `ProcessRouteImport` and `SendEmailOTP` extend `Illuminate\Bus\Queueable`. This is fully compatible with L12.

Add explicit `implements ShouldQueue` if not already present:
```php
use Illuminate\Contracts\Queue\ShouldQueue;

class SendEmailOTP extends Job implements ShouldQueue
```

### 13.4 Run Queue Worker

```bash
# In production (supervisor recommended)
php artisan queue:work redis --queue=default --sleep=3 --tries=3 --max-time=3600
```

---

## 14. Console Commands & Scheduler

### 14.1 Commands — No Breaking Changes

All 7 commands extend `Illuminate\Console\Command`. Fully compatible with L12.

**Update `Console\Kernel.php` signature** or migrate to `routes/console.php`:

```php
// routes/console.php (L12 preferred)
use Illuminate\Support\Facades\Schedule;

Schedule::command('events:send-reminders')->hourly();
Schedule::command('events:mark-completed')->dailyAt('00:30');
```

Or keep `app/Console/Kernel.php` — it still works in L12.

### 14.2 Scheduler with Octane

Octane does not run the scheduler automatically. Use cron:
```bash
# crontab -e
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

---

## 15. Mail & Notifications

### 15.1 `SendOTP` and `WelcomeEmail` Mailables

Both extend `Illuminate\Mail\Mailable`. Fully compatible with L12.

### 15.2 Mail Config

`MAIL_MAILER=smtp` with Gmail SMTP — no changes needed.

L12 adds `MAIL_SCHEME` env var (optional):
```
MAIL_SCHEME=ssl
```

### 15.3 `SendEmailOTP` Job

Currently dispatched synchronously. After queue migration (Section 13), it will be async. Ensure the `SendOTP` mailable is serializable (no closure-based fields).

---

## 16. Helper Functions

### 16.1 `app/helpers.php` — Review Each Function

**`uploadFile()`** — Uses `Storage` facade. No L12 breaking changes.

**`callExternalAPI()`** — Uses Guzzle. Update for Guzzle 7.8 compatibility:
```php
// Ensure exception handling covers GuzzleHttp\Exception\ConnectException
```

**`getData($id, $model)`** — Switch-case pattern. No breaking changes. Consider type-hinting return:
```php
function getData(int $id, string $model): ?\Illuminate\Database\Eloquent\Model
```

**`getLocationDetails()`** — Uses `spatie/geocoder`. Verify API key still in `config/geocoder.php`.

**`sendOTP()`** — Dispatches `SendEmailOTP` job. After queue migration, this becomes async.

### 16.2 Autoloading

`composer.json` already has:
```json
"autoload": {
    "files": ["app/helpers.php"]
}
```
No changes needed.

---

## 17. Configuration Files

### 17.1 Files to Update

| File | Action |
|------|--------|
| `config/app.php` | Update `providers` array — remove deleted SPs |
| `config/cors.php` | Remove fruitcake-specific keys if any |
| `config/jwt.php` | Regenerate after package upgrade |
| `config/sanctum.php` | Update for Sanctum v4 (stateful domains) |
| `config/auth.php` | Verify `api` guard uses `jwt` driver |
| `config/queue.php` | Add redis connection |
| `config/constants.php` | No changes |

### 17.2 `config/app.php` — Remove Deleted Providers

```php
// REMOVE from providers array:
App\Providers\RouteServiceProvider::class,
App\Providers\EventServiceProvider::class,
App\Providers\BroadcastServiceProvider::class,
App\Providers\AuthServiceProvider::class,

// KEEP:
App\Providers\AppServiceProvider::class,
```

### 17.3 New L12 Config Keys

```php
// config/app.php — add if missing
'maintenance' => [
    'driver' => 'file',
    // 'store'  => 'redis',  // use for multi-server
],
```

---

## 18. Environment Variables

### 18.1 Add New Required Variables

```env
# Octane
OCTANE_SERVER=swoole           # or roadrunner
OCTANE_HTTPS=false

# Queue (Redis)
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis

# JWT v2
JWT_BLACKLIST_GRACE_PERIOD=30

# Mail (L12 new)
MAIL_SCHEME=ssl

# App (L12)
APP_MAINTENANCE_DRIVER=file
APP_MAINTENANCE_STORE=database

# Remove (no longer needed)
# MIX_PUSHER_APP_KEY   → renamed to VITE_PUSHER_APP_KEY (if used)
# MIX_PUSHER_APP_CLUSTER → renamed to VITE_PUSHER_APP_CLUSTER
```

### 18.2 Full Updated `.env` Key Reference

```env
APP_NAME=Tourkokan
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://yourdomain.com
APP_MAINTENANCE_DRIVER=file

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tourkokan
DB_USERNAME=...
DB_PASSWORD=...
DB_TIMEZONE=+05:30

BROADCAST_DRIVER=log
CACHE_DRIVER=redis
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=ssl
MAIL_SCHEME=ssl
MAIL_FROM_ADDRESS=...
MAIL_FROM_NAME="${APP_NAME}"

JWT_SECRET=...
JWT_BLACKLIST_ENABLED=true
JWT_BLACKLIST_GRACE_PERIOD=30

OCTANE_SERVER=swoole
OCTANE_HTTPS=false

FIREBASE_PROJECT_ID=...
FIREBASE_SERVER_KEY=...
FIREBASE_FCM_URL=...

MSG91_AUTH_KEY=...
MSG91_ROUTE=...
MSG91_SENDER_ID=...

GOOGLE_MAPS_GEOCODING_API_KEY=...
```

---

## 19. Laravel Octane Setup

### 19.1 Install Octane

```bash
composer require laravel/octane
php artisan octane:install
# Choose: swoole (recommended for performance) or roadrunner
```

### 19.2 Install Swoole Extension (Server)

```bash
# Ubuntu/Debian
pecl install swoole
echo "extension=swoole.so" >> /etc/php/8.2/cli/php.ini

# macOS (dev)
pecl install swoole
```

### 19.3 Configuration (`config/octane.php`)

```php
return [
    'server' => env('OCTANE_SERVER', 'swoole'),

    'https' => env('OCTANE_HTTPS', false),

    'listeners' => [
        // Flush state between requests
        WorkerStarting::class  => [...],
        RequestReceived::class => [
            EnsureUploadedFilesAreValid::class,
            EnsureRequestServerPortMatchesScheme::class,
        ],
        RequestHandled::class  => [],
        RequestTerminated::class => [
            FlushTemporaryContainerInstances::class,
        ],
    ],

    'warm' => [
        // Bind these into the container on worker start (not per request)
        \App\Services\FirebaseService::class,
        \App\Services\CategoryService::class,
    ],

    'flush' => [
        // These are re-bound fresh on each request
    ],

    'garbage'    => 50,   // GC after N requests
    'max_requests' => 500, // Restart worker after N requests (memory leak prevention)

    'swoole' => [
        'options' => [
            'log_file'        => storage_path('logs/swoole_http.log'),
            'package_max_length' => 20 * 1024 * 1024,  // 20MB for file uploads
        ],
    ],
];
```

### 19.4 Critical Octane Compatibility Rules

Octane keeps the application alive between requests. **Any state that persists between requests is a bug.**

#### ❌ Anti-patterns to fix:

**1. Static properties in middleware/services:**
```php
// DANGEROUS — persists across requests
class PreMiddleware {
    protected static $user = null;
}
// FIX: use instance properties or request binding
```

**2. Singleton services with request-scoped data:**
```php
// DANGEROUS
app()->singleton('currentUser', fn() => auth()->user());
// FIX: use scoped() instead
app()->scoped('currentUser', fn() => auth()->user());
```

**3. `config()` mutation at runtime:**
```php
// DANGEROUS — persists to next request
config(['app.locale' => $user->language]);
// FIX: use app()->setLocale() which Octane resets per request
app()->setLocale($user->language);
```

**PreMiddleware likely does this for language** — fix before enabling Octane.

**4. Database connection state:**
Already using per-request connections with MySQL — safe.

**5. File uploads with Octane:**
Default `package_max_length` is 2MB. Set to 20MB (done in config above) since banner/icon uploads exist.

### 19.5 Running Octane

```bash
# Development
php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000

# Production (with supervisor)
php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000 --workers=4 --task-workers=2

# Reload without downtime (after code changes)
php artisan octane:reload
```

### 19.6 Supervisor Config (Production)

```ini
[program:tourkokan-octane]
process_name=%(program_name)s
command=php /var/www/tourkokan/artisan octane:start --server=swoole --host=0.0.0.0 --port=8000 --workers=4
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/tourkokan/storage/logs/octane.log
stopwaitsecs=3600
```

### 19.7 Nginx Config with Octane

```nginx
server {
    listen 80;
    server_name api.tourkokan.com;

    location / {
        proxy_pass         http://127.0.0.1:8000;
        proxy_http_version 1.1;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;
        
        # For file uploads
        client_max_body_size 20M;
    }
}
```

---

## 20. Testing Strategy

### 20.1 Before Migration (Baseline)

- [ ] Export all Postman collections
- [ ] For each endpoint, save a sample successful response
- [ ] Note which endpoints require auth and which are public
- [ ] Run `php artisan route:list` and save output

### 20.2 After Each Migration Step

Run this checklist after every major step:
```bash
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan view:clear
php artisan optimize:clear

# Verify no errors
php artisan route:list
php artisan config:show app
```

### 20.3 Critical Endpoints to Manually Test

| Endpoint | Test Case |
|----------|-----------|
| `POST /admin/v2/auth/login` | Returns JWT token with correct shape |
| `POST /admin/v2/listBanners` | Returns paginated data |
| `POST /admin/v2/addBanner` | Multipart upload works, file saves |
| `POST /admin/v2/updateBanner` | Partial update works, status=0 works |
| `POST /admin/v2/deleteBanner` | Soft/hard delete, 404 on missing id |
| `POST /api/v2/auth/login` | User login returns token |
| `GET /api/v2/advertisingPackages` | Public, no auth needed |
| Any paginated list | Pagination shape intact |
| Any file upload endpoint | Files save to correct storage path |

### 20.4 Octane-Specific Tests

```bash
# Start Octane
php artisan octane:start

# Hit the same endpoint twice — verify no state leakage
curl http://localhost:8000/admin/v2/listBanners  # request 1
curl http://localhost:8000/admin/v2/listBanners  # request 2 — should be identical
```

---

## 21. Deployment Steps

### Step-by-Step Migration Sequence

```
Step 1: Branch & Backup
  git checkout -b feature/laravel-12-migration
  mysqldump ... > backup.sql

Step 2: PHP 8.2 on dev machine
  verify: php -v → 8.2.x

Step 3: Update composer.json
  - All packages to L12 compatible versions
  - Remove fruitcake/laravel-cors, laravel/ui

Step 4: composer update
  composer update --with-all-dependencies

Step 5: Migrate bootstrap/app.php
  - Route registration
  - Middleware registration
  - Exception handling

Step 6: Delete obsolete providers
  RouteServiceProvider, EventServiceProvider, BroadcastServiceProvider, AuthServiceProvider

Step 7: Update AppServiceProvider
  - Rate limiters
  - Observers
  - Morph map

Step 8: Fix model $dates → $casts

Step 9: Fix BaseController sendError default arg

Step 10: Update JWT config
  php artisan vendor:publish --provider="Tymon\JWTAuth\Providers\LaravelServiceProvider" --force

Step 11: Fix PreMiddleware for Octane safety

Step 12: Update config files
  - Remove old providers from app.php
  - Update cors.php
  - Add redis queue

Step 13: Update .env

Step 14: Run migrations
  php artisan migrate

Step 15: Clear all caches
  php artisan optimize:clear

Step 16: Verify routes
  php artisan route:list

Step 17: Install & configure Octane
  composer require laravel/octane
  php artisan octane:install

Step 18: Test all critical endpoints

Step 19: Deploy to staging → full regression test

Step 20: Production deploy (maintenance window)
  php artisan down
  git pull
  composer install --no-dev --optimize-autoloader
  php artisan migrate --force
  php artisan optimize
  php artisan octane:start (via supervisor)
  php artisan up
```

---

## 22. Rollback Plan

If anything goes wrong after production deployment:

```bash
# 1. Put site in maintenance
php artisan down

# 2. Revert code
git checkout main  # or previous tag

# 3. Restore DB if schema changed
mysql -u root -p tourkokan < backup_YYYYMMDD.sql

# 4. Restore composer
composer install --no-dev --optimize-autoloader

# 5. Clear caches
php artisan optimize:clear

# 6. Restart PHP-FPM (non-Octane)
sudo systemctl restart php8.2-fpm
# OR stop Octane and use php-fpm
php artisan octane:stop

# 7. Bring back up
php artisan up
```

---

## Summary: Risk Matrix

| Area | Risk | Effort | Priority |
|------|------|--------|----------|
| `bootstrap/app.php` restructure | High | Medium | P0 |
| JWT v2 upgrade + token invalidation | High | Medium | P0 |
| `PreMiddleware` Octane safety | High | Low | P0 |
| `fruitcake/cors` removal | Medium | Low | P1 |
| PHP 8.2 dynamic properties | Medium | Medium | P1 |
| Queue → Redis migration | Medium | Low | P1 |
| `$dates` → `$casts` | Low | Low | P2 |
| `BaseController` default arg | Low | Trivial | P2 |
| Legacy V1 controller cleanup | Low | High | P3 (post-migration) |
| `saiashirwadinformatia/secure-ids` compat | Unknown | Low | P2 |

---

*Generated: 2026-04-06 | Branch: `feature/laravel-12-migration`*
