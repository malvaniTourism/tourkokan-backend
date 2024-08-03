<?php

namespace App\Http\Controllers\Admin\V2;

use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\BaseController;
use Illuminate\Support\Facades\Storage;

class GalleryController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function getGallery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'site_id' => 'sometimes|required|exists:sites,id',
            'search' => 'sometimes|required|string|alpha|max:255',
            'category' => 'sometimes|required|exists:categories,code',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $search = $request->input('search');
        $category = $request->input('category');
        $siteId = $request->input('site_id');

        $galleryQuery = Gallery::with([
            'galleryable:id,name,parent_id',
            'galleryable.categories:id,name,code,parent_id'
        ]);

        // Apply site_id filter if provided
        $galleryQuery->when($request->has('site_id') && $siteId = $request->input('site_id'), function ($query) use ($siteId) {
            $query->whereHasMorph('galleryable', '*', function ($query) use ($siteId) {
                $query->where('id', $siteId);
            });
        });

        // Apply category and search filter if both are provided
        $galleryQuery->when($request->has('search') && $request->has('category') && !empty($category = $request->input('category')), function ($query) use ($search, $category) {
            $query->whereHas('galleryable.categories', function ($query) use ($category) {
                $query->where('code', $category);
            })
                ->where('title', 'like', '%' . $search . '%');
        });

        // Apply search filter if only search is provided
        $galleryQuery->when($request->has('search') && empty($request->input('category')), function ($query) use ($search) {
            $query->where('title', 'like', '%' . $search . '%');
        });

        // Paginate the results
        $galleries = $galleryQuery->paginate($request->input('per_page', 10));

        return $this->sendResponse($galleries, 'Gallery images successfully retrieved!');
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
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Gallery  $gallery
     * @return \Illuminate\Http\Response
     */
    public function show(Gallery $gallery)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Gallery  $gallery
     * @return \Illuminate\Http\Response
     */
    public function edit(Gallery $gallery)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Gallery  $gallery
     * @return \Illuminate\Http\Response
     */
    public function updateGallery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:galleries,id',
            'title' => 'sometimes|required|string|between:2,100',
            'description' => 'sometimes|required|string|between:2,500',
            'path' => 'sometimes|nullable|mimes:jpeg,jpg,png.webp|max:512',
            'is_url' => 'sometimes|boolean:true,false',
            'status' => 'sometimes|boolean:true,false'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $input = $request->all();

        $gallery = Gallery::find($request->id);

        $baseClassName = class_basename($gallery->galleryable_type);

        $uploadPath = config('constants.upload_path.' . strtolower($baseClassName));

        $fileFields = ['path'];

        foreach ($fileFields as $field) {
            if ($image = $request->file($field)) {
                $currentFilePath = $gallery->$field;

                if (Storage::exists($currentFilePath)) {
                    Storage::delete($currentFilePath);
                }

                $input[$field] = uploadFile($image, $uploadPath)['path'];
            }
        }

        $gallery->update($input);

        return $this->sendResponse($gallery, 'Gallery updated successfully...!');
    }


    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Gallery  $gallery
     * @return \Illuminate\Http\Response
     */
    public function destroy(Gallery $gallery)
    {
        //
    }
}
