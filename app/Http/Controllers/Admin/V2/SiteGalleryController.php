<?php

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\BaseController;
use App\Models\Gallery;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SiteGalleryController extends BaseController
{
    /**
     * List gallery images for a site.
     * POST /v2/getSiteGallery
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'site_id' => 'required|exists:sites,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        try {
            $site = Site::findOrFail($request->site_id);
            $galleries = $site->gallery()->latest()->get();

            return $this->sendResponse($galleries, 'Gallery fetched successfully');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            return $this->sendError('Site not found', '', 200);
        }
    }

    /**
     * Upload gallery images for any site (admin).
     * POST /v2/uploadSiteGallery
     */
    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'site_id'     => 'required|exists:sites,id',
            'images'      => 'required|array|min:1|max:20',
            'images.*'    => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
            'title'       => 'nullable|string|max:255',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        try {
            $site = Site::findOrFail($request->site_id);

            $uploaded = [];
            foreach ($request->file('images') as $image) {
                $path = uploadFile(
                    $image,
                    config('constants.upload_path.site_gallery')
                )['path'];

                $uploaded[] = $site->gallery()->create([
                    'title'       => $request->input('title', $site->getRawOriginal('name')),
                    'description' => $request->input('description'),
                    'path'        => $path,
                    'is_url'      => false,
                    'status'      => true,
                ]);
            }

            return $this->sendResponse($uploaded, count($uploaded) . ' image(s) uploaded successfully');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Delete a gallery image (admin).
     * POST /v2/deleteSiteGallery
     */
    public function destroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'gallery_id' => 'required|exists:galleries,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        try {
            $gallery = Gallery::where('id', $request->gallery_id)
                ->where('galleryable_type', Site::class)
                ->firstOrFail();

            if (!$gallery->is_url && $gallery->path) {
                Storage::delete($gallery->getRawOriginal('path'));
            }

            $gallery->delete();

            return $this->sendResponse(null, 'Gallery image deleted successfully');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            return $this->sendError('Gallery image not found', '', 200);
        }
    }
}
