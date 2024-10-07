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
use App\Models\Gallery;
use App\Models\Contact;

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

        #categories
        $categories = Category::with(['subCategories:id,name,mr_name,code,parent_id,icon,is_hot_category'])
            ->select('*')
            ->whereNotIn('code', ['country', 'state', 'city', 'district', 'village', 'area'])
            ->whereNull('parent_id')
            ->whereStatus(true)
            ->latest()
            ->get();

        #Top famouse cities
        // $cities = Site::select(
        //     'id',
        //     'name',
        //     'mr_name',
        //     'tag_line',
        //     'logo',
        //     'icon',
        //     'image'
        // )
        //     ->withAvg("rating", 'rate')
        //     // ->having('rating_avg_rate', '>', 3)
        //     ->withCount('photos', 'comment')
        //     ->with(['category:id,name,code,parent_id,icon,status,is_hot_category'])
        //     ->whereHas('category', function ($query) {
        //         $query->where('code', 'city');
        //     })
        //     ->selectSub(function ($query) {
        //         $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
        //             ->from('favourites')
        //             ->whereColumn('sites.id', 'favourites.favouritable_id')
        //             ->where('favourites.favouritable_type', Site::class)
        //             ->where('favourites.user_id', config('user_id'));
        //     }, 'is_favorite')
        //     ->latest()
        //     // ->limit(8)
        //     ->get();
        $cities = Site::withCount(['sites', 'photos', 'comment'])
            ->withAvg('rating', 'rate')
            ->whereHas('categories', function ($query) {
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
            ->get()
            ->map(function ($city) {
                $city->rating_avg_rate = number_format($city->rating_avg_rate, 1);
                return $city;
            });

        $cities->load([
            'categories:id,name,code,parent_id,icon,status,is_hot_category'
        ]);

        foreach ($cities as $city) {
            $city->setRelation('sites', $city->sites()->select('id', 'name', 'mr_name', 'parent_id')->with('categories:id,name,mr_name,code,parent_id,icon,status,is_hot_category')->limit(5)->get());
            $city->setRelation('gallery', $city->gallery()->limit(5)->get());

            $city->setRelation('comment', $city->comment()->select('id', 'parent_id', 'user_id', 'comment', 'commentable_type', 'commentable_id')->limit(5)->get()->each(function ($comment) {
                $comment->setRelation('comments', $comment->comments()->select('id', 'parent_id', 'user_id', 'comment', 'commentable_type', 'commentable_id')->limit(5)->get()->each(function ($reply) {
                    $reply->setRelation('users', $reply->users()->select('id', 'name', 'email', 'profile_picture')->get());
                }));
                $comment->setRelation('users', $comment->users()->select('id', 'name', 'email', 'profile_picture')->get());
            }));
        }

        #Routes
        $routes = Route::with([
            'routeStops:id,serial_no,route_id,site_id,arr_time,dept_time,total_time,delayed_time',
            'routeStops.site:id,name,mr_name',
            'routeStops.site.categories:id,name,mr_name,icon',
            'sourcePlace:id,name,mr_name',
            'sourcePlace.categories:id,name,mr_name,icon',
            'destinationPlace:id,name,mr_name',
            'destinationPlace.categories:id,name,mr_name,icon',
            'busType:id,type,logo,meta_data'
        ])->whereHas('routeStops', function ($query) use ($request) {
            // write code to get user city
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


        $gallery = Gallery::with([
            'galleryable:id,name,parent_id',
            'galleryable.categories:id,name,mr_name,code,parent_id'
        ])
            ->limit(isValidReturn($request, 'per_page', 10))
            ->get();

        $queries = Contact::where('user_id', config('user_id'))
            ->limit(isValidReturn($request, 'per_page', 10))
            ->get();
        $category = Category::with('subCategories')->where('code', 'emergency')->first();

        $ids = $category->subCategories->pluck('id');

        $emergency = Site::whereHas('categories', function ($query) use ($ids) {
            $query->whereIn('id', $ids);
        })->get();

        $records = Cache::remember('landing_page_data' . config('user')->id . '_' . $request->site_id, 60, function () use ($banners, $routes, $categories, $cities, $gallery, $queries, $blogs, $emergency) {
            return array(
                'version' => AppVersion::latest()->first(),
                'user' => config('user')->load(['addresses']),
                'banners' => $banners,
                'routes' => $routes,
                // 'stops' => $stops,
                'categories' => $categories,
                'cities' => $cities,
                // 'projects' => $projects,
                // 'products'=>$products,
                // 'place_category' => $place_category,
                // 'places' => $places,
                'gallery' => $gallery,
                'queries' => $queries,
                'emergencies' => $emergency,
                'blogs' => $blogs
            );
        });

        $cachedData = Cache::get('landing_page_data' . config('user')->id . '_' . $request->site_id);

        if ($cachedData) {
            $cachedData['cities'] = $cities;
        }

        return $this->sendResponse($cachedData, 'Landing page data successfully Retrieved...!');
    }
}
