<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Event\CreateEventRequest;
use App\Http\Requests\Event\UpdateEventRequest;
use App\Models\Event;
use App\Models\EventInteraction;
use App\Models\Favourite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class EventController extends BaseController
{
    /**
     * List all approved events with optional filters.
     * GET /api/v2/events
     */
    public function index(Request $request)
    {
        try {
            $query = Event::with(['eventType', 'site:id,name'])
                ->approved();

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'LIKE', "%{$search}%")
                      ->orWhere('description', 'LIKE', "%{$search}%")
                      ->orWhere('venue_name', 'LIKE', "%{$search}%");
                });
            }

            if ($request->filled('taluka')) {
                $query->where('taluka', $request->taluka);
            }

            if ($request->filled('event_type_id')) {
                $query->where('event_type_id', $request->event_type_id);
            }

            if ($request->filled('is_free')) {
                $query->where('is_free', (bool) $request->is_free);
            }

            if ($request->filled('start_date')) {
                $query->where('start_date', '>=', $request->start_date);
            }

            if ($request->filled('end_date')) {
                $query->where('end_date', '<=', $request->end_date);
            }

            if ($request->boolean('upcoming')) {
                $query->upcoming();
            }

            if ($request->filled('is_featured') && $request->is_featured) {
                $query->featured();
            }

            $perPage = $request->input('per_page', 15);
            $events  = $query->orderBy('start_date')->paginate($perPage);

            // Append user interaction flags if authenticated
            if (auth()->check()) {
                $userId   = auth()->id();
                $eventIds = $events->pluck('id');

                $interactions = EventInteraction::whereIn('event_id', $eventIds)
                    ->where('user_id', $userId)
                    ->get()
                    ->groupBy('event_id');

                $favourites = Favourite::where('favouritable_type', Event::class)
                    ->whereIn('favouritable_id', $eventIds)
                    ->where('user_id', $userId)
                    ->pluck('favouritable_id')
                    ->toArray();

                $events->getCollection()->transform(function ($event) use ($interactions, $favourites) {
                    $userInteractions = $interactions->get($event->id, collect())->pluck('interaction_type');
                    $event->user_interaction = [
                        'has_liked'     => $userInteractions->contains('like'),
                        'is_going'      => $userInteractions->contains('going'),
                        'is_interested' => $userInteractions->contains('interested'),
                        'has_favourited' => in_array($event->id, $favourites),
                    ];
                    return $event;
                });
            }

            return $this->sendResponse($events, 'Events fetched successfully');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Get single event by slug.
     * GET /api/v2/events/{slug}
     */
    public function show(Request $request, $slug)
    {
        try {
            $event = Event::with(['eventType', 'site:id,name', 'user:id,name'])
                ->where('slug', $slug)
                ->where('status', 'approved')
                ->firstOrFail();

            // Increment view count
            $event->increment('view_count');

            // Log view interaction if authenticated
            if (auth()->check()) {
                EventInteraction::firstOrCreate([
                    'event_id'         => $event->id,
                    'user_id'          => auth()->id(),
                    'interaction_type' => 'view',
                ], [
                    'ip_address' => $request->ip(),
                    'platform'   => $request->header('X-Platform'),
                ]);

                $userId = auth()->id();
                $userInteractions = EventInteraction::where('event_id', $event->id)
                    ->where('user_id', $userId)
                    ->pluck('interaction_type');

                $isFavourited = Favourite::where('favouritable_type', Event::class)
                    ->where('favouritable_id', $event->id)
                    ->where('user_id', $userId)
                    ->exists();

                $event->user_interaction = [
                    'has_liked'     => $userInteractions->contains('like'),
                    'is_going'      => $userInteractions->contains('going'),
                    'is_interested' => $userInteractions->contains('interested'),
                    'has_favourited' => $isFavourited,
                ];
            }

            return $this->sendResponse($event, 'Event fetched successfully');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Create a new event.
     * POST /api/v2/events
     */
    public function store(CreateEventRequest $request)
    {
        try {
            $user  = auth()->user();
            $input = $request->validated();

            // Auto-fill organizer from user profile if not provided
            $input['user_id']         = $user->id;
            $input['organizer_name']  = $input['organizer_name']  ?? $user->name;
            $input['organizer_phone'] = $input['organizer_phone'] ?? ($user->mobile ?? '');
            $input['organizer_email'] = $input['organizer_email'] ?? $user->email;
            $input['status']          = 'pending';

            // Upload banner image to S3
            unset($input['banner_image']);
            if ($request->hasFile('banner_image')) {
                $input['banner_image'] = uploadFile(
                    $request->file('banner_image'),
                    config('constants.upload_path.event_banner')
                )['path'];
            }

            $event = Event::create($input);

            return $this->sendResponse([
                'id'         => $event->id,
                'slug'       => $event->slug,
                'status'     => $event->status,
                'created_at' => $event->created_at,
            ], 'Event submitted for admin approval');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Update an event (owner only).
     * POST /api/v2/updateEvent  — id in body
     */
    public function update(UpdateEventRequest $request)
    {
        try {
            $event = Event::where('id', $request->id)
                ->where('user_id', auth()->id())
                ->firstOrFail();

            if (in_array($event->status, ['cancelled', 'completed'])) {
                return $this->sendError('Cannot update a cancelled or completed event', '', 200);
            }

            $validated = $request->validated();

            // Upload new banner and delete old one from S3
            unset($validated['banner_image']);
            if ($request->hasFile('banner_image')) {
                if ($event->banner_image) {
                    Storage::delete($event->banner_image);
                }
                $validated['banner_image'] = uploadFile(
                    $request->file('banner_image'),
                    config('constants.upload_path.event_banner')
                )['path'];
            }

            $event->update($validated);

            return $this->sendResponse([
                'id'     => $event->id,
                'status' => $event->fresh()->status,
            ], 'Event updated successfully');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Cancel event (owner only).
     * POST /api/v2/cancelEvent  — id in body
     */
    public function cancel(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:events,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        try {
            $event = Event::where('id', $request->id)
                ->where('user_id', auth()->id())
                ->firstOrFail();

            if (in_array($event->status, ['cancelled', 'completed'])) {
                return $this->sendError('Event is already cancelled or completed', '', 200);
            }

            $event->update(['status' => 'cancelled']);

            return $this->sendResponse(null, 'Event cancelled successfully');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Get authenticated user's events.
     * GET /api/v2/events/my-events
     */
    public function myEvents(Request $request)
    {
        try {
            $query = Event::with(['eventType'])
                ->where('user_id', auth()->id());

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $perPage = $request->input('per_page', 15);
            $events  = $query->orderByDesc('created_at')->paginate($perPage);

            return $this->sendResponse($events, 'My events fetched successfully');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Delete event (owner only, only drafts/rejected/cancelled).
     * POST /api/v2/deleteEvent  — id in body
     */
    public function destroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:events,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        try {
            $event = Event::where('id', $request->id)
                ->where('user_id', auth()->id())
                ->firstOrFail();

            if (!in_array($event->status, ['draft', 'rejected', 'cancelled'])) {
                return $this->sendError('Only draft, rejected, or cancelled events can be deleted', '', 200);
            }

            $event->delete();

            return $this->sendResponse(null, 'Event deleted successfully');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }
}
