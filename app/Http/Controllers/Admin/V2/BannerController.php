<?php

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\BaseController;
use App\Rules\ImageGuideline;
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
    public function listBanners(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'per_page'             => 'sometimes|integer|min:1|max:30',
            'status'               => 'sometimes|boolean',
            'is_active'            => 'sometimes|boolean',
            'banner_placement_id'  => 'sometimes|exists:banner_placements,id',
            'banner_package_id'    => 'sometimes|exists:banner_packages,id',
            'level'                => 'sometimes|in:' . implode(',', array_column(config('constants.banner_levels'), 'code')),
            'search'               => 'sometimes|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $query = Banner::with([
            'package:id,name,duration_days,price',
            'placement:id,code,description,screen,width,height',
            'bannerable' => fn($q) => $q->select('id', 'name'),
            'bannerable.categories' => fn($q) => $q->select('id', 'name', 'code'),
        ]);

        $query->when($request->filled('status'),              fn($q) => $q->where('status', $request->status));
        $query->when($request->filled('is_active'),           fn($q) => $q->where('is_active', $request->is_active));
        $query->when($request->filled('banner_placement_id'), fn($q) => $q->where('banner_placement_id', $request->banner_placement_id));
        $query->when($request->filled('banner_package_id'),   fn($q) => $q->where('banner_package_id', $request->banner_package_id));
        $query->when($request->filled('level'),               fn($q) => $q->where('level', $request->level));
        $query->when($request->filled('search'),              fn($q) => $q->where('name', 'like', '%' . $request->search . '%'));

        $banners = $query->orderByDesc('created_at')
            ->paginateSafe();

        return $this->sendResponse($banners, 'All Banner successfully Retrieved...!');
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
        // Carousel renders as home hero (1.35:1); middle/footer are ad slots (2.5:1)
        $imageType = $request->input('level') === 'carousel' ? 'hero_home' : 'ad_banner';

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|unique:banners|between:2,40',
            'image' => ['required', 'mimes:jpeg,jpg,png,webp', new ImageGuideline($imageType)],
            'start_date' => 'required|date_format:Y-m-d H:i:s',
            'duration' => 'required|in:' . implode(',', array_column(config('constants.banner_days'), 'code')),
            'level' =>  'required|in:' . implode(',', array_column(config('constants.banner_levels'), 'code')),
            'image_orientation' =>  'required|in:' . implode(',', array_column(config('constants.image_orientation'), 'code')),
            'status' => 'boolean',
            'bannerable_type' => 'required|string',
            'bannerable_id' => 'required|numeric',
            'redirect_url' => 'nullable|url',
            'meta_data' => 'nullable|json'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $input = $request->all();
       
        $uploadPath = config('constants.upload_path.banner');

        $fileFields = ['image'];

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
        // Level may be absent on update — fall back to the banner's stored level
        $level     = $request->input('level') ?? Banner::where('id', $request->id)->value('level');
        $imageType = $level === 'carousel' ? 'hero_home' : 'ad_banner';

        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:banners,id',
            'image' => ['nullable', 'mimes:jpeg,jpg,png,webp', new ImageGuideline($imageType)],
            'start_date' => 'nullable|date_format:Y-m-d H:i:s',
            'duration' => 'nullable|in:' . implode(',', array_column(config('constants.banner_days'), 'code')),
            'level' =>  'nullable|in:' . implode(',', array_column(config('constants.banner_levels'), 'code')),
            'image_orientation' =>  'nullable|in:' . implode(',', array_column(config('constants.image_orientation'), 'code')),
            'status' => 'boolean',
            'bannerable_type' => 'nullable|required_with:bannerable_id|string',
            'bannerable_id' => 'nullable|required_with:bannerable_type|numeric',
            'redirect_url' => 'nullable|url',
            'meta_data' => 'nullable|json'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $banner = Banner::findOrFail($request->id);

        $input = $request->except(['id', 'bannerable_id', 'bannerable_type']);

        if ($request->hasFile('image')) {
            $rawPath = $banner->getRawOriginal('image');
            if ($rawPath && Storage::exists($rawPath)) {
                Storage::delete($rawPath);
            }
            $input['image'] = uploadFile($request->file('image'), config('constants.upload_path.banner'))['path'];
        } else {
            unset($input['image']);
        }

        if ($request->bannerable_id && $request->bannerable_type) {
            $data = getData($request->bannerable_id, $request->bannerable_type);

            if (!$data) {
                return $this->sendError($request->bannerable_type . ' Not Exist..!', '', 400);
            }

            $banner->bannerable()->associate($data);
        }

        $banner->update(array_filter($input, fn($v) => !is_null($v)));

        return $this->sendResponse($banner->refresh(), 'Banner updated successfully...!');
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

        $rawImage = $banner->getRawOriginal('image');
        if ($rawImage && Storage::exists($rawImage)) {
            Storage::delete($rawImage);
        }

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