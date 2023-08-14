<?php

namespace App\Http\Controllers\API\V1;

use App\Models\AppVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\BaseController as BaseController;

class AppVersionController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
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
            'release_date' => 'required|date',
            'release_notes' => 'nullable|string',
            'update_url' => 'nullable|string',
            'meta_data' => 'nullable|json',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $app_version = AppVersion::create($request->all());

        return $this->sendResponse($app_version, 'App version successfully added');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\AppVersion  $appVersion
     * @return \Illuminate\Http\Response
     */
    public function getAppVersion(AppVersion $appVersion)
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
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\AppVersion  $appVersion
     * @return \Illuminate\Http\Response
     */
    public function edit(AppVersion $appVersion)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\AppVersion  $appVersion
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, AppVersion $appVersion)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\AppVersion  $appVersion
     * @return \Illuminate\Http\Response
     */
    public function destroy(AppVersion $appVersion)
    {
        //
    }
}
