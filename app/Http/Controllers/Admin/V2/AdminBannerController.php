<?php

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BannerPackage;
use App\Models\BannerPlacement;
use App\Models\Banner;
use Illuminate\Support\Facades\Validator;

class AdminBannerController extends Controller
{
    public function storePackage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'duration_days' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'allowed_placements' => 'required|array',
            'allowed_placements.*' => 'string|exists:banner_placements,code',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $package = BannerPackage::create($request->all());

        return $this->sendResponse($package, 'Banner Package created successfully');
    }

    public function storePlacement(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|unique:banner_placements,code',
            'description' => 'nullable|string',
            'screen' => 'nullable|string',
            'width' => 'nullable|integer',
            'height' => 'nullable|integer',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $placement = BannerPlacement::create($request->all());

        return $this->sendResponse($placement, 'Banner Placement created successfully');
    }

    public function changeStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'banner_id' => 'required|exists:banners,id',
            'status' => 'required|in:approved,rejected,pending,expired'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $banner = Banner::find($request->banner_id);
        $banner->status = $request->status;
        $banner->save();

        return $this->sendResponse($banner, 'Banner status updated successfully');
    }
}