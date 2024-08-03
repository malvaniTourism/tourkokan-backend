<?php

namespace App\Http\Controllers\Admin\V2;

use App\Models\AppVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\Cache;

class AppVersionController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function listAppVersions(Request $request)
    {
        try {
            $app_version = AppVersion::latest()
                ->paginate(isValidReturn($request->all(), 'per_page', 15));

            if (!$app_version)
                return $this->sendError('Empty', [], 404);

            return $this->sendResponse($app_version, 'App version successfully Retrieved...!');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
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
    public function addAppVersion(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'platform' => 'required|string',
            'version_number' => 'required|string|unique:app_versions',
            'release_date' => 'required|date_format:Y-m-d H:i:s',
            'release_notes' => 'nullable|string',
            'update_url' => 'nullable|string',
            'meta_data' => 'nullable|json',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $app_version = AppVersion::create($request->all());

        Cache::forget('app_version');

        return $this->sendResponse($app_version, 'App version successfully added');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\AppVersion  $appVersion
     * @return \Illuminate\Http\Response
     */
    public function getAppVersion()
    {
        try {
            $app_version = AppVersion::latest()
                ->first();

            if (!$app_version)
                return $this->sendError('Empty', [], 404);

            return $this->sendResponse($app_version, 'App version successfully Retrieved...!');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\AppVersion  $appVersion
     * @return \Illuminate\Http\Response
     */
    public function updateAppVersion(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'platform' => 'sometimes|required|string',
            'version_number' => 'sometimes|required|string|unique:app_versions,version_number,' . $request->id,
            'release_date' => 'sometimes|required|date_format:Y-m-d H:i:s',
            'release_notes' => 'nullable|string',
            'update_url' => 'nullable|string',
            'meta_data' => 'nullable|json',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        // Find the app version record by ID
        $app_version = AppVersion::find($request->id);

        if (!$app_version) {
            return $this->sendError('App version not found', '', 404);
        }

        // Update the app version with the validated data
        $app_version->update($request->all());

        // Clear the cache for app_version
        Cache::forget('app_version');

        return $this->sendResponse($app_version, 'App version successfully updated');
    }


    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\AppVersion  $appVersion
     * @return \Illuminate\Http\Response
     */
    public function deleteAppVersion(Request $request)
    {
        $app_version = AppVersion::find($request->id);

        if (is_null($app_version)) {
            return $this->sendError('Empty', [], 404);
        }

        $app_version->delete($request->id);

        return $this->sendResponse(true, 'App version deleted successfully...!');
    }
}
