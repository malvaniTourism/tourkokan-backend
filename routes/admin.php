<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Admin\PlaceController;
use App\Http\Controllers\Admin\CityController;
use App\Http\Controllers\Admin\PlaceCategoryController;
use App\Http\Controllers\Admin\ProductCategoryController;
use App\Http\Controllers\Admin\AllowedProductCategoryController;
use App\Http\Controllers\Admin\FoodController;
use App\Http\Controllers\Admin\TourPackageController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\AccomodationCategoryController;
use App\Http\Controllers\Admin\V2\BannerController;
use App\Http\Controllers\Admin\BusTypeController;
use App\Http\Controllers\Admin\DropDownController;
use App\Http\Controllers\Admin\V2\BonusTypesController;
use App\Http\Controllers\Admin\V2\CategoryController;
use App\Http\Controllers\Admin\V2\SiteController;

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

Route::group(['middleware' => 'api'], function ($router) {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
});

Route::group(['middleware' => ['admin', 'auth:api'], 'prefix' => 'api'], function ($router) {

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

    Route::get('/productcategories', [ProductCategoryController::class, 'index']);
    Route::post('/productcategory', [ProductCategoryController::class, 'store']);
    Route::get('/productcategory/{id}', [ProductCategoryController::class, 'show']);
    Route::post('/productcategory/{id}', [ProductCategoryController::class, 'update']);
    Route::delete('/productcategory/{id}', [ProductCategoryController::class, 'destroy']);

    Route::post('/allowproductcategory', [AllowedProductCategoryController::class, 'store']);
    Route::post('/allowproductcategory/{id}', [AllowedProductCategoryController::class, 'update']);
    Route::delete('/allowproductcategory/{id}', [AllowedProductCategoryController::class, 'destroy']);

    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/product', [ProductController::class, 'store']);
    Route::get('/product/{id}', [ProductController::class, 'show']);
    Route::post('/product/{id}', [ProductController::class, 'update']);
    Route::delete('/product/{id}', [ProductController::class, 'destroy']);

    Route::get('/foods', [FoodController::class, 'index']);
    Route::post('/food', [FoodController::class, 'store']);
    Route::get('/food/{id}', [FoodController::class, 'show']);
    Route::post('/food/{id}', [FoodController::class, 'update']);
    Route::delete('/food/{id}', [FoodController::class, 'destroy']);

    Route::get('/tourpackages', [TourPackageController::class, 'index']);
    Route::post('/tourpackage', [TourPackageController::class, 'store']);
    Route::get('/tourpackage/{id}', [TourPackageController::class, 'show']);
    Route::post('/tourpackage/{id}', [TourPackageController::class, 'update']);
    Route::delete('/tourpackage/{id}', [TourPackageController::class, 'destroy']);

    Route::get('/accomodationcategories', [AccomodationCategoryController::class, 'index']);
    Route::post('/accomodationcategory', [AccomodationCategoryController::class, 'store']);
    Route::get('/accomodationcategory/{id}', [AccomodationCategoryController::class, 'show']);
    Route::post('/accomodationcategory/{id}', [AccomodationCategoryController::class, 'update']);
    Route::delete('/accomodationcategory/{id}', [AccomodationCategoryController::class, 'destroy']);

    Route::get('/bustypes', [BusTypeController::class, 'index']);
    Route::post('/bustype', [BusTypeController::class, 'store']);
    Route::get('/bustype/{id}', [BusTypeController::class, 'show']);
    Route::post('/bustype/{id}', [BusTypeController::class, 'update']);
    Route::delete('/bustype/{id}', [BusTypeController::class, 'destroy']);



    
});


Route::group(['middleware' => 'api', 'prefix' => 'v2/auth'], function ($router) {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::post('users', [AuthController::class, 'index']);
    Route::post('sendOtp', [AuthController::class, 'sendOtp']);
    Route::post('verifyOtp', [AuthController::class, 'verifyOtp']);
}); 

Route::group(['middleware' =>  ['auth:api', 'premiddleware'], 'prefix' => 'v2'], function ($router) {
    Route::post('listcategories', [CategoryController::class, 'listcategories']);
    Route::post('getCategory', [CategoryController::class, 'getCategory']);
    Route::post('addCategory', [CategoryController::class, 'addCategory']);
    Route::post('updateCategory', [CategoryController::class, 'updateCategory']);
    Route::post('deleteCategory', [CategoryController::class, 'deleteCategory']);
    
    Route::post('sites', [SiteController::class, 'sites']);
    Route::post('getSite', [SiteController::class, 'getSite']);

    Route::post('addSite', [SiteController::class, 'addSite']);
    Route::post('updateSite', [SiteController::class, 'updateSite']);
    Route::delete('deleteSite', [SiteController::class, 'deleteSite']);

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

    
});
