<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use App\Models\AppVersion;
use App\Models\Banner;
use App\Models\BannerPlacement;
use App\Models\Category;
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
use App\Models\AdminMessage;
use App\Models\Contact;
use App\Services\CategoryService;
use App\Services\ContactService;
use App\Services\SiteService;

class LandingPageController extends BaseController
{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct(
        protected CategoryService $categoryService,
        protected ContactService $contactService,
        protected SiteService $siteService,
    ) {
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
        $banners = BannerPlacement::with(['banners' => function ($query) {
            // banners.status is a boolean column (tinyint), not a workflow string
            $query->where('is_active', true)
                ->where('status', true)
                // ->whereDate('start_date', '<=', now())
                // ->whereDate('end_date', '>=', now())
                ->latest();
        }])
            ->whereHas('banners', function ($query) {
                $query->where('is_active', true)
                    ->where('status', true);
                    // ->whereDate('start_date', '<=', now())
                    // ->whereDate('end_date', '>=', now());
            })
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->code => $item->banners];
            });

        $categories = $this->categoryService->getPaginated(null, 1, 15, [
            'paginate' => false,
            'fields'   => ['id', 'name', 'code', 'icon', 'is_hot_category'],
        ]);

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
        //             ->where('favourites.user_id', auth()->id());
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
                    ->where('favourites.favouritable_type', (new Site)->getMorphClass())
                    ->where('favourites.user_id', auth()->id());
            }, 'is_favorite')
            ->latest()
            ->get()
            ->map(function ($city) {
                $city->rating_avg_rate = number_format((float) $city->rating_avg_rate, 1);
                return $city;
            });

        // Single eager-load pass (categories -> sites -> gallery -> comment tree) instead of
        // per-city lazy queries; per-parent limits ride on MySQL 8 window functions.
        $cities->load([
            'categories:id,name,code,parent_id,icon,status,is_hot_category',
            'sites' => fn($q) => $q->select('id', 'name', 'mr_name', 'parent_id')
                ->with(['categories:id,name,mr_name,code,parent_id,icon,status,is_hot_category', 'site:id,parent_id,name,mr_name'])
                ->orderBy('id')->limit(5),
        ]);
        SiteService::loadSiteEngagement($cities);

        #Top 5 Hotels, Restaurants, Resorts
        $categorySites = $this->siteService->getTrending($request->filled('site_id') ? $request->site_id : null);

        #Routes
        $routes = Route::with([
            'routeStops:id,serial_no,route_id,site_id,arr_time,dept_time,total_time,delayed_time,distance',
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
            DB::raw('ROUND((SELECT MAX(distance) FROM route_stops WHERE route_id = routes.id), 2) AS distance')
        )
        ->latest()
        ->limit(5)
        ->get();

        $blogs = Blog::latest()
            ->limit(5)
            ->get();

        #Hot Sites
        $hotSites = $this->siteService->getHotSites($request->filled('site_id') ? $request->site_id : null);


        // Homepage feed shows place/site galleries only. Product galleries (added with the
        // marketplace) live on a different table shape — no `parent_id`, no `categories`
        // relation — so pulling them into this constrained morph load crashes the query.
        $gallery = Gallery::whereNot('galleryable_type', (new \App\Models\Product())->getMorphClass())
            ->with([
                'galleryable:id,name,parent_id',
                'galleryable.categories:id,name,mr_name,code,parent_id'
            ])
            ->limit(isValidReturn($request, 'per_page', 10))
            ->get();

        $queries = $this->contactService->getForUser([
            'user_id' => auth()->id(),
            'limit'   => isValidReturn($request, 'per_page', 10),
            'counts'  => true,
        ]);
        // $category = Category::with('subCategories')->where('code', 'emergency')->first();

        // $ids = $category->subCategories->pluck('id');

        // $emergency = Site::whereHas('categories', function ($query) use ($ids) {
        //     $query->whereIn('id', $ids);
        // })->get();

        $emergency = $this->categoryService->getPaginated(
            'emergency',
            1
        );
        

        $records = Cache::remember('landing_page_data' . auth()->user()->id . '_' . $request->site_id, 60, function () use ($banners, $routes, $categories, $cities, $gallery, $queries, $blogs, $emergency, $categorySites, $hotSites) {
            return array(
                'version' => AppVersion::latest()->first(),
                'user' => auth()->user()->load(['addresses']),
                'banners' => $banners,
                'routes' => $routes,
                // 'stops' => $stops,
                'categories' => $categories,
                'cities' => $cities,
                'trending' => $categorySites,
                // 'projects' => $projects,
                // 'products'=>$products,
                // 'place_category' => $place_category,
                // 'places' => $places,
                'gallery' => $gallery,
                'queries' => $queries,
                'emergencies' => $emergency,
                'blogs' => $blogs,
                'hot_sites' => $hotSites,
            );
        });

        $cachedData = Cache::get('landing_page_data' . auth()->user()->id . '_' . $request->site_id);

        if ($cachedData) {
            $cachedData['cities'] = $cities;
            $cachedData['hot_sites'] = $hotSites;
            $cachedData['trending'] = $categorySites;
            $cachedData['unread_message_count'] = AdminMessage::where('user_id', auth()->id())
                ->where('is_read', false)
                ->count();
        }

        return $this->sendResponse($cachedData, 'Landing page data successfully Retrieved...!');
    }
}
