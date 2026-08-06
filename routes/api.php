<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

// use App\Http\Controllers\API\V1\{
//     AppVersionController,
//     ContactController,
//     RatingController,
//     RouteController,
//     // CategoryController,
//     ProjectsController,
//     ProductController,
//     RolesController,
//     PhotosController,
//     // LandingPageController,
//     PlaceController,
//     BlogController,
//     // HomeController,
//     CityController,
//     CommentController,
//     FavouriteController,
//     AddressController,
//     PlaceCategoryController,
//     FoodController,
//     SiteController
// };

use App\Http\Controllers\User\V2\{
    AppVersionController,
    SiteController,
    LandingPageController,
    CategoryController,
    CommentController,
    ContactController,
    FavouriteController,
    GalleryController,
    RatingController,
    RolesController,
    RouteController,
    EventController,
    EventTypeController,
    EventGalleryController,
    SiteGalleryController,
    EventInteractionController,
    NotificationController,
    HealthCheckController,
    MessageController,
    UserRoleRequestController,
    RouteStopsController,
    ProductCategoryController,
    ProductController,
    CatalogController,
};

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });

// Route::group(['middleware' => 'api', 'prefix' => 'auth'], function ($router) {
//     Route::post('/login', [AuthController::class, 'login']);
//     Route::post('/register', [AuthController::class, 'register']);
//     Route::post('/refresh', [AuthController::class, 'refresh']);
//     Route::post('/users', [AuthController::class, 'index']);
//     Route::post('/sendOtp', [AuthController::class, 'sendOtp']);
//     Route::post('/verifyOtp', [AuthController::class, 'verifyOtp']);
// });

// Route::group(['middleware' => 'api', 'prefix' => 'v1'], function ($router) {
//     Route::get('roleDD', [RolesController::class, 'roleDD']);
// });

// Route::group(['middleware' => 'auth:api', 'prefix' => 'v1'], function ($router) {

//     Route::post('/addAppVersion', [AppVersionController::class, 'addAppVersion']);
//     Route::get('/getAppVersion', [AppVersionController::class, 'getAppVersion']);

//     Route::get('/user-profile', [AuthController::class, 'userProfile']);
//     Route::post('/updateProfile', [AuthController::class, 'updateProfile']);
//     Route::post('/logout', [AuthController::class, 'logout']);

//     Route::get('/landingpage', [LandingPageController::class, 'index']);
//     Route::post('/search', [HomeController::class, 'search']);

//     Route::get('/cities', [CityController::class, 'index']);
//     Route::get('/city/{id}', [CityController::class, 'show']);
//     Route::get('/city/{id}/detail', [CityController::class, 'getAllcities']);

//     Route::get('/placecategories', [PlaceCategoryController::class, 'index']);

//     Route::get('/places', [PlaceController::class, 'index']);
//     Route::get('/place/{id}', [PlaceController::class, 'show']);

//     // Route::get('/stops', [PlaceController::class, 'stops']);
//     // Route::post('/searchPlace', [PlaceController::class, 'searchPlace']);

//     Route::get('/listroutes', [RouteController::class, 'listroutes']);
//     Route::post('/routes', [RouteController::class, 'routes']);

//     Route::get('/contacts', [ContactController::class, 'index']);
//     Route::post('/contact', [ContactController::class, 'store']);
//     Route::get('/contact/{id}', [ContactController::class, 'show']);
//     Route::put('/contact/{id}', [ContactController::class, 'update']);
//     Route::delete('/contact/{id}', [ContactController::class, 'destroy']);

//     Route::post('/address', [AddressController::class, 'store']);
//     Route::put('/address/{id}', [AddressController::class, 'update']);
//     Route::delete('/address/{id}', [AddressController::class, 'destroy']);

//     Route::get('/blogs', [BlogController::class, 'index']);
//     Route::post('/blog', [BlogController::class, 'store']);
//     Route::get('/blog/{id}', [BlogController::class, 'show']);
//     Route::get('/blog/category/{id}', [BlogController::class, 'blogByCategory']);
//     Route::put('/blog/{id}', [BlogController::class, 'update']);
//     Route::delete('/blog/{id}', [BlogController::class, 'destroy']);

//     Route::get('/categories', [CategoryController::class, 'index']);
//     Route::post('/category', [CategoryController::class, 'store']);
//     Route::get('/category/{id}', [CategoryController::class, 'show']);
//     Route::get('/category/{categories_id}/projects', [CategoryController::class, 'getAllProjects']);
//     Route::get('/category/{id}/productcategories', [CategoryController::class, 'getAllowedProductCategories']);
//     Route::post('/category/{id}', [CategoryController::class, 'update']);
//     Route::delete('/category/{id}', [CategoryController::class, 'destroy']);

//     Route::post('/projects', [ProjectsController::class, 'index']);
//     Route::post('/project', [ProjectsController::class, 'store']);
//     Route::get('/project/{id}', [ProjectsController::class, 'show']);
//     Route::post('/project/{id}', [ProjectsController::class, 'update']);
//     Route::delete('/project/{id}', [ProjectsController::class, 'destroy']);
//     // Route::get('/project/{id}/products', [ProjectsController::class, 'getAllProducts']); 

//     // Route::get('/products', [ProductsController::class, 'index']);   
//     Route::post('/project/{project_id}/products', [ProductController::class, 'getAllProductsByProjectId']);
//     // Route::post('/product', [ProductsController::class, 'store']);
//     // Route::post('/product/{id}', [ProductsController::class, 'update']);  
//     // Route::delete('/product/{id}', [ProductsController::class, 'destroy']);   


//     Route::get('/food/{id}', [FoodController::class, 'show']);


//     Route::get('/photos', [PhotosController::class, 'index']);
//     Route::post('/photo', [PhotosController::class, 'store']);
//     Route::get('/photo/{id}', [PhotosController::class, 'show']);
//     Route::post('/photo/{id}', [PhotosController::class, 'update']);
//     Route::delete('/photo/{id}', [PhotosController::class, 'destroy']);

//     Route::get('/roles', [RolesController::class, 'index']);
//     Route::post('/role', [RolesController::class, 'store']);
//     Route::get('/role/{id}', [RolesController::class, 'show']);
//     Route::put('/role/{id}', [RolesController::class, 'update']);
//     Route::delete('/role/{id}', [RolesController::class, 'destroy']);
//     Route::get('/role/{id}/users', [RolesController::class, 'getAllUsers']);

//     Route::get('/comments', [CommentController::class, 'index']);
//     Route::post('/comment', [CommentController::class, 'store']);
//     Route::get('/comment/{id}', [CommentController::class, 'show']);
//     Route::put('/comment/{id}', [CommentController::class, 'update']);
//     Route::delete('/comment/{id}', [CommentController::class, 'destroy']);

//     Route::get('/favourites', [FavouriteController::class, 'index']);
//     Route::post('/favourite', [FavouriteController::class, 'store']);
//     Route::get('/favourite/{user_id}', [AuthController::class, 'getAllFavourites']);
//     // Route::put('/favourite/{id}', [FavouriteController::class, 'update']);   
//     Route::delete('/favourite/{id}', [FavouriteController::class, 'destroy']);

//     Route::get('ratings', [RatingController::class, 'index']);
//     Route::post('rating', [RatingController::class, 'store']);
//     Route::put('rating/{id}', [RatingController::class, 'update']);
//     Route::delete('rating/{id}', [RatingController::class, 'destroy']);

//     // New Structure API
//     Route::post('listCities', [SiteController::class, 'listCities']);
//     Route::post('getCity/{id}', [SiteController::class, 'getCity']);
//     Route::post('stops', [SiteController::class, 'stops']);
//     Route::post('searchPlace', [SiteController::class, 'searchPlace']);
// });

// New Structure API


Route::group(['middleware' => 'premiddleware', 'prefix' => 'v2'], function ($router) {
    Route::get('roleDD', [RolesController::class, 'roleDD']);
    Route::post('addGuestQuery', [ContactController::class, 'addQuery']);
    Route::post('deleteMyAccount', [AuthController::class, 'deleteMyAccount']);
    Route::post('eventTypeDD', [EventTypeController::class, 'index']);
    Route::get('advertisingPackages', function () {
        $packages = \App\Models\BannerPackage::where('is_active', true)->orderBy('price')->get();
        return response()->json(['success' => true, 'data' => $packages]);
    });
});

Route::group(['middleware' => ['premiddleware', 'throttle:auth'], 'prefix' => 'v2/auth'], function ($router) {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::post('updateEmail', [AuthController::class, 'updateEmail']);
    Route::post('isVerifiedEmail', [AuthController::class, 'isVerifiedEmail']);
    Route::post('deleteMyAccount', [AuthController::class, 'deleteMyAccount']); // As per need make changes not tested
    Route::post('googleAuth', [AuthController::class, 'googleAuth']);

    // OTP: stricter limit — 3/min per IP to prevent SMS abuse
    Route::middleware('throttle:otp')->group(function () {
        Route::post('sendOtp', [AuthController::class, 'sendOtp']);
        Route::post('verifyOtp', [AuthController::class, 'verifyOtp']);
    });
});

Route::group(['middleware' => ['auth:api', 'premiddleware'], 'prefix' => 'v2'], function ($router) {
    Route::post('sites', [SiteController::class, 'sites']);
    Route::post('getSite', [SiteController::class, 'getSite']);

    Route::post('landingpage', [LandingPageController::class, 'index']);

    Route::post('user-profile', [AuthController::class, 'userProfile']);
    Route::post('updateProfile', [AuthController::class, 'updateProfile']);
    Route::post('logout', [AuthController::class, 'logout']);

    Route::post('listroutes', [RouteController::class, 'listroutes']);
    Route::post('routes', [RouteController::class, 'routes']);
    Route::post('getRouteStops', [RouteStopsController::class, 'getRouteStops']);
    Route::post('downloadRoute', [RouteController::class, 'downloadRoute']);

    Route::post('favourites', [FavouriteController::class, 'index']);
    Route::post('addDeleteFavourite', [FavouriteController::class, 'addDeleteFavourite']);

    Route::post('getQueries', [ContactController::class, 'getQueries']);
    Route::post('addQuery', [ContactController::class, 'addQuery']);
    Route::post('getQuery', [ContactController::class, 'getQuery']);
    Route::post('updateQuery', [ContactController::class, 'updateQuery']);
    Route::post('deleteQuery', [ContactController::class, 'deleteQuery']);

    Route::post('listcategories', [CategoryController::class, 'listcategories']);
    Route::post('getCategory', [CategoryController::class, 'getCategory']);

    Route::post('ratings', [RatingController::class, 'index']);
    Route::middleware('throttle:writes')->group(function () {
        Route::post('addUpdateRating', [RatingController::class, 'addUpdateRating']);
        Route::delete('rating/{id}', [RatingController::class, 'destroy']);
    });

    Route::post('comments', [CommentController::class, 'index']);
    Route::post('getComment', [CommentController::class, 'getComment']);
    Route::middleware('throttle:writes')->group(function () {
        Route::post('comment', [CommentController::class, 'store']);
        Route::post('updateComment', [CommentController::class, 'updateComment']);
        Route::post('deleteComment', [CommentController::class, 'deleteComment']);
    });

    Route::post('getGallery', [GalleryController::class, 'getGallery']);

    // ── Events (User) ──────────────────────────────────────────────
    Route::post('listEvents', [EventController::class, 'index']);
    Route::post('myEvents', [EventController::class, 'myEvents']);
    Route::get('events/{slug}', [EventController::class, 'show']);   // GET kept for SEO

    Route::middleware(['vendor', 'throttle:writes'])->group(function () {
        Route::post('createEvent', [EventController::class, 'store']);
        Route::post('updateEvent', [EventController::class, 'update']);
        Route::post('deleteEvent', [EventController::class, 'destroy']);
        Route::post('cancelEvent', [EventController::class, 'cancel']);
    });

    // Event Gallery (completed events only)
    Route::post('getEventGallery', [EventGalleryController::class, 'index']);
    Route::post('uploadEventGallery', [EventGalleryController::class, 'upload'])->middleware('throttle:uploads');
    Route::post('deleteEventGallery', [EventGalleryController::class, 'destroy'])->middleware('throttle:writes');

    // Event Interactions
    Route::post('likeEvent', [EventInteractionController::class, 'like']);
    Route::post('goingEvent', [EventInteractionController::class, 'going']);
    Route::post('interestedEvent', [EventInteractionController::class, 'interested']);
    Route::post('favouriteEvent', [EventInteractionController::class, 'favourite']);
    Route::post('shareEvent', [EventInteractionController::class, 'share']);

    // ── Push Notifications ─────────────────────────────────────────
    Route::post('registerPushToken', [NotificationController::class, 'registerToken']);
    Route::post('unregisterPushToken', [NotificationController::class, 'unregisterToken']);
    Route::post('getDevices', [NotificationController::class, 'getDevices']);
    Route::post('testNotification', [NotificationController::class, 'testNotification']);

    // ── Health Check (auth required — not in admin panel) ──────────
    Route::get('healthcheck', [HealthCheckController::class, 'check']);

    // ── User Inbox (admin → user messages) ─────────────────────────
    Route::post('myMessages', [MessageController::class, 'inbox']);
    Route::post('readMessage', [MessageController::class, 'markRead']);
    Route::post('unreadMessageCount', [MessageController::class, 'unreadCount']);

    // ── Site Gallery (approved sites only) ────────────────────────────
    Route::post('getSiteGallery', [SiteGalleryController::class, 'index']);
    Route::post('uploadSiteGallery', [SiteGalleryController::class, 'upload'])->middleware('throttle:uploads');
    Route::post('deleteSiteGallery', [SiteGalleryController::class, 'destroy'])->middleware('throttle:writes');

    // ── Public Catalog (what tourists browse) ───────────────────────
    // Everything reads through Product::scopeLive — approved listing, approved and
    // published site, inside its availability window.
    Route::post('listProducts', [CatalogController::class, 'listProducts']);
    Route::post('productDetail', [CatalogController::class, 'productDetail']);
    Route::post('productsBySite', [CatalogController::class, 'productsBySite']);
    Route::post('featuredProducts', [CatalogController::class, 'featuredProducts']);

    // Engagement capture — starts on day one of the free period so there is data to
    // price on later. See docs/VENDOR_PRODUCTS_DESIGN.md §9.
    Route::post('recordProductView', [CatalogController::class, 'recordProductView'])
        ->middleware('throttle:writes');
    Route::post('recordProductLead', [CatalogController::class, 'recordProductLead'])
        ->middleware('throttle:writes');

    // ── Site Onboarding (user-submitted places) ─────────────────────
    Route::post('parseMapUrl', [SiteController::class, 'parseMapUrl']);
    Route::post('mySubmissions', [SiteController::class, 'mySubmissions']);

    Route::middleware(['vendor', 'throttle:writes'])->group(function () {
        Route::post('addSite', [SiteController::class, 'submitSite']);
        Route::post('updateMySubmission', [SiteController::class, 'updateSubmission']);
        Route::post('deleteMySubmission', [SiteController::class, 'deleteSubmission']);

        // ── Vendor business outlets ─────────────────────────────────
        Route::post('mySites', [SiteController::class, 'mySites']);
        Route::post('setPrimarySite', [SiteController::class, 'setPrimarySite']);

        // ── Product taxonomy lookups (drive the app's Add-Product form) ──
        Route::post('allowedProductCategories', [ProductCategoryController::class, 'allowedProductCategories']);
        Route::post('categoryAttributeSchema', [ProductCategoryController::class, 'categoryAttributeSchema']);

        // ── Vendor catalog ──────────────────────────────────────────────
        Route::post('myProducts', [ProductController::class, 'myProducts']);
        Route::post('getProduct', [ProductController::class, 'getProduct']);
        Route::post('addProduct', [ProductController::class, 'addProduct']);
        Route::post('updateProduct', [ProductController::class, 'updateProduct']);
        Route::post('deleteProduct', [ProductController::class, 'deleteProduct']);
        Route::post('submitProductForReview', [ProductController::class, 'submitProductForReview']);
        Route::post('toggleProductStatus', [ProductController::class, 'toggleProductStatus']);

        Route::post('saveProductVariant', [ProductController::class, 'saveProductVariant']);
        Route::post('deleteProductVariant', [ProductController::class, 'deleteProductVariant']);

        Route::post('deleteProductMedia', [ProductController::class, 'deleteProductMedia']);
        Route::post('setProductCover', [ProductController::class, 'setProductCover']);
        Route::post('reorderProductMedia', [ProductController::class, 'reorderProductMedia']);
    });

    // Image uploads carry their own throttle, matching the site/event gallery routes.
    Route::middleware(['vendor', 'throttle:uploads'])->group(function () {
        Route::post('uploadProductMedia', [ProductController::class, 'uploadProductMedia']);
    });

    // ── Role Requests ───────────────────────────────────────────────
    Route::post('requestRole', [UserRoleRequestController::class, 'store']);
    Route::get('myRoleRequests', [UserRoleRequestController::class, 'index']);
});

use Illuminate\Support\Facades\Mail;

Route::get('/send-test-email', function () {
    $details = [
        'subject' => 'Test Email from Laravel',
        'body' => 'This is a test email sent from Laravel using webmail configuration.'
    ];

    Mail::raw($details['body'], function($message) use ($details) {
        $message->to('kamblepranav747@gmail.com')
                ->subject($details['subject']);
    });

    return 'Test email sent successfully!';
});
