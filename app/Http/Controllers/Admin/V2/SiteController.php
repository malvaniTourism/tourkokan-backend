<?php

namespace App\Http\Controllers\Admin\V2;

use App\Models\Site;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\Validator;

class SiteController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function sites(Request $request) //cities
    {
        // $user = auth()->user();
        $validator = Validator::make($request->all(), [
            'search' => 'sometimes|nullable|string|alpha|max:255',
            'type' => 'sometimes|required|string|max:255|in:bus',
            'apitype' => 'required|string|max:255|in:list,dropdown',
            'category' => ($request->has('type')) ? 'nullable|exists:categories,code' : 'nullable|required_without:parent_id|exists:categories,code',
            'parent_id' => 'nullable|required_with:parent_id|exists:sites,parent_id'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $sites = Site::withCount(['photos', 'comments'])
            ->with(['photos', 'comments', 'category:id,name,code,parent_id,icon,status,is_hot_category']);
            
        if ($request->has('category')) {
            $sites = $sites->whereHas('category', function ($query) use ($request) {
                $query->where('code', $request->category);
            });
        }

        if ($request->has('parent_id')) {
            $sites = $sites->orWhere('parent_id', "=", $request->parent_id);
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $sites = $sites->where('name', 'like', '%' . $search . '%');
        }

        if ($request->has('type') && $request->input('type') == 'bus') {
            $sites =  $sites->whereIn('bus_stop_type', ['Depo', 'Stop']);
        }

        $sites = $sites->select(isValidReturn(config('grid.siteApiTypes.' . $request->apitype), 'columns', '*'))
            ->paginate(10);

        return $this->sendResponse($sites, 'Sites successfully Retrieved...!');
    }


    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Site  $Site
     * @return \Illuminate\Http\Response
     */
    public function getSite(Request $request)
    {
        // there is bug in this api need to fix if this api is hit and id passed of any other category type site data will be returned.
        // need to restrict this by adding validation or condition
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:sites,id'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $city   =   Site::withCount(['sites', 'photos', 'comments'])
            ->withAvg('rateable', 'rate')
            ->with([
                'category:id,name,code,parent_id,icon,status,is_hot_category',
                'sites' => function ($query) {
                    $query->with('category:id,name,code,parent_id,icon,status,is_hot_category')
                        ->limit(5);
                },
                'comments' => function ($query) {
                    $query->select('id', 'parent_id', 'user_id', 'comment', 'commentable_type', 'commentable_id')
                        ->limit(5);
                },
                'comments.comments' => function ($query) {
                    $query->select('id', 'parent_id', 'user_id', 'comment', 'commentable_type', 'commentable_id')
                        ->limit(5);
                },
                'comments.users' => function ($query) {
                    $query->select('id', 'name', 'email', 'profile_picture');
                },
                'comments.comments.users' => function ($query) {
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
    public function searchPlace(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search' => 'sometimes|nullable|string|alpha|max:255',
            'type' => 'sometimes|nullable|string|max:255|in:bus',
            'apitype' => 'required|string|max:255|in:list,dropdown',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $places = Site::withCount(['sites', 'photos', 'comments'])
            ->with(['photos', 'category:id,name,icon,status']);

        if ($request->has('search')) {
            $search = $request->input('search');
            $places = $places->where('name', 'like', '%' . $search . '%');
        }

        if ($request->has('type') && $request->input('type') == 'bus') {
            $places->whereIn('bus_stop_type', ['Depo', 'Stop']);
        }

        $places = $places->select(isValidReturn(config('grid.siteApiTypes.' . $request->apitype), 'columns', '*'))
            ->paginate();

        return $this->sendResponse($places, 'places successfully Retrieved...!');
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
