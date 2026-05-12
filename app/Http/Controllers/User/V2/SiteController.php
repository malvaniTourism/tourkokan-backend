<?php

namespace App\Http\Controllers\User\V2;

use App\Models\Site;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use App\Models\Category;
use App\Services\SiteService;
use Illuminate\Support\Facades\Validator;

class SiteController extends BaseController
{
    public function __construct(protected SiteService $siteService)
    {
        $this->middleware('auth:api');
    }

    public function listCities()
    {
        $user = auth()->user();

        $city = Site::withCount(['gallery', 'comment'])
            ->with(['gallery', 'comment', 'categories:id,name,code,parent_id,icon,status,is_hot_category'])
            ->selectSub(function ($query) use ($user) {
                $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
                    ->from('favourites')
                    ->whereColumn('sites.id', 'favourites.favouritable_id')
                    ->where('favourites.favouritable_type', Site::class)
                    ->where('favourites.user_id', $user->id);
            }, 'is_favorite')
            ->whereHas('categories', function ($query) {
                $query->where('code', 'city');
            })->paginate(10);

        return $this->sendResponse($city, 'Cities successfully Retrieved...!');
    }

    public function getSite(Request $request)
    {
        $user_id = auth()->id();

        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:sites,id'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $city = Site::withCount(['sites', 'gallery', 'comment'])
            ->withAvg('rating', 'rate')
            ->with([
                'categories:id,name,code,parent_id,icon,status,is_hot_category',
                'sites' => function ($query) {
                    $query->with('categories:id,name,code,parent_id,icon,status,is_hot_category')
                        ->limit(5);
                },
                'comment' => function ($query) {
                    $query->select('id', 'parent_id', 'user_id', 'comment', 'commentable_type', 'commentable_id')
                        ->limit(5);
                },
                'comment.comments' => function ($query) {
                    $query->select('id', 'parent_id', 'user_id', 'comment', 'commentable_type', 'commentable_id')
                        ->limit(5);
                },
                'comment.users' => function ($query) {
                    $query->select('id', 'name', 'email', 'profile_picture');
                },
                'comment.comments.users' => function ($query) {
                    $query->select('id', 'name', 'email', 'profile_picture');
                },
                'gallery'
            ])
            ->selectSub(function ($query) use ($user_id) {
                $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
                    ->from('favourites')
                    ->whereColumn('sites.id', 'favourites.favouritable_id')
                    ->where('favourites.favouritable_type', Site::class)
                    ->where('favourites.user_id', $user_id);
            }, 'is_favorite')
            ->latest()
            ->limit(5)
            ->find($request->id);

        $city->setAttribute('trending',  $this->siteService->getTrending($request->id));
        $city->setAttribute('hot_sites', $this->siteService->getHotSites($request->id));

        return $this->sendResponse($city, 'Site successfully Retrieved...!');
    }

    public function stops()
    {
        $places = Site::with([
            'site:id,name,icon',
            'categories:id,name,code,parent_id,icon,status,is_hot_category'
        ])
            ->whereIn('bus_stop_type', ['Depo', 'Stop'])
            ->select('id', 'name', 'parent_id', 'icon', 'status', 'is_hot_place', 'bus_stop_type')
            ->paginate(10);

        return $this->sendResponse($places, 'Stops successfully Retrieved...!');
    }

    public function sites(Request $request)
    {
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'search'    => 'sometimes|nullable|string|alpha|max:255',
            'type'      => 'sometimes|required|string|max:255|in:bus',
            'apitype'   => 'required|string|max:255|in:list,dropdown',
            'category'  => ($request->has('type') || $request->has('global')) ? 'nullable|exists:categories,code' : 'nullable|required_without:parent_id|exists:categories,code',
            'parent_id' => 'nullable|required_with:parent_id|exists:sites,parent_id',
            'global'    => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $withArr = [
            'site:id,parent_id,name',
            'sites' => function ($query) use ($user) {
                $query->select(
                    'id', 'name', 'mr_name', 'parent_id', 'image',
                    'domain_name', 'description', 'tag_line',
                    'bus_stop_type', 'icon', 'status',
                )
                    ->with(['rate:id,user_id,rate,rateable_type,rateable_id,status', 'gallery'])
                    ->where('is_hot_place', true)
                    ->selectSub(function ($query) use ($user) {
                        $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
                            ->from('favourites')
                            ->whereColumn('sites.id', 'favourites.favouritable_id')
                            ->where('favourites.favouritable_type', Site::class)
                            ->where('favourites.user_id', $user->id);
                    }, 'is_favorite')
                    ->orderBy('name', 'asc')
                    ->withAvg("rating", 'rate');
            },
            'sites.comment',
            'gallery',
            'comment',
            'categories:id,name,code,parent_id,icon,status,is_hot_category',
            'rate:id,user_id,rate,rateable_type,rateable_id,status',
            'address:id,email,phone,latitude,longitude,addressable_type,addressable_id'
        ];

        if ($request->apitype == 'dropdown') {
            $withArr = ['categories:id,name,code,parent_id,icon,status,is_hot_category'];
        }

        $sites = Site::with($withArr);

        if (isValidReturn($request, 'category') == "emergency") {
            $category = Category::with('subCategories')->where('code', 'emergency')->first();
            if ($category) {
                $ids = $category->subCategories->pluck('id');
                $sites = $sites->whereHas('categories', function ($query) use ($ids) {
                    $query->whereIn('id', $ids);
                });
            } else {
                $sites = $sites->whereNull('id');
            }
        } else {
            if ($request->has('category')) {
                $sites = $sites->whereHas('categories', function ($query) use ($request) {
                    $query->where('code', $request->category);
                });
            }
        }

        if ($request->has('parent_id')) {
            $sites = $sites->where('parent_id', '=', $request->parent_id);
        }

        if ($request->has('global')) {
            $sites = $sites->whereNotNull('parent_id');
        }

        if ($request->has('search')) {
            $sites = $sites->where('name', 'like', $request->input('search') . '%');
        }

        if ($request->has('type') && $request->input('type') == 'bus') {
            $sites = $sites->whereIn('bus_stop_type', ['Depo', 'Stop']);
        }

        $sites = $sites->select(isValidReturn(config('grid.siteApiTypes.' . $request->apitype), 'columns', '*'));

        if ($request->apitype != 'dropdown') {
            $sites = $sites->selectSub(function ($query) use ($user) {
                $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
                    ->from('favourites')
                    ->whereColumn('sites.id', 'favourites.favouritable_id')
                    ->where('favourites.favouritable_type', Site::class)
                    ->where('favourites.user_id', $user->id);
            }, 'is_favorite')
                ->withAvg("rating", 'rate')
                ->withCount(['gallery', 'comment']);
        }

        $sites = $sites->paginate($request->get('per_page', 15));

        return $this->sendResponse($sites, 'Sites successfully Retrieved...!');
    }
}
