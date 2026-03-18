<?php

namespace App\Http\Controllers\User\V2;

use App\Models\Gallery;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\BaseController;

class GalleryController extends BaseController
{
    /**
     * Flat paginated gallery with optional search (by image title or site name)
     * and optional category filter. Both filters are independent and combinable.
     */
    public function getGallery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search'   => 'sometimes|nullable|string|max:255',
            'category' => 'sometimes|nullable|exists:categories,code',
            'site_id'  => 'sometimes|nullable|exists:sites,id',
            'per_page' => 'sometimes|nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $search   = $request->input('search');
        $category = $request->input('category');
        $siteId   = $request->input('site_id');

        $gallery = Gallery::with([
            'galleryable:id,name,mr_name,parent_id',
            'galleryable.categories:id,name,code,parent_id'
        ])->where('status', true);

        if (!empty($siteId)) {
            $gallery->where('galleryable_type', Site::class)
                    ->where('galleryable_id', $siteId);
        }

        if (!empty($category)) {
            $gallery->whereHas('galleryable.categories', function ($query) use ($category) {
                $query->where('code', $category);
            });
        }

        if (!empty($search)) {
            $gallery->where(function ($query) use ($search) {
                $query->where('title', 'like', '%' . $search . '%')
                      ->orWhereHas('galleryable', function ($query) use ($search) {
                          $query->where('name', 'like', '%' . $search . '%')
                                ->orWhere('mr_name', 'like', '%' . $search . '%');
                      });
            });
        }

        $gallery = $gallery->latest()->paginate($request->input('per_page', 10));

        return $this->sendResponse($gallery, 'Gallery images successfully retrieved.');
    }


}
