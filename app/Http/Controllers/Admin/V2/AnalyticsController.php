<?php

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\BaseController;
use App\Models\User;
use App\Models\UserActivityLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AnalyticsController extends BaseController
{
    // ── Raw paginated log list with all filters ──────────────────────────────

    public function activityLogs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'per_page'    => 'sometimes|integer|min:1|max:200',
            'user_id'     => 'sometimes|exists:users,id',
            'event_type'  => 'sometimes|string|max:50',
            'entity_type' => 'sometimes|string|max:50',
            'entity_id'   => 'sometimes|integer',
            'platform'    => 'sometimes|in:mobile,web,admin',
            'success'     => 'sometimes|boolean',
            'date_from'   => 'sometimes|date',
            'date_to'     => 'sometimes|date',
            'ip_address'  => 'sometimes|string|max:45',
            'search'      => 'sometimes|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $logs = UserActivityLog::with('user:id,name,mobile,email,profile_picture')
            ->when($request->filled('user_id'),     fn($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('event_type'),  fn($q) => $q->where('event_type', $request->event_type))
            ->when($request->filled('entity_type'), fn($q) => $q->where('entity_type', $request->entity_type))
            ->when($request->filled('entity_id'),   fn($q) => $q->where('entity_id', $request->entity_id))
            ->when($request->filled('platform'),    fn($q) => $q->where('platform', $request->platform))
            ->when($request->has('success'),        fn($q) => $q->where('success', $request->boolean('success')))
            ->when($request->filled('date_from'),   fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'),     fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->when($request->filled('ip_address'),  fn($q) => $q->where('ip_address', $request->ip_address))
            ->when($request->filled('search'),      fn($q) => $q->where(function ($q2) use ($request) {
                $q2->where('route', 'like', '%' . $request->search . '%')
                   ->orWhere('entity_name', 'like', '%' . $request->search . '%');
            }))
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 50));

        return $this->sendResponse($logs, 'Activity logs retrieved successfully');
    }

    // ── All activity for one user (chronological timeline) ──────────────────

    public function userTimeline(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id'   => 'required|exists:users,id',
            'per_page'  => 'sometimes|integer|min:1|max:100',
            'date_from' => 'sometimes|date',
            'date_to'   => 'sometimes|date',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $user = User::select('id', 'name', 'mobile', 'email', 'profile_picture', 'created_at')
            ->with('roles:id,name,code')
            ->findOrFail($request->user_id);

        $logs = UserActivityLog::where('user_id', $request->user_id)
            ->when($request->filled('date_from'), fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'),   fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 50));

        return $this->sendResponse([
            'user' => $user,
            'logs' => $logs,
        ], 'User timeline retrieved successfully');
    }

    // ── Most visited sites ───────────────────────────────────────────────────

    public function topSites(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit'     => 'sometimes|integer|min:1|max:100',
            'date_from' => 'sometimes|date',
            'date_to'   => 'sometimes|date',
            'platform'  => 'sometimes|in:mobile,web,admin',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $results = UserActivityLog::select(
                'entity_id',
                'entity_name',
                DB::raw('COUNT(*) as view_count'),
                DB::raw('COUNT(DISTINCT user_id) as unique_users')
            )
            ->where('event_type', 'site_view')
            ->whereNotNull('entity_id')
            ->when($request->filled('date_from'), fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'),   fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->when($request->filled('platform'),  fn($q) => $q->where('platform', $request->platform))
            ->groupBy('entity_id', 'entity_name')
            ->orderByDesc('view_count')
            ->limit($request->input('limit', 20))
            ->get();

        return $this->sendResponse($results, 'Top sites retrieved successfully');
    }

    // ── Most viewed events ───────────────────────────────────────────────────

    public function topEvents(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit'     => 'sometimes|integer|min:1|max:100',
            'date_from' => 'sometimes|date',
            'date_to'   => 'sometimes|date',
            'platform'  => 'sometimes|in:mobile,web,admin',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $results = UserActivityLog::select(
                'entity_id',
                'entity_name',
                DB::raw('COUNT(*) as view_count'),
                DB::raw('COUNT(DISTINCT user_id) as unique_users')
            )
            ->whereIn('event_type', ['event_view', 'event_interaction'])
            ->whereNotNull('entity_id')
            ->when($request->filled('date_from'), fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'),   fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->when($request->filled('platform'),  fn($q) => $q->where('platform', $request->platform))
            ->groupBy('entity_id', 'entity_name')
            ->orderByDesc('view_count')
            ->limit($request->input('limit', 20))
            ->get();

        return $this->sendResponse($results, 'Top events retrieved successfully');
    }

    // ── Most searched routes ─────────────────────────────────────────────────

    public function topRoutes(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit'     => 'sometimes|integer|min:1|max:100',
            'date_from' => 'sometimes|date',
            'date_to'   => 'sometimes|date',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        // Aggregated from meta_data source_id + destination_id pairs
        $results = DB::table('user_activity_logs')
            ->select(
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta_data, '$.source_id')) as source_id"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta_data, '$.destination_id')) as destination_id"),
                DB::raw('COUNT(*) as search_count'),
                DB::raw('COUNT(DISTINCT user_id) as unique_users')
            )
            ->where('event_type', 'route_search')
            ->whereNotNull('meta_data')
            ->when($request->filled('date_from'), fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'),   fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->groupBy('source_id', 'destination_id')
            ->orderByDesc('search_count')
            ->limit($request->input('limit', 20))
            ->get();

        return $this->sendResponse($results, 'Top routes retrieved successfully');
    }

    // ── Category interest distribution ───────────────────────────────────────

    public function userInterests(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id'   => 'sometimes|exists:users,id',
            'date_from' => 'sometimes|date',
            'date_to'   => 'sometimes|date',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $results = DB::table('user_activity_logs as ual')
            ->join('category_site as cs', function ($join) {
                $join->on('ual.entity_id', '=', 'cs.site_id')
                     ->where('ual.entity_type', '=', 'site');
            })
            ->join('categories as c', 'cs.category_id', '=', 'c.id')
            ->select(
                'c.id as category_id',
                'c.name as category_name',
                DB::raw('COUNT(*) as visit_count'),
                DB::raw('COUNT(DISTINCT ual.user_id) as unique_users')
            )
            ->whereIn('ual.event_type', ['site_view', 'favourite_toggle'])
            ->when($request->filled('user_id'),   fn($q) => $q->where('ual.user_id', $request->user_id))
            ->when($request->filled('date_from'), fn($q) => $q->whereDate('ual.created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'),   fn($q) => $q->whereDate('ual.created_at', '<=', $request->date_to))
            ->groupBy('c.id', 'c.name')
            ->orderByDesc('visit_count')
            ->get();

        return $this->sendResponse($results, 'User interests retrieved successfully');
    }

    // ── Login / logout / register history ───────────────────────────────────

    public function loginHistory(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id'   => 'sometimes|exists:users,id',
            'per_page'  => 'sometimes|integer|min:1|max:100',
            'date_from' => 'sometimes|date',
            'date_to'   => 'sometimes|date',
            'platform'  => 'sometimes|in:mobile,web,admin',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $logs = UserActivityLog::with('user:id,name,mobile,email')
            ->whereIn('event_type', ['login', 'logout', 'register'])
            ->when($request->filled('user_id'),   fn($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('platform'),  fn($q) => $q->where('platform', $request->platform))
            ->when($request->filled('date_from'), fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'),   fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 50));

        return $this->sendResponse($logs, 'Login history retrieved successfully');
    }

    // ── DAU / WAU / MAU ─────────────────────────────────────────────────────

    public function activeUsers(Request $request)
    {
        $today = Carbon::today();

        $dau = UserActivityLog::whereDate('created_at', $today)
            ->whereNotNull('user_id')->distinct('user_id')->count('user_id');

        $wau = UserActivityLog::where('created_at', '>=', $today->copy()->subDays(7))
            ->whereNotNull('user_id')->distinct('user_id')->count('user_id');

        $mau = UserActivityLog::where('created_at', '>=', $today->copy()->subDays(30))
            ->whereNotNull('user_id')->distinct('user_id')->count('user_id');

        $newUsersToday = UserActivityLog::whereDate('created_at', $today)
            ->where('event_type', 'register')->count();

        $totalEventsToday = UserActivityLog::whereDate('created_at', $today)
            ->whereNotNull('user_id')->count();

        return $this->sendResponse([
            'dau'                       => $dau,
            'wau'                       => $wau,
            'mau'                       => $mau,
            'new_users_today'           => $newUsersToday,
            'avg_events_per_user_today' => $dau > 0 ? round($totalEventsToday / $dau, 1) : 0,
        ], 'Active users retrieved successfully');
    }

    // ── Admin dashboard summary stats ────────────────────────────────────────

    public function dashboardStats(Request $request)
    {
        $today = Carbon::today();

        $loginsToday    = UserActivityLog::whereDate('created_at', $today)->where('event_type', 'login')->count();
        $apiCallsToday  = UserActivityLog::whereDate('created_at', $today)->count();
        $siteViewsToday = UserActivityLog::whereDate('created_at', $today)->where('event_type', 'site_view')->count();
        $eventViewsToday= UserActivityLog::whereDate('created_at', $today)->where('event_type', 'event_view')->count();

        $topEventType = UserActivityLog::whereDate('created_at', $today)
            ->select('event_type', DB::raw('COUNT(*) as cnt'))
            ->groupBy('event_type')
            ->orderByDesc('cnt')
            ->value('event_type');

        $avgResponseTime = (int) UserActivityLog::whereDate('created_at', $today)
            ->whereNotNull('response_time_ms')
            ->avg('response_time_ms');

        $mostActive = UserActivityLog::whereDate('created_at', $today)
            ->whereNotNull('user_id')
            ->select('user_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('user_id')
            ->orderByDesc('cnt')
            ->with('user:id,name')
            ->first();

        return $this->sendResponse([
            'total_logins_today'     => $loginsToday,
            'total_api_calls_today'  => $apiCallsToday,
            'total_site_views_today' => $siteViewsToday,
            'total_event_views_today'=> $eventViewsToday,
            'top_event_type_today'   => $topEventType,
            'avg_response_time_ms'   => $avgResponseTime,
            'most_active_user_today' => $mostActive?->user ? [
                'id'    => $mostActive->user->id,
                'name'  => $mostActive->user->name,
                'count' => $mostActive->cnt,
            ] : null,
        ], 'Dashboard stats retrieved successfully');
    }

    // ── Count breakdown by event_type ────────────────────────────────────────

    public function eventTypeSummary(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_from' => 'sometimes|date',
            'date_to'   => 'sometimes|date',
            'platform'  => 'sometimes|in:mobile,web,admin',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $results = UserActivityLog::select('event_type', DB::raw('COUNT(*) as count'))
            ->when($request->filled('date_from'), fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'),   fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->when($request->filled('platform'),  fn($q) => $q->where('platform', $request->platform))
            ->groupBy('event_type')
            ->orderByDesc('count')
            ->get();

        return $this->sendResponse($results, 'Event type summary retrieved successfully');
    }

    // ── mobile vs web vs admin breakdown ────────────────────────────────────

    public function platformBreakdown(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_from' => 'sometimes|date',
            'date_to'   => 'sometimes|date',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $results = UserActivityLog::select(
                'platform',
                DB::raw('COUNT(*) as count'),
                DB::raw('COUNT(DISTINCT user_id) as unique_users')
            )
            ->when($request->filled('date_from'), fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'),   fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->groupBy('platform')
            ->get();

        return $this->sendResponse($results, 'Platform breakdown retrieved successfully');
    }

    // ── Favourite add/remove history ─────────────────────────────────────────

    public function favouriteActivity(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id'     => 'sometimes|exists:users,id',
            'entity_type' => 'sometimes|in:site,event',
            'per_page'    => 'sometimes|integer|min:1|max:100',
            'date_from'   => 'sometimes|date',
            'date_to'     => 'sometimes|date',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $logs = UserActivityLog::with('user:id,name,mobile,email')
            ->where('event_type', 'favourite_toggle')
            ->when($request->filled('user_id'),     fn($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('entity_type'), fn($q) => $q->where('entity_type', $request->entity_type))
            ->when($request->filled('date_from'),   fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'),     fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 50));

        return $this->sendResponse($logs, 'Favourite activity retrieved successfully');
    }
}
