<?php

namespace App\Http\Controllers\Admin\V2;

use App\Models\BonusTypes;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;

class BonusTypesController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function listBonusTypes(Request $request)
    {
        $page = $request->input('page', 1); // Default to page 1
        $perPage = $request->input('per_page', 15); // Default to 15 items per page

        // Generate a unique cache key based on the page number and items per page
        $cacheKey = 'bonus_types_page_' . $page . '_per_page_' . $perPage;

        // Check if cache exists for the generated key
        if (!Cache::has($cacheKey)) {
            $bonusTypes = Cache::remember($cacheKey, 60, function () use ($perPage) {
                return BonusTypes::paginate($perPage);
            });
        } else {
            $bonusTypes = Cache::get($cacheKey);
        }

        // If the cache returns null or the result is empty, send an error response
        if (!$bonusTypes) {
            return $this->sendError('Empty', [], 404);
        }


        return $this->sendResponse($bonusTypes, 'Bonus Types successfully Retrieved...!');
    }

    public function getBonusType(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:bonus_types,id'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $bonusTypes = Cache::remember('bonus_type' . $request->id, 3600, function ()  use ($request) {
            return BonusTypes::find($request->id);
        });

        return $this->sendResponse($bonusTypes, 'Bonus type retrieved successfully...!');
    }

    protected function clearPaginatedCache()
    {
        // Clear the cache for all pages. This is a simple way but can be optimized.
        for ($page = 1; $page <= 100; $page++) { // Assuming a reasonable upper limit
            for ($perPage = 10; $perPage <= 100; $perPage += 10) { // Assuming step sizes
                $cacheKey = 'bonus_types_page_' . $page . '_per_page_' . $perPage;
                Cache::forget($cacheKey);
            }
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function addBonusType(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|unique:bonus_types,name',
            'code' => 'required|string|unique:bonus_types,code',
            'amount' => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $bonusType = BonusTypes::create($request->all());

        // Clear the cache for all paginated lists
        $this->clearPaginatedCache();

        return $this->sendResponse($bonusType, 'Category added successfully...!');
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\BonusTypes  $bonusTypes
     * @return \Illuminate\Http\Response
     */
    public function updateBonusType(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:bonus_types,id',
            'name' => 'nullable|string|unique:bonus_types,name',
            'code' => 'nullable|string|unique:bonus_types,code',
            'amount' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $bonusType = BonusTypes::find($request->id);

        if (!$bonusType) {
            return $this->sendError('Empty', [], 404);
        }

        $bonusType->update(array_filter($request->all()));

        // Clear the cache for all paginated lists
        $this->clearPaginatedCache();

        return $this->sendResponse($bonusType, 'Bonus type updated successfully...!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\BonusTypes  $bonusTypes
     * @return \Illuminate\Http\Response
     */
    public function deleteBonusType(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:bonus_types,id'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $bonusType = BonusTypes::find($request->id);

        if (!$bonusType) {
            return $this->sendError('Empty', [], 404);
        }

        $bonusType->delete($request->id);

        Cache::forget('bonus_type' . $request->id);

        // Clear the cache for all paginated lists
        $this->clearPaginatedCache();

        return $this->sendResponse($bonusType, 'Bonus Type deleted successfully...!');
    }
}
