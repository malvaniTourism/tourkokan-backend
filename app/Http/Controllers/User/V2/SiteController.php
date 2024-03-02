<?php

namespace App\Http\Controllers\User\V2;

use App\Models\Site;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use App\Models\Category;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class SiteController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function listCities() //cities
    {
        $user = auth()->user();

        $city = Site::withCount(['photos', 'comment'])
            ->with(['photos', 'comment', 'category:id,name,code,parent_id,icon,status,is_hot_category'])
            ->selectSub(function ($query) use ($user) {
                $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
                    ->from('favourites')
                    ->whereColumn('sites.id', 'favourites.favouritable_id')
                    ->where('favourites.favouritable_type', Site::class)
                    ->where('favourites.user_id', $user->id);
            }, 'is_favorite')
            ->whereHas('category', function ($query) {
                $query->where('code', 'city');
            })->paginate(10);

        return $this->sendResponse($city, 'Cities successfully Retrieved...!');
    }


    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Site  $Site
     * @return \Illuminate\Http\Response
     */
    public function getSite(Request $request)    //city
    {
        // there is bug in this api need to fix if this api is hit and id passed of any other category type site data will be returned.
        // need to restrict this by adding validation or condition
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:sites,id'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $city   =   Site::withCount(['sites', 'photos', 'comment'])
            ->withAvg('rating', 'rate')
            ->with([
                'category:id,name,code,parent_id,icon,status,is_hot_category',
                'sites' => function ($query) {
                    $query->with('category:id,name,code,parent_id,icon,status,is_hot_category')
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
                'photos'
            ])
            ->latest()
            ->limit(5)
            ->find($request->id);

        return $this->sendResponse($city, 'Cities successfully Retrieved...!');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function stops()
    {
        $places = Site::with([
            'site:id,name,icon',
            'category:id,name,code,parent_id,icon,status,is_hot_category'
        ])
            ->whereIn('bus_stop_type', ['Depo', 'Stop'])
            ->select('id', 'name', 'parent_id', 'category_id', 'icon', 'status', 'is_hot_place', 'bus_stop_type')
            ->paginate(10);

        return $this->sendResponse($places, 'Stops successfully Retrieved...!');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function sites(Request $request)
    {
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'search' => 'sometimes|nullable|string|alpha|max:255',
            'type' => 'sometimes|required|string|max:255|in:bus',
            'apitype' => 'required|string|max:255|in:list,dropdown',
            'category' => ($request->has('type') || $request->has('global')) ? 'nullable|exists:categories,code' : 'nullable|required_without:parent_id|exists:categories,code',
            'parent_id' => 'nullable|required_with:parent_id|exists:sites,parent_id',
            'global'    => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $sites = Site::withCount(['photos', 'comment'])
            ->with([
                'sites' => function ($query) use ($user) {
                    $query->select(
                        'id',
                        'name',
                        'name',
                        'parent_id',
                        'category_id',
                        'image',
                        'domain_name',
                        'description',
                        'tag_line',
                        'bus_stop_type',
                        'icon',
                        'status',
                    )
                        ->where('is_hot_place', true)
                        ->selectSub(function ($query) use ($user) {
                            $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
                                ->from('favourites')
                                ->whereColumn('sites.id', 'favourites.favouritable_id')
                                ->where('favourites.favouritable_type', Site::class)
                                ->where('favourites.user_id', $user->id);
                        }, 'is_favorite');
                },
                'sites.comment',
                'photos', 'comment', 'category:id,name,code,parent_id,icon,status,is_hot_category'
            ]);


        if ($request->has('category')) {
            if ($request->category == 'emergency') {
                $category = Category::where('code', 'emergency')->pluck('id');

                $category_ids =  Category::where('parent_id', $category)->get()->pluck('id');

                $sites = $sites->whereIn('category_id', $category_ids);
            } else {
                $sites = $sites->whereHas('category', function ($query) use ($request) {
                    $query->where('code', $request->category);
                });
            }
        }

        if ($request->has('parent_id')) {
            $sites = $sites->orWhere('parent_id', "=", $request->parent_id);
        }

        if ($request->has('global')) {
            $sites = $sites->whereNotNull('parent_id');
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $sites = $sites->where('name', 'like', $search . '%');
        }

        if ($request->has('type') && $request->input('type') == 'bus') {
            $sites =  $sites->whereIn('bus_stop_type', ['Depo', 'Stop']);
        }

        $sites = $sites->select(isValidReturn(config('grid.siteApiTypes.' . $request->apitype), 'columns', '*'))
            ->selectSub(function ($query) use ($user) {
                $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
                    ->from('favourites')
                    ->whereColumn('sites.id', 'favourites.favouritable_id')
                    ->where('favourites.favouritable_type', Site::class)
                    ->where('favourites.user_id', $user->id);
            }, 'is_favorite')
            ->withAvg("rating", 'rate')
            ->paginate(15);


        return $this->sendResponse($sites, 'Sites successfully Retrieved...!');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Site  $site
     * @return \Illuminate\Http\Response
     */
    public function show(Site $site)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Site  $site
     * @return \Illuminate\Http\Response
     */
    public function edit(Site $site)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Site  $site
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Site $site)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Site  $site
     * @return \Illuminate\Http\Response
     */
    public function destroy(Site $site)
    {
        //
    }
}
