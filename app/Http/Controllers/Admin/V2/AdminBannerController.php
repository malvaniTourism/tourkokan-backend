<?php

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\BaseController;
use Illuminate\Http\Request;
use App\Models\BannerPackage;
use App\Models\BannerPlacement;
use App\Models\Banner;
use Illuminate\Support\Facades\Validator;

class AdminBannerController extends BaseController
{
    // ─── Dropdowns ───────────────────────────────────────────────────────────

    public function bannerFormDD()
    {
        $placements = BannerPlacement::where('is_active', true)
            ->orderByDesc('id')
            ->get(['id', 'code', 'description', 'screen', 'width', 'height'])
            ->keyBy('code');

        $packages = BannerPackage::where('is_active', true)
            ->orderBy('price')
            ->get(['id', 'name', 'duration_days', 'price', 'allowed_placements'])
            ->map(function ($package) use ($placements) {
                $package->allowed_placements = collect($package->allowed_placements)
                    ->map(fn($code) => $placements->get($code))
                    ->filter()
                    ->values();
                return $package;
            });

        return $this->sendResponse($packages, 'Banner form dropdown retrieved successfully');
    }

    // ─── Banner Packages (Pricing) ───────────────────────────────────────────

    public function listPackages(Request $request)
    {
        $packages = BannerPackage::orderBy('created_at', 'desc')->get();
        return $this->sendResponse($packages, 'Banner packages retrieved successfully');
    }

    public function getPackage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:banner_packages,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $package = BannerPackage::findOrFail($request->id);
        return $this->sendResponse($package, 'Banner package retrieved successfully');
    }

    public function storePackage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'                  => 'required|string',
            'duration_days'         => 'required|integer|min:1',
            'price'                 => 'required|numeric|min:0',
            'allowed_placements'    => 'required|array',
            'allowed_placements.*'  => 'string|exists:banner_placements,code',
            'is_active'             => 'boolean',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $package = BannerPackage::create($request->all());
        return $this->sendResponse($package, 'Banner package created successfully');
    }

    public function updatePackage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'                    => 'required|exists:banner_packages,id',
            'name'                  => 'sometimes|string',
            'duration_days'         => 'sometimes|integer|min:1',
            'price'                 => 'sometimes|numeric|min:0',
            'allowed_placements'    => 'sometimes|array',
            'allowed_placements.*'  => 'string|exists:banner_placements,code',
            'is_active'             => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $package = BannerPackage::findOrFail($request->id);
        $package->update($request->except('id'));
        return $this->sendResponse($package, 'Banner package updated successfully');
    }

    public function deletePackage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:banner_packages,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        BannerPackage::findOrFail($request->id)->delete();
        return $this->sendResponse([], 'Banner package deleted successfully');
    }

    // ─── Banner Placements ───────────────────────────────────────────────────

    public function listPlacements(Request $request)
    {
        $placements = BannerPlacement::orderBy('created_at', 'desc')->get();
        return $this->sendResponse($placements, 'Banner placements retrieved successfully');
    }

    public function getPlacement(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:banner_placements,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $placement = BannerPlacement::findOrFail($request->id);
        return $this->sendResponse($placement, 'Banner placement retrieved successfully');
    }

    public function storePlacement(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code'        => 'required|string|unique:banner_placements,code',
            'description' => 'nullable|string',
            'screen'      => 'nullable|string',
            'width'       => 'nullable|integer',
            'height'      => 'nullable|integer',
            'is_active'   => 'boolean',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $placement = BannerPlacement::create($request->all());
        return $this->sendResponse($placement, 'Banner placement created successfully');
    }

    public function updatePlacement(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'          => 'required|exists:banner_placements,id',
            'code'        => 'sometimes|string|unique:banner_placements,code,' . $request->id,
            'description' => 'sometimes|nullable|string',
            'screen'      => 'sometimes|nullable|string',
            'width'       => 'sometimes|nullable|integer',
            'height'      => 'sometimes|nullable|integer',
            'is_active'   => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $placement = BannerPlacement::findOrFail($request->id);
        $placement->update($request->except('id'));
        return $this->sendResponse($placement, 'Banner placement updated successfully');
    }

    public function deletePlacement(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:banner_placements,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        BannerPlacement::findOrFail($request->id)->delete();
        return $this->sendResponse([], 'Banner placement deleted successfully');
    }

    // ─── Banner Status ───────────────────────────────────────────────────────

    public function changeStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'banner_id' => 'required|exists:banners,id',
            'status'    => 'required|in:approved,rejected,pending,expired',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $banner = Banner::findOrFail($request->banner_id);
        $banner->status = $request->status;
        $banner->save();

        return $this->sendResponse($banner, 'Banner status updated successfully');
    }
}
