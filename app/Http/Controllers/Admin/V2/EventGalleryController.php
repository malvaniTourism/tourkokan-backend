<?php

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\BaseController;
use App\Models\Event;
use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class EventGalleryController extends BaseController
{
    /**
     * List gallery images for any event.
     * POST /v2/getEventGallery
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'event_id' => 'required|exists:events,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        try {
            $event = Event::findOrFail($request->event_id);
            $galleries = $event->galleries()->latest()->get();

            return $this->sendResponse($galleries, 'Gallery fetched successfully');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            return $this->sendError('Event not found', '', 200);
        }
    }

    /**
     * Upload gallery images for a completed event (admin).
     * POST /v2/uploadEventGallery
     *
     * Gallery upload is restricted to events with status = completed.
     */
    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'event_id'    => 'required|exists:events,id',
            'images'      => 'required|array|min:1|max:20',
            'images.*'    => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
            'title'       => 'nullable|string|max:255',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        try {
            $event = Event::findOrFail($request->event_id);

            if ($event->status !== 'completed') {
                return $this->sendError('Gallery images can only be uploaded for completed events', '', 200);
            }

            $uploaded = [];
            foreach ($request->file('images') as $image) {
                $path = uploadFile(
                    $image,
                    config('constants.upload_path.event_gallery')
                )['path'];

                $uploaded[] = $event->galleries()->create([
                    'title'       => $request->input('title', $event->title),
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
     * POST /v2/deleteEventGallery
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
                ->where('galleryable_type', Event::class)
                ->firstOrFail();

            if (!$gallery->is_url && $gallery->path) {
                Storage::delete($gallery->path);
            }

            $gallery->delete();

            return $this->sendResponse(null, 'Gallery image deleted successfully');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            return $this->sendError('Gallery image not found', '', 200);
        }
    }
}
