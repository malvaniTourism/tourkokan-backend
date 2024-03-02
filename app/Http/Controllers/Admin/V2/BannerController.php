<?php

namespace App\Http\Controllers\Admin\V2;

use App\Models\Banner;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

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
            $query->select('id', 'name', 'category_id');
        }, 'bannerable.category' =>  function ($query) {
            $query->select('id', 'name', 'code');
        }])
            ->paginate(10);

        return $this->sendResponse($banner, 'All Banner successfully Retrieved...!');
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
    public function addBanner(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|unique:banners|between:2,40',
            'image' => 'required|mimes:jpeg,jpg,png,webp',
            'start_date' => 'required|date_format:Y-m-d H:i:s',
            'duration' => 'required|in:' . implode(',', array_column(config('constants.banner_days'), 'code')),
            'level' =>  'required|in:' . implode(',', array_column(config('constants.banner_levels'), 'code')),
            'image_orientation' =>  'required|in:' . implode(',', array_column(config('constants.image_orientation', 'code'), 'code')),
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
            $query->select('id', 'name', 'category_id');
        }, 'bannerable.category' =>  function ($query) {
            $query->select('id', 'name', 'code');
        }])
            ->find($request->id);

        return $this->sendResponse($banner, 'Banner successfully Retrieved...!');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Banner  $banner
     * @return \Illuminate\Http\Response
     */
    public function edit(Banner $banner)
    {
        //
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
            'image_orientation' =>  'nullable|in:' . implode(',', array_column(config('constants.image_orientation', 'code'), 'code')),
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

            $path = $request->file('image')->store(config('constants.upload_path.banners') . $request->bannerable_type . '/' . $request->name);

            $image = Storage::url($path);

            Log::info("FILE STORED" . $image);
        }

        if ($request->bannerable_id && $request->bannerable_type) {
            $data = getData($request->bannerable_id, $request->bannerable_type);

            if (!$data) {
                return $this->sendError($request->bannerable_type . ' Not Exist..!', '', 400);
            }

            $banner = new Banner;

            $banner->name = $request->name;

            $banner->image = $image;

            $banner->start_date = $request->start_date;

            $banner->duration = $request->duration;

            $banner->level = $request->level;

            $banner->image_orientation = $request->image_orientation;

            $banner->status = $request->status;

            $banner->meta_data = $request->meta_data;

            $banner->bannerable()->associate($data);

            $banner = Banner::create(array_filter(json_decode($banner, true)));

            return $this->sendResponse([645], 'Banner updated successfully...!');
        }

        $input =  $request->all();
        $input['image'] = $image;

        //issue to update status because array_filer
        $banner = Banner::where('id', $input['id'])->update(array_filter($input));

        return $this->sendResponse($input, 'Banner updated successfully...!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Banner  $banner
     * @return \Illuminate\Http\Response
     */
    public function deleteBanner($id)
    {
        $banner = Banner::find($id);

        if (is_null($banner)) {
            return $this->sendError('Empty', [], 404);
        }

        $banner->delete($id);

        return $this->sendResponse($banner, 'Banner deleted successfully...!');
    }
}
