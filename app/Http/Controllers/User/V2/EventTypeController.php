<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController;
use App\Models\EventType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class EventTypeController extends BaseController
{
    /**
     * Event types dropdown for users (active only).
     * POST /api/v2/eventTypeDD
     *
     * Filters (all optional):
     *   search      – matches name, mr_name, or code
     *   is_hot_type – boolean, hot/popular types only
     *   top_level   – boolean, root types only (no parent)
     *   parent_id   – integer, subtypes of a specific parent
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search'      => 'nullable|string|max:100',
            'is_hot_type' => 'nullable|boolean',
            'top_level'   => 'nullable|boolean',
            'parent_id'   => 'nullable|integer|exists:event_types,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        try {
            $query = EventType::where('status', true);

            if ($request->filled('search')) {
                $s = $request->search;
                $query->where(function ($q) use ($s) {
                    $q->where('name', 'LIKE', "%{$s}%")
                      ->orWhere('mr_name', 'LIKE', "%{$s}%")
                      ->orWhere('code', 'LIKE', "%{$s}%");
                });
            }

            if ($request->filled('is_hot_type')) {
                $query->where('is_hot_type', $request->boolean('is_hot_type'));
            }

            if ($request->boolean('top_level')) {
                $query->whereNull('parent_id');
            } elseif ($request->filled('parent_id')) {
                $query->where('parent_id', $request->parent_id);
            }

            $types = $query->orderBy('name')
                ->get(['id', 'parent_id', 'name', 'mr_name', 'code', 'icon', 'is_hot_type']);

            return $this->sendResponse($types, 'Event types fetched successfully');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            return $this->sendError('Something went wrong', '', 200);
        }
    }
}
