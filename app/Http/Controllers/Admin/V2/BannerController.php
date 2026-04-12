<?php

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\BaseController;
use Illuminate\Http\Request;
use App\Models\Banner;
use App\Models\BannerPackage;
use App\Models\BannerPlacement;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BannerController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function listBanners()
    {
        $banner = Banner::with(['bannerable' =>  function ($query) {
            $query->select('id', 'name');
        }, 'bannerable.categories' =>  function ($query) {
            $query->select('id', 'name', 'code');
        }])
            ->paginate(10);

        return $this->sendResponse($banner, 'All Banner successfully Retrieved...!');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'banner_package_id' => 'required|exists:banner_packages,id',
            'banner_placement_id' => 'required|exists:banner_placements,id',
            'title' => 'required|string|max:255',
            'image_url' => 'required|url',
            'redirect_url' => 'nullable|url',
            'start_date' => 'required|date|after_or_equal:today',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $package = BannerPackage::find($request->banner_package_id);
        $placement = BannerPlacement::find($request->banner_placement_id);

        // Validate if placement is allowed in package
        if (!in_array($placement->code, $package->allowed_placements ?? [])) {
            return $this->sendError('This placement is not allowed for the selected package.', [], 422);
        }

        $startDate = Carbon::parse($request->start_date);
        $endDate = $startDate->copy()->addDays($package->duration_days);

        $banner = Banner::create([
            'user_id' => $request->user() ? $request->user()->id : null,
            'banner_package_id' => $package->id,
            'banner_placement_id' => $placement->id,
            'title' => $request->title,
            'image_url' => $request->image_url,
            'redirect_url' => $request->redirect_url,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'pending',
            'is_active' => true,
        ]);

        return $this->sendResponse($banner, 'Banner campaign created successfully. Pending approval.');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function addBanner(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|unique:banners|between:2,40',
            'image' => 'required|mimes:jpeg,jpg,png,webp',
            'start_date' => 'required|date_format:Y-m-d H:i:s',
            'duration' => 'required|in:' . implode(',', array_column(config('constants.banner_days'), 'code')),
            'level' =>  'required|in:' . implode(',', array_column(config('constants.banner_levels'), 'code')),
            'image_orientation' =>  'required|in:' . implode(',', array_column(config('constants.image_orientation'), 'code')),
            'status' => 'boolean',
            'bannerable_type' => 'required|string',
            'bannerable_id' => 'required|numeric',
            'meta_data' => 'nullable|json'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $input = $request->all();
       
        $uploadPath = config('constants.upload_path.banner');

        $fileFields = ['logo', 'icon', 'image'];

        foreach ($fileFields as $field) {
            if ($image = $request->file($field)) {
                $input[$field] = uploadFile($image, $uploadPath)['path'];
            }
        }
        
        $data = getData($request->bannerable_id, $request->bannerable_type);

        if (!$data) {
            return $this->sendError($request->bannerable_type . ' Not Exist..!', '', 400);
        }

        $banner = $data->banners()->create($input);

        return $this->sendResponse($banner, 'Banner added successfully...!');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Banner  $banner
     * @return \Illuminate\Http\Response
     */
    public function getBanner(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:banners,id'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $banner = Banner::with(['bannerable' =>  function ($query) {
            $query->select('id', 'name');
        }, 'bannerable.categories' =>  function ($query) {
            $query->select('id', 'name', 'code');
        }])
            ->find($request->id);

        return $this->sendResponse($banner, 'Banner successfully Retrieved...!');
    }

        /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Banner  $banner
     * @return \Illuminate\Http\Response
     */
    public function updateBanner(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:banners,id',
            'image' => 'nullable|mimes:jpeg,jpg,png,webp',
            'start_date' => 'nullable|date_format:Y-m-d H:i:s',
            'duration' => 'nullable|in:' . implode(',', array_column(config('constants.banner_days'), 'code')),
            'level' =>  'nullable|in:' . implode(',', array_column(config('constants.banner_levels'), 'code')),
            'image_orientation' =>  'nullable|in:' . implode(',', array_column(config('constants.image_orientation'), 'code')),
            'status' => 'boolean',
            'bannerable_type' => 'nullable|required_with:bannerable_id|string',
            'bannerable_id' => 'nullable|required_with:bannerable_type|numeric',
            'meta_data' => 'nullable|json'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $image = null;
        Log::info("upload file starting");
        //Image 1 store      
        if ($image = $request->file('image')) {
            Log::info("inside upload image");

            $image = date('YmdHis') . "." . $image->getClientOriginalExtension();

            $path = $request->file('image')->store(config('constants.upload_path.banner') . $request->bannerable_type . '/' . $request->name);

            $image = Storage::url($path);

            Log::info("FILE STORED" . $image);
        }

        if ($request->bannerable_id && $request->bannerable_type) {
            $data = getData($request->bannerable_id, $request->bannerable_type);

            if (!$data) {
                return $this->sendError($request->bannerable_type . ' Not Exist..!', '', 400);
            }

            $banner = Banner::findOrFail($request->id);

            $banner->fill(array_filter([
                'name'              => $request->name,
                'image'             => $image,
                'start_date'        => $request->start_date,
                'duration'          => $request->duration,
                'level'             => $request->level,
                'image_orientation' => $request->image_orientation,
                'status'            => $request->status,
                'meta_data'         => $request->meta_data,
            ], fn($v) => !is_null($v)));

            $banner->bannerable()->associate($data);
            $banner->save();

            return $this->sendResponse($banner, 'Banner updated successfully...!');
        }

        $input = $request->all();
        $input['image'] = $image;

        $banner = Banner::where('id', $input['id'])->update(array_filter($input, fn($v) => !is_null($v)));

        return $this->sendResponse($input, 'Banner updated successfully...!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Banner  $banner
     * @return \Illuminate\Http\Response
     */
    public function deleteBanner(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:banners,id'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $banner = Banner::find($request->id);

        $banner->delete();

        return $this->sendResponse($banner, 'Banner deleted successfully...!');
    }

    public function fetch(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'placement' => 'required|string|exists:banner_placements,code',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $banners = Banner::active()
            ->whereHas('placement', function ($q) use ($request) {
                $q->where('code', $request->placement);
            })
            ->inRandomOrder()
            ->get();

        // Increment impressions
        foreach ($banners as $banner) {
            $banner->increment('impressions');
        }

        return $this->sendResponse($banners, 'Banners fetched successfully');
    }
}