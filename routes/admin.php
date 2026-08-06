<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Admin\PlaceController;
use App\Http\Controllers\Admin\CityController;
use App\Http\Controllers\Admin\PlaceCategoryController;
use App\Http\Controllers\Admin\FoodController;
use App\Http\Controllers\Admin\V2\BannerController;
use App\Http\Controllers\Admin\V2\BusTypeController;
use App\Http\Controllers\Admin\DropDownController;
use App\Http\Controllers\Admin\V2\AppVersionController;
use App\Http\Controllers\Admin\V2\BonusTypesController;
use App\Http\Controllers\Admin\V2\CategoryController;
use App\Http\Controllers\Admin\V2\ContactController;
use App\Http\Controllers\Admin\V2\GalleryController;
use App\Http\Controllers\Admin\V2\RolesController;
use App\Http\Controllers\Admin\V2\RouteController;
use App\Http\Controllers\Admin\V2\SiteController;
use App\Http\Controllers\Admin\V2\AdminBannerController;
use App\Http\Controllers\Admin\V2\EventController as AdminEventController;
use App\Http\Controllers\Admin\V2\CommentController as AdminCommentController;
use App\Http\Controllers\Admin\V2\MessageController as AdminMessageController;
use App\Http\Controllers\Admin\V2\EventTypeController as AdminEventTypeController;
use App\Http\Controllers\Admin\V2\EventGalleryController as AdminEventGalleryController;
use App\Http\Controllers\Admin\V2\SiteGalleryController as AdminSiteGalleryController;
use App\Http\Controllers\Admin\V2\UserRoleRequestController as AdminUserRoleRequestController;
use App\Http\Controllers\Admin\V2\AnalyticsController;
use App\Http\Controllers\Admin\V2\ProductCategoryController as AdminProductCategoryController;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
|
| Here is where you can register admin routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "admin" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    print('I am an admin');
});

Route::group(['middleware' => ['api', 'premiddleware', 'throttle:auth']], function ($router) {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
});

Route::group(['middleware' => ['admin', 'auth:api', 'premiddleware'], 'prefix' => 'api'], function ($router) {

    Route::get('/user-profile', [AuthController::class, 'userProfile']);
    Route::post('/users', [AuthController::class, 'index']);

    Route::get('/cities', [CityController::class, 'index']);
    Route::post('/city', [CityController::class, 'store']);
    Route::get('/city/{id}', [CityController::class, 'show']);
    Route::post('/city/{id}', [CityController::class, 'update']);
    Route::delete('/city/{id}', [CityController::class, 'destroy']);
    Route::get('/city/{id}/detail', [CityController::class, 'getAllcities']);

    Route::get('/placecategories', [PlaceCategoryController::class, 'index']);
    Route::post('/placecategory', [PlaceCategoryController::class, 'store']);
    Route::get('/placecategory/{id}', [PlaceCategoryController::class, 'show']);
    Route::get('/placecategory/places/{place_categories_id}', [PlaceCategoryController::class, 'getAllPlaces']);
    Route::post('/placecategory/{id}', [PlaceCategoryController::class, 'update']);
    Route::delete('/placecategory/{id}', [PlaceCategoryController::class, 'destroy']);

    Route::get('/places', [PlaceController::class, 'index']);
    Route::post('/place', [PlaceController::class, 'store']);
    Route::get('/place/{id}', [PlaceController::class, 'show']);
    Route::post('/place/{id}', [PlaceController::class, 'update']);
    Route::delete('/place/{id}', [PlaceController::class, 'destroy']);

    // Product catalog admin routes are rebuilt under /admin/v2 in Phase 3–4.
    // See docs/VENDOR_PRODUCTS_DESIGN.md §7.

    Route::get('/foods', [FoodController::class, 'index']);
    Route::post('/food', [FoodController::class, 'store']);
    Route::get('/food/{id}', [FoodController::class, 'show']);
    Route::post('/food/{id}', [FoodController::class, 'update']);
    Route::delete('/food/{id}', [FoodController::class, 'destroy']);

    Route::get('/bustypes', [BusTypeController::class, 'index']);
    Route::post('/bustype', [BusTypeController::class, 'store']);
    Route::get('/bustype/{id}', [BusTypeController::class, 'show']);
    Route::post('/bustype/{id}', [BusTypeController::class, 'update']);
    Route::delete('/bustype/{id}', [BusTypeController::class, 'destroy']);



    
});


Route::group(['middleware' => ['api', 'premiddleware', 'throttle:auth'], 'prefix' => 'v2/auth'], function ($router) {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('refresh', [AuthController::class, 'refresh']);

    Route::middleware('throttle:otp')->group(function () {
        Route::post('sendOtp', [AuthController::class, 'sendOtp']);
        Route::post('verifyOtp', [AuthController::class, 'verifyOtp']);
    });
});

// Every endpoint here requires the admin/superadmin role.
//
// The `admin` alias was missing from this group until 2026-08-05, which left the whole
// admin API — approveSite, approveRoleRequest, deleteEvent, allUsers, analytics — open to
// any authenticated user. Do not remove it. Regression coverage lives in
// tests/Feature/AdminAccessTest.php.
Route::group(['middleware' => ['auth:api', 'premiddleware', 'admin', 'throttle:admin'], 'prefix' => 'v2'], function ($router) {
    Route::post('listcategories', [CategoryController::class, 'listcategories']);
    Route::post('getCategory', [CategoryController::class, 'getCategory']);
    Route::post('addCategory', [CategoryController::class, 'addCategory']);
    Route::post('updateCategory', [CategoryController::class, 'updateCategory']);
    Route::post('deleteCategory', [CategoryController::class, 'deleteCategory']);
    
    Route::post('sites', [SiteController::class, 'sites']);
    Route::post('getSite', [SiteController::class, 'getSite']);

    Route::post('addSite', [SiteController::class, 'addSite']);
    Route::post('updateSite', [SiteController::class, 'updateSite']);
    Route::post('deleteSite', [SiteController::class, 'deleteSite']);

    Route::post('eventTypeDD', [AdminEventTypeController::class, 'index']);
    Route::post('bannerDaysDD', [DropDownController::class, 'bannerDaysDD']);
    Route::post('bannerImageOrientationDD', [DropDownController::class, 'bannerImageOrientationDD']);
    Route::post('bannerLevelsDD', [DropDownController::class, 'bannerLevelsDD']);

    
    Route::post('addBanner', [BannerController::class, 'addBanner']);
    Route::post('listBanners', [BannerController::class, 'listBanners']);
    Route::post('getBanner', [BannerController::class, 'getBanner']);
    Route::post('updateBanner', [BannerController::class, 'updateBanner']);
    Route::post('deleteBanner', [BannerController::class, 'deleteBanner']);

    Route::post('addBonusType', [BonusTypesController::class, 'addBonusType']);
    Route::post('listBonusTypes', [BonusTypesController::class, 'listBonusTypes']);
    Route::post('getBonusType', [BonusTypesController::class, 'getBonusType']);
    Route::post('deleteBonusType', [BonusTypesController::class, 'deleteBonusType']);
    Route::post('updateBonusType', [BonusTypesController::class, 'updateBonusType']);

    Route::post('routes', [RouteController::class, 'routes']);
    Route::post('routeDetails', [RouteController::class, 'routeDetails']);

    Route::post('getQueries', [ContactController::class, 'getQueries']);
    Route::post('getQuery', [ContactController::class, 'getQuery']);
    Route::post('updateQuery', [ContactController::class, 'updateQuery']);
    Route::post('replyQuery', [ContactController::class, 'replyQuery']);

    Route::post('allUsers', [AuthController::class, 'allUsers']);
    Route::post('listUsers', [AuthController::class, 'listUsers']);

    Route::post('roleDD', [RolesController::class, 'roleDD']);

    Route::post('listAppVersions', [AppVersionController::class, 'listAppVersions']);
    Route::post('getAppVersion', [AppVersionController::class, 'getAppVersion']);
    Route::post('addAppVersion', [AppVersionController::class, 'addAppVersion']);
    Route::post('updateAppVersion', [AppVersionController::class, 'updateAppVersion']);
    Route::post('deleteAppVersion', [AppVersionController::class, 'deleteAppVersion']);

    Route::post('getGallery', [GalleryController::class, 'getGallery']);
    Route::post('updateGallery', [GalleryController::class, 'updateGallery']);

    // Banner Form Dropdown (packages with full placement objects)
    Route::post('bannerFormDD', [AdminBannerController::class, 'bannerFormDD']);

    // Banner Packages (Pricing)
    Route::post('listBannerPackages', [AdminBannerController::class, 'listPackages']);
    Route::post('getBannerPackage', [AdminBannerController::class, 'getPackage']);
    Route::post('addBannerPackage', [AdminBannerController::class, 'storePackage']);
    Route::post('updateBannerPackage', [AdminBannerController::class, 'updatePackage']);
    Route::post('deleteBannerPackage', [AdminBannerController::class, 'deletePackage']);

    // Banner Placements
    Route::post('listBannerPlacements', [AdminBannerController::class, 'listPlacements']);
    Route::post('getBannerPlacement', [AdminBannerController::class, 'getPlacement']);
    Route::post('addBannerPlacement', [AdminBannerController::class, 'storePlacement']);
    Route::post('updateBannerPlacement', [AdminBannerController::class, 'updatePlacement']);
    Route::post('deleteBannerPlacement', [AdminBannerController::class, 'deletePlacement']);

    // Banner Status
    Route::post('changeBannerStatus', [AdminBannerController::class, 'changeStatus']);

    // ── Events ────────────────────────────────────────────────────────────────
    Route::post('listEvents', [AdminEventController::class, 'index']);
    Route::post('getEvent', [AdminEventController::class, 'show']);
    Route::post('createEvent', [AdminEventController::class, 'store']);
    Route::post('updateEvent', [AdminEventController::class, 'update']);
    Route::post('deleteEvent', [AdminEventController::class, 'destroy']);
    Route::post('pendingEvents', [AdminEventController::class, 'pending']);
    Route::post('approveEvent', [AdminEventController::class, 'approve']);
    Route::post('rejectEvent', [AdminEventController::class, 'reject']);
    Route::post('featureEvent', [AdminEventController::class, 'feature']);
    Route::post('eventAnalytics', [AdminEventController::class, 'analytics']);

    // ── Event Gallery ─────────────────────────────────────────────────────────
    Route::post('getEventGallery', [AdminEventGalleryController::class, 'index']);
    Route::post('uploadEventGallery', [AdminEventGalleryController::class, 'upload'])->middleware('throttle:uploads');
    Route::post('deleteEventGallery', [AdminEventGalleryController::class, 'destroy']);

    // ── Comment Moderation ────────────────────────────────────────────────────
    Route::post('pendingComments', [AdminCommentController::class, 'pending']);
    Route::post('listComments', [AdminCommentController::class, 'index']);
    Route::post('approveComment', [AdminCommentController::class, 'approve']);
    Route::post('rejectComment', [AdminCommentController::class, 'reject']);

    // ── Site Gallery ──────────────────────────────────────────────────────────
    Route::post('getSiteGallery', [AdminSiteGalleryController::class, 'index']);
    Route::post('uploadSiteGallery', [AdminSiteGalleryController::class, 'upload'])->middleware('throttle:uploads');
    Route::post('deleteSiteGallery', [AdminSiteGalleryController::class, 'destroy']);

    // ── Site Submission Review ────────────────────────────────────────────────
    Route::post('pendingSites', [SiteController::class, 'pendingSubmissions']);
    Route::post('allSubmissions', [SiteController::class, 'allSubmissions']);
    Route::post('approveSite', [SiteController::class, 'approveSite']);
    Route::post('rejectSite', [SiteController::class, 'rejectSite']);

    // ── User Role Requests ────────────────────────────────────────────────────
    Route::post('userRoleRequests', [AdminUserRoleRequestController::class, 'index']);
    Route::post('approveRoleRequest', [AdminUserRoleRequestController::class, 'approve']);
    Route::post('rejectRoleRequest', [AdminUserRoleRequestController::class, 'reject']);

    // ── Direct Messaging ──────────────────────────────────────────────────────
    Route::post('sendMessage', [AdminMessageController::class, 'send']);
    Route::post('listMessages', [AdminMessageController::class, 'index']);
    Route::post('deleteMessage', [AdminMessageController::class, 'destroy']);

    // ── Product Taxonomy ──────────────────────────────────────────────────────
    // A product category carries the attribute_schema that drives the vendor's
    // Add-Product form in the app, so creating one here ships a new vertical with
    // no migration and no app release. See docs/VENDOR_PRODUCTS_DESIGN.md §6.
    Route::post('listProductCategories',       [AdminProductCategoryController::class, 'listProductCategories']);
    Route::post('getProductCategory',          [AdminProductCategoryController::class, 'getProductCategory']);
    Route::post('addProductCategory',          [AdminProductCategoryController::class, 'addProductCategory']);
    Route::post('updateProductCategory',       [AdminProductCategoryController::class, 'updateProductCategory']);
    Route::post('deleteProductCategory',       [AdminProductCategoryController::class, 'deleteProductCategory']);
    Route::post('setAllowedProductCategories', [AdminProductCategoryController::class, 'setAllowedProductCategories']);

    // ── Analytics ─────────────────────────────────────────────────────────────
    Route::post('analytics/activityLogs',      [AnalyticsController::class, 'activityLogs']);
    Route::post('analytics/userTimeline',      [AnalyticsController::class, 'userTimeline']);
    Route::post('analytics/topSites',          [AnalyticsController::class, 'topSites']);
    Route::post('analytics/topEvents',         [AnalyticsController::class, 'topEvents']);
    Route::post('analytics/topRoutes',         [AnalyticsController::class, 'topRoutes']);
    Route::post('analytics/userInterests',     [AnalyticsController::class, 'userInterests']);
    Route::post('analytics/loginHistory',      [AnalyticsController::class, 'loginHistory']);
    Route::post('analytics/activeUsers',       [AnalyticsController::class, 'activeUsers']);
    Route::post('analytics/dashboardStats',    [AnalyticsController::class, 'dashboardStats']);
    Route::post('analytics/eventTypeSummary',  [AnalyticsController::class, 'eventTypeSummary']);
    Route::post('analytics/platformBreakdown', [AnalyticsController::class, 'platformBreakdown']);
    Route::post('analytics/favouriteActivity', [AnalyticsController::class, 'favouriteActivity']);
});

// Admin-only route management (requires admin role)
Route::group(['middleware' => ['auth:api', 'premiddleware', 'admin', 'throttle:admin'], 'prefix' => 'v2'], function () {
    Route::post('addRoute', [RouteController::class, 'addRoute']);
    Route::post('routesUpdate', [RouteController::class, 'routesUpdate']);
    Route::post('deleteRoute', [RouteController::class, 'deleteRoute']);
    Route::post('massRouteStopsUpdate', [RouteController::class, 'massRouteStopsUpdate']);

});
