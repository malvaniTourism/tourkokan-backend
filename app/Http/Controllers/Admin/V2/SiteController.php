<?php

namespace App\Http\Controllers\Admin\V2;

use App\Models\Site;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use App\Models\Category;
use Illuminate\Support\Facades\Storage;
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
        $validator = Validator::make($request->all(), [
            'search' => 'sometimes|nullable|string|alpha|max:255',
            'type' => 'sometimes|required|string|max:255|in:bus',
            'apitype' => 'required|string|max:255|in:list,dropdown',
            'category' => 'nullable|exists:categories,code',
            'parent_id' => 'nullable|exists:sites,parent_id',
            'global'    => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $withArr = [
            'sites' => function ($query) {
                $query->select(
                    'id',
                    'name',
                    'mr_name',
                    'parent_id',
                    'image',
                    'domain_name',
                    'description',
                    'tag_line',
                    'bus_stop_type',
                    'icon',
                    'status',
                )
                    ->with(['rate:id,user_id,rate,rateable_type,rateable_id,status'])
                    ->where('is_hot_place', true)
                    ->withAvg("rating", 'rate');
            },
            'sites.comment',
            'photos',
            'comment',
            'categories:id,name,code,parent_id,icon,status,is_hot_category',
            'rate:id,user_id,rate,rateable_type,rateable_id,status',
            'address:id,email,phone,latitude,longitude,addressable_type,addressable_id'
        ];

        if ($request->apitype == 'dropdown') {
            $withArr = [
                'categories:id,name,code,parent_id,icon,status,is_hot_category'
            ];
        }

        $sites = Site::with($withArr);

        if (isValidReturn($request, 'category') == "emergency") {
            // Retrieve the 'emergency' category with its sub-categories
            $category = Category::with('subCategories')->where('code', 'emergency')->first();

            if ($category) {
                // Extract the IDs of all sub-categories
                $ids = $category->subCategories->pluck('id');

                // Filter sites where the categories match the extracted sub-category IDs
                $sites = $sites->whereHas('categories', function ($query) use ($ids) {
                    $query->whereIn('id', $ids);
                });
            } else {
                // If no 'emergency' category is found, return an empty result
                $sites = $sites->whereNull('id'); // Ensures no sites are returned
            }
        } else {
            if ($request->has('category')) {
                // Filter sites based on the specific category code provided in the request
                $sites = $sites->whereHas('categories', function ($query) use ($request) {
                    $query->where('code', $request->category);
                });
            }
        }

        if ($request->has('parent_id')) {
            $sites = $sites->where('parent_id', $request->parent_id);
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
            ->paginate(isValidReturn($request->all(), 'per_page', 15));


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

        $city   =   Site::withCount(['sites', 'photos', 'comment'])
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
                'comment.comment' => function ($query) {
                    $query->select('id', 'parent_id', 'user_id', 'comment', 'commentable_type', 'commentable_id')
                        ->limit(5);
                },
                'comment.users' => function ($query) {
                    $query->select('id', 'name', 'email', 'profile_picture');
                },
                'comment.comment.users' => function ($query) {
                    $query->select('id', 'name', 'email', 'profile_picture');
                },
                'photos'
            ])
            ->withAvg("rating", 'rate')
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
            'categories:id,name,code,parent_id,icon,status,is_hot_category'
        ])
            ->whereIn('bus_stop_type', ['Depo', 'Stop'])
            ->select('id', 'name', 'parent_id', 'icon', 'status', 'is_hot_place', 'bus_stop_type')
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
            ->with(['photos', 'categories:id,name,icon,status']);

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
    public function addSite(Request $request)
    {
        if (is_string($request['categories'])) {
            $request['categories'] = json_decode($request['categories'], true);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:sites,name|string|between:2,100',
            'parent_id' => 'nullable|exists:sites,id',
            'user_id' => 'nullable|exists:users,id',
            'categories' => 'required|array',
            'categories.*' => 'exists:categories,id',
            'bus_stop_type' => 'nullable|in:Stop,Depo',
            'tag_line' => 'required|string|between:2,100',
            'description' => 'required|string',
            'domain_name' => 'nullable|string',
            'logo' => 'nullable|mimes:jpeg,jpg,png|max:1024',
            'icon' => 'nullable|mimes:jpeg,jpg,png|max:512',
            'image' => 'nullable|mimes:jpeg,jpg,png|max:512',
            'status' => 'boolean:true,false',
            'latitude' => 'nullable|required_with:longitude|between:-90,90',
            'longitude' => 'nullable|required_with:latitude|between:-90,90',
            'pin_code' => 'nullable|numeric',
            'speciality' => 'nullable|json',
            'rules' => 'nullable|json',
            'social_media' => 'nullable|json',
            'meta_data' => 'nullable|json',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $validatedData = $validator->validated();

        $input = $request->except(['categories']); // Remove categories from input as we'll handle it separately

        $input = $request->all();

        $uploadPath = config('constants.upload_path.site');

        $fileFields = ['logo', 'icon', 'image'];

        foreach ($fileFields as $field) {
            if ($image = $request->file($field)) {
                $input[$field] = uploadFile($image, $uploadPath)['path'];
            }
        }

        $site = Site::create($input);

        $site->categories()->attach($validatedData['categories']);

        return $this->sendResponse($site, 'Site added successfully...!');
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function updateSite(Request $request)
    {
        // Decode `categories` if it's a JSON string
        if (is_string($request->input('categories'))) {
            $request->merge([
                'categories' => json_decode($request->input('categories'), true)
            ]);
        }

        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:sites,id',
            'name' => 'sometimes|required|unique:sites,name|string|between:2,100',
            'parent_id' => 'sometimes|required|exists:sites,id',
            'user_id' => 'sometimes|required|exists:users,id',
            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
            'bus_stop_type' => 'sometimes|required|in:Stop,Depo',
            'tag_line' => 'sometimes|required|string|between:2,100',
            'description' => 'sometimes|required|string',
            'domain_name' => 'sometimes|required|string',
            'logo' => 'sometimes|required|mimes:jpeg,jpg,png|max:1024',
            'icon' => 'sometimes|required|mimes:jpeg,jpg,png|max:512',
            'image' => 'sometimes|required|mimes:jpeg,jpg,png|max:512',
            'status' => 'sometimes|required|boolean:true,false',
            'latitude' => 'sometimes|required|required_with:longitude|between:-90,90',
            'longitude' => 'sometimes|required|required_with:latitude|between:-90,90',
            'pin_code' => 'sometimes|required|numeric',
            'speciality' => 'sometimes|required|json',
            'rules' => 'sometimes|required|json',
            'social_media' => 'sometimes|required|json',
            'meta_data' => 'sometimes|required|json',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $input = $request->except('categories'); // Exclude categories for separate handling

        $site = Site::find($request->id);

        $uploadPath = config('constants.upload_path.site');

        $fileFields = ['logo', 'icon', 'image'];

        foreach ($fileFields as $field) {
            if ($image = $request->file($field)) {
                $currentFilePath = $site->$field;

                if (Storage::exists($currentFilePath)) {
                    Storage::delete($currentFilePath);
                }

                $input[$field] = uploadFile($image, $uploadPath)['path'];
            }
        }

        // Update site details
        $site->update($input);

        // Update categories if provided
        if ($request->has('categories')) {
            $site->categories()->sync($request->input('categories'));
        }

        return $this->sendResponse($site, 'Site updated successfully...!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Site  $site
     * @return \Illuminate\Http\Response
     */
    public function deleteSite(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:categories,id'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $site = Site::find($request->id);

        if (!$site) {
            return $this->sendError('Empty', [], 404);
        }

        if (Storage::exists($site->logo)) {
            Storage::delete($site->logo);
        }

        if (Storage::exists($site->icon)) {
            Storage::delete($site->icon);
        }

        if (Storage::exists($site->image)) {
            Storage::delete($site->image);
        }

        $site->delete($request->id);

        return $this->sendResponse($site, 'Site deleted successfully...!');
    }
}
