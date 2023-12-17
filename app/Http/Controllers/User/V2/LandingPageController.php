<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Validator;
use App\Http\Controllers\BaseController as BaseController;
use App\Models\AppVersion;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Projects;
use App\Models\Products;
use App\Models\Place;
use App\Models\City;
use App\Models\Blog;
use App\Models\Favourite;
use App\Models\PlaceCategory;
use App\Models\Route;
use App\Models\Site;

class LandingPageController extends BaseController
{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        logger($request->ip());
        logger($request->header());
        logger($request->header('user-agent'));

        $user = auth()->user();

        #Services categories
        $categories = Category::whereNotIn('code', ['country', 'state', 'city', 'district', 'village', 'area'])
            ->latest()
            ->limit(8)
            ->get();

        #Top famouse cities
        $cities = Site::select('id', 'name', 'tag_line', 'logo', 'icon', 'image')
            ->withAvg("rateable", 'rate')
            // ->having('rateable_avg_rate', '>', 3)
            ->withCount('photos', 'comments')
            ->with(['category:id,name,code,parent_id,icon,status,is_hot_category'])
            ->whereHas('category', function ($query) {
                $query->where('code', 'city');
            })
            ->selectSub(function ($query) use ($user) {
                $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
                    ->from('favourites')
                    ->whereColumn('sites.id', 'favourites.favouritable_id')
                    ->where('favourites.favouritable_type', Site::class)
                    ->where('favourites.user_id', $user->id);
            }, 'is_favorite')
            ->latest()
            ->limit(4)
            ->get();


        # Top Projects
        // $projects = Projects::withAvg("rateable", 'rate')
        //     // ->having('rateable_avg_rate', '>', 3) //this condition is working
        //     ->withCount('photos')
        //     ->latest()
        //     ->limit(5)
        //     ->get();

        // $products = Products::withAvg("rateable", 'rate')
        //                     ->having('rateable_avg_rate', '>', 3)
        //                     ->withCount('comments','photos')
        //                     ->latest()
        //                     ->limit(6)
        //                     ->get();

        #Bus Stops / Depos
        $stops = Place::withAvg("rateable", 'rate')
            ->select('id', 'name', 'city_id', 'parent_id', 'place_category_id', 'image_url', 'bg_image_url', 'visitors_count')
            ->orWhere('visitors_count', '>=', 5)
            ->whereIn('place_category_id', [3, 4])
            ->latest()
            ->limit(5)
            ->get();

        $routes = Route::with([
            'routeStops:id,serial_no,route_id,site_id,arr_time,dept_time,total_time,delayed_time',
            'routeStops.site:id,name,category_id',
            'routeStops.site.category:id,name,icon',
            'sourcePlace:id,name,category_id',
            'sourcePlace.category:id,name,icon',
            'destinationPlace:id,name,category_id',
            'destinationPlace.category:id,name,icon',
            'busType:id,type,logo,meta_data'
        ])->select('id', 'source_place_id', 'destination_place_id', 'bus_type_id', 'name', 'start_time', 'end_time', 'total_time', 'delayed_time')
            ->latest()
            ->limit(5)
            ->get();


        #Place Categories
        // $place_category = PlaceCategory::with(['places' => function ($query) {
        //     $query->select('places.id', 'places.name', 'places.city_id', 'places.parent_id', 'places.place_category_id', 'places.image_url', 'places.bg_image_url', 'places.visitors_count')
        //         ->leftJoin('places as p2', function ($join) {
        //             $join->on('places.place_category_id', '=', 'p2.place_category_id')
        //                 ->whereRaw('places.id <= p2.id');
        //         })
        //         ->groupBy('places.id')
        //         ->havingRaw('COUNT(*) <= 5');
        // }])
        //     ->withCount('places')
        //     ->limit(5)
        //     ->get();

        // return $place_catgory;
        #Top Places
        //add location based filter near by famous locations
        // $places = Place::select('id', 'name', 'city_id', 'place_category_id', 'parent_id', 'rating', 'visitors_count')
        //     ->withAvg("rateable", 'rate')
        //     // ->having('rateable_avg_rate', '>', 3)
        //     // ->orWhere('visitors_count', '>=', 5)
        //     ->withCount('photos')
        //     ->with(['placeCategory' => function ($query) {
        //         $query->select('id', 'name', 'icon');
        //     }, 'city' => function ($query) {
        //         $query->select('id', 'name', 'image_url');
        //     }])
        //     ->selectSub(function ($query) use ($user) {
        //         $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
        //             ->from('favourites')
        //             ->whereColumn('places.id', 'favourites.favouritable_id')
        //             ->where('favourites.favouritable_type', Place::class)
        //             ->where('favourites.user_id', $user->id);
        //     }, 'is_favorite')
        //     ->latest()
        //     ->limit(5)
        //     ->get();

        $blogs = Blog::latest()
            ->limit(5)
            ->get();

        $records =  array(
            'version' => AppVersion::latest()->first(),
            'banners' => Banner::get(),
            'routes' => $routes,
            'stops' => $stops,
            'categories' => $categories,
            'cities' => $cities,
            // 'projects' => $projects,
            // 'products'=>$products,
            // 'place_category' => $place_category,
            // 'places' => $places,
            'blogs' => $blogs
        );

        return $this->sendResponse($records, 'Landing page data successfully Retrieved...!');
    }
}
