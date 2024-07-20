<?php

namespace App\Http\Controllers\User\V2;

use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\BaseController;

class GalleryController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function getGallery(Request $request)
    {
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'search' => 'sometimes|nullable|string|alpha|max:255',
            'category' => 'nullable|exists:categories,code'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $search = $request->input('search');
        $category = $request->input('category');

        $gallery = Gallery::with([
            'galleryable:id,name,parent_id',
            'galleryable.categories:id,name,code,parent_id'
        ]);

        if ($request->has('search') && $request->has('category') && !empty($category)) {
            $gallery = $gallery->whereHas('galleryable.categories', function ($query) use ($category) {
                $query->where('code', $category);
            })
                ->where('title', 'like',  '%' . $search . '%');
        } elseif ($request->has('search')) {
            $gallery = $gallery->where('title', 'like', '%' . $search . '%');
        }

        $gallery = $gallery->paginate(isValidReturn($request, 'per_page', 10));

        return $this->sendResponse($gallery, 'Gallery images successfully Retrieved...!');
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
    public function update(Request $request, Gallery $gallery)
    {
        //
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
