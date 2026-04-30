<?php

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Event\CreateEventRequest;
use App\Http\Requests\Event\UpdateEventRequest;
use App\Models\Event;
use App\Models\EventNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EventController extends BaseController
{
    /**
     * List all events with filters (admin view).
     * POST /api/v2/admin/events
     */
    public function index(Request $request)
    {
        try {
            $query = Event::with(['user:id,name,email', 'eventType:id,name', 'site:id,name']);

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('taluka')) {
                $query->where('taluka', $request->taluka);
            }

            if ($request->filled('event_type_id')) {
                $query->where('event_type_id', $request->event_type_id);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'LIKE', "%{$search}%")
                      ->orWhere('organizer_name', 'LIKE', "%{$search}%");
                });
            }

            $perPage = $request->input('per_page', 20);
            $events  = $query->orderByDesc('created_at')->paginate($perPage);

            return $this->sendResponse($events, 'Events fetched successfully');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Get single event detail (admin view).
     * POST /api/v2/admin/getEvent  — id in body
     */
    public function show(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:events,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        try {
            $event = Event::with([
                'user:id,name,email,mobile',
                'eventType:id,name,code',
                'site:id,name',
                'approvedBy:id,name',
            ])->findOrFail($request->id);

            return $this->sendResponse($event, 'Event fetched successfully');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Admin creates an event (auto-approved, no pending).
     * POST /api/v2/admin/createEvent
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), array_merge(
            (new CreateEventRequest())->rules(),
            [
                'user_id'          => 'nullable|exists:users,id',
                'organizer_phone'  => 'required|string|max:20',
            ]
        ));

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        try {
            $input = $validator->validated();

            $input['status']      = 'approved';
            $input['approved_by'] = auth()->id();
            $input['approved_at'] = now();

            if (!empty($input['user_id'])) {
                $user = User::find($input['user_id']);
                $input['organizer_name']  = $user->name;
                $input['organizer_phone'] = $input['organizer_phone'] ?? $user->mobile;
                $input['organizer_email'] = $input['organizer_email'] ?? $user->email;
            }

            $event = Event::create($input);

            return $this->sendResponse(
                $event->load(['eventType:id,name', 'site:id,name', 'user:id,name,email']),
                'Event created and published successfully'
            );
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Admin updates any event.
     * POST /api/v2/admin/updateEvent  — id in body
     */
    public function update(UpdateEventRequest $request)
    {
        try {
            $event = Event::findOrFail($request->id);
            $event->update($request->validated());

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
     * Admin deletes any event (any status).
     * POST /api/v2/admin/deleteEvent  — id in body
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
            $event = Event::findOrFail($request->id);
            $event->delete();

            return $this->sendResponse(null, 'Event deleted successfully');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * List pending events awaiting approval.
     * GET /api/v2/admin/events/pending
     */
    public function pending(Request $request)
    {
        try {
            $events = Event::with(['user:id,name,email', 'eventType:id,name', 'site:id,name'])
                ->pending()
                ->orderBy('created_at')
                ->paginate($request->input('per_page', 20));

            return $this->sendResponse($events, 'Pending events fetched');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Approve an event.
     * POST /api/v2/admin/approveEvent  — id in body
     */
    public function approve(Request $request)
    {
        $id = $request->input('id');
        $validator = Validator::make($request->all(), [
            'id'               => 'required|exists:events,id',
            'admin_notes'      => 'nullable|string',
            'is_featured'      => 'nullable|boolean',
            'featured_until'   => 'nullable|date',
            'send_notification'=> 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        try {
            DB::beginTransaction();

            $event = Event::findOrFail($id);

            if ($event->status === 'approved') {
                return $this->sendError('Event is already approved', '', 200);
            }

            $event->update([
                'status'         => 'approved',
                'approved_by'    => auth()->id(),
                'approved_at'    => now(),
                'admin_notes'    => $request->admin_notes,
                'is_featured'    => $request->input('is_featured', false),
                'featured_until' => $request->featured_until,
            ]);

            // Schedule notification if requested
            if ($request->input('send_notification', false)) {
                EventNotification::create([
                    'event_id'        => $event->id,
                    'type'            => 'new_event',
                    'target_audience' => 'all',
                    'target_taluka'   => $event->taluka,
                    'title'           => "New Event: {$event->title}",
                    'body'            => "A new event is happening in {$event->taluka}. Check it out!",
                    'scheduled_at'    => now(),
                    'status'          => 'pending',
                ]);
            }

            DB::commit();

            return $this->sendResponse([
                'id'          => $event->id,
                'status'      => $event->status,
                'approved_by' => auth()->user()->name,
                'approved_at' => $event->approved_at,
            ], 'Event approved successfully');
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Reject an event.
     * POST /api/v2/admin/rejectEvent  — id in body
     */
    public function reject(Request $request)
    {
        $id = $request->input('id');
        $validator = Validator::make($request->all(), [
            'id'               => 'required|exists:events,id',
            'rejection_reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        try {
            $event = Event::findOrFail($id);

            if (in_array($event->status, ['cancelled', 'completed'])) {
                return $this->sendError('Cannot reject a cancelled or completed event', '', 200);
            }

            $event->update([
                'status'           => 'rejected',
                'rejection_reason' => $request->rejection_reason,
                'approved_by'      => null,
                'approved_at'      => null,
            ]);

            return $this->sendResponse([
                'id'               => $event->id,
                'status'           => $event->status,
                'rejection_reason' => $event->rejection_reason,
            ], 'Event rejected');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Feature / unfeature an event.
     * POST /api/v2/admin/featureEvent  — id in body
     */
    public function feature(Request $request)
    {
        $id = $request->input('id');
        $validator = Validator::make($request->all(), [
            'id'             => 'required|exists:events,id',
            'is_featured'    => 'required|boolean',
            'featured_until' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        try {
            $event = Event::findOrFail($id);
            $event->update([
                'is_featured'    => $request->is_featured,
                'featured_until' => $request->featured_until,
            ]);

            return $this->sendResponse(null, $request->is_featured ? 'Event featured' : 'Event unfeatured');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Event analytics summary.
     * POST /api/v2/admin/eventAnalytics  — id in body
     */
    public function analytics(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:events,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        try {
            $event = Event::with('interactions')->findOrFail($request->id);

            $breakdown = $event->interactions()
                ->selectRaw('interaction_type, COUNT(*) as count')
                ->groupBy('interaction_type')
                ->pluck('count', 'interaction_type');

            return $this->sendResponse([
                'event_id'    => $event->id,
                'title'       => $event->title,
                'status'      => $event->status,
                'view_count'       => $event->view_count,
                'click_count'      => $event->click_count,
                'share_count'      => $event->share_count,
                'like_count'       => $event->like_count,
                'favourite_count'  => $event->favourite_count,
                'going_count'      => $event->going_count,
                'interested_count' => $event->interested_count,
                'interaction_breakdown' => $breakdown,
            ], 'Analytics fetched');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }
}
