<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController;
use App\Models\Event;
use App\Models\EventInteraction;
use App\Models\Favourite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EventInteractionController extends BaseController
{
    /**
     * Toggle like on an event.
     * POST /api/v2/likeEvent  — id in body
     */
    public function like(Request $request)
    {
        return $this->toggle($request, $request->input('id'), 'like', 'like_count', 'liked');
    }

    /**
     * Toggle going on an event.
     * POST /api/v2/goingEvent  — id in body
     */
    public function going(Request $request)
    {
        return $this->toggle($request, $request->input('id'), 'going', 'going_count', 'is_going');
    }

    /**
     * Toggle interested on an event.
     * POST /api/v2/interestedEvent  — id in body
     */
    public function interested(Request $request)
    {
        return $this->toggle($request, $request->input('id'), 'interested', 'interested_count', 'is_interested');
    }

    /**
     * Toggle favourite using existing favourites table.
     * POST /api/v2/favouriteEvent  — id in body
     */
    public function favourite(Request $request)
    {
        $id = $request->input('id');
        try {
            $event = Event::where('id', $id)->where('status', 'approved')->firstOrFail();
            $user  = auth()->user();

            $existing = Favourite::where('favouritable_type', Event::class)
                ->where('favouritable_id', $id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing) {
                $existing->delete();
                $event->decrement('favourite_count');
                $favourited = false;
            } else {
                Favourite::create([
                    'user_id'          => $user->id,
                    'favouritable_type' => Event::class,
                    'favouritable_id'   => $id,
                ]);
                $event->increment('favourite_count');
                $favourited = true;
            }

            return $this->sendResponse([
                'favourited'      => $favourited,
                'favourite_count' => $event->fresh()->favourite_count,
            ], $favourited ? 'Added to favourites' : 'Removed from favourites');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Record a share interaction.
     * POST /api/v2/shareEvent  — id in body
     */
    public function share(Request $request)
    {
        $id = $request->input('id');
        try {
            $event = Event::where('id', $id)->where('status', 'approved')->firstOrFail();
            $user  = auth()->user();

            // Shares can be recorded multiple times (no unique constraint needed)
            EventInteraction::create([
                'event_id'         => $id,
                'user_id'          => $user->id,
                'interaction_type' => 'share',
                'device_type'      => $request->input('device_type'),
                'platform'         => $request->input('platform'),
                'ip_address'       => $request->ip(),
            ]);

            $event->increment('share_count');

            return $this->sendResponse([
                'share_count' => $event->fresh()->share_count,
            ], 'Share recorded');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Generic toggle handler for like / going / interested.
     */
    private function toggle(Request $request, $id, string $type, string $countColumn, string $responseKey)
    {
        if (!$id) return $this->sendError('Event id is required', '', 200);
        try {
            $event = Event::where('id', $id)->where('status', 'approved')->firstOrFail();
            $user  = auth()->user();

            $interaction = EventInteraction::where([
                'event_id'         => $id,
                'user_id'          => $user->id,
                'interaction_type' => $type,
            ])->first();

            if ($interaction) {
                $interaction->delete();
                $event->decrement($countColumn);
                $active = false;
            } else {
                EventInteraction::create([
                    'event_id'         => $id,
                    'user_id'          => $user->id,
                    'interaction_type' => $type,
                    'device_type'      => $request->input('device_type'),
                    'platform'         => $request->input('platform'),
                    'ip_address'       => $request->ip(),
                ]);
                $event->increment($countColumn);
                $active = true;
            }

            return $this->sendResponse([
                $responseKey  => $active,
                $countColumn  => $event->fresh()->{$countColumn},
            ], ucfirst($type) . ' updated');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }
}
