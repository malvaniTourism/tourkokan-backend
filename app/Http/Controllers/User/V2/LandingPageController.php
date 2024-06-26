<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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
        $validator = Validator::make($request->all(), [
            'site_id' => 'sometimes|required|exists:sites,id'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        #Banners
        $banners = Banner::latest()
            ->limit(5)
            ->get();

        #Services categories
        $categories = Category::whereNotIn('code', ['country', 'state', 'city', 'district', 'village', 'area'])
            ->latest()
            ->limit(8)
            ->get();

        #Top famouse cities
        $cities = Site::select(
            'id',
            'name',
            'mr_name',
            'tag_line',
            'logo',
            'icon',
            'image'
        )
            ->withAvg("rating", 'rate')
            // ->having('rating_avg_rate', '>', 3)
            ->withCount('photos', 'comment')
            ->with(['category:id,name,code,parent_id,icon,status,is_hot_category'])
            ->whereHas('category', function ($query) {
                $query->where('code', 'city');
            })
            ->selectSub(function ($query) {
                $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
                    ->from('favourites')
                    ->whereColumn('sites.id', 'favourites.favouritable_id')
                    ->where('favourites.favouritable_type', Site::class)
                    ->where('favourites.user_id', config('user_id'));
            }, 'is_favorite')
            ->latest()
            // ->limit(8)
            ->get();


        // #Bus Stops / Depos
        // $stops = Place::withAvg("rating", 'rate')
        //     ->select('id', 'name', 'city_id', 'parent_id', 'place_category_id', 'image_url', 'bg_image_url', 'visitors_count')
        //     ->orWhere('visitors_count', '>=', 5)
        //     ->whereIn('place_category_id', [3, 4])
        //     ->latest()
        //     ->limit(5)
        //     ->get();

        $routes = Route::with([
            'routeStops:id,serial_no,route_id,site_id,arr_time,dept_time,total_time,delayed_time',
            'routeStops.site:id,name,mr_name,category_id',
            'routeStops.site.category:id,name,icon',
            'sourcePlace:id,name,mr_name,category_id',
            'sourcePlace.category:id,name,icon',
            'destinationPlace:id,name,mr_name,category_id',
            'destinationPlace.category:id,name,icon',
            'busType:id,type,logo,meta_data'
        ])->whereHas('routeStops', function ($query) use ($request) {
            if ($request->has('site_id')) {
                $query->where('site_id', $request->site_id);
            }
        })->select(
            'id',
            'source_place_id',
            'destination_place_id',
            'bus_type_id',
            'name',
            'start_time',
            'end_time',
            'total_time',
            'delayed_time',
            DB::raw('(SELECT MAX(distance) FROM route_stops WHERE route_id = routes.id) AS distance')
        )
            ->latest()
            ->limit(5)
            ->get();

        $blogs = Blog::latest()
            ->limit(5)
            ->get();

        $records =  array(
            'version' => AppVersion::latest()->first(),
            'banners' => $banners,
            'routes' => $routes,
            // 'stops' => $stops,
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
