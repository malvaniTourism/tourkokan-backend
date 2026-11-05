<?php

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\BaseController;
use App\Models\AiConfig;
use App\Models\AiLog;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Credits\CreditLedger;
use App\Services\Trip\ItineraryCache;
use App\Services\Trip\SpendGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * AI Trip Planner — the admin side.
 *
 * Four questions, one endpoint each:
 *   overview     is this healthy, and what is it costing me?
 *   logs/getLog  a traveller says their trip was wrong — what happened?
 *   config       tighten something right now, without a deploy
 *   credits      a real user ran out and emailed me
 *
 * The config endpoint earns its place because the backend runs Octane: every
 * change that would otherwise be a deploy means a pull, a restart and a wait.
 * During an abuse spike you want a form submission, not a release.
 */
class AiDashboardController extends BaseController
{
    /** Keys an admin may change from the panel, with how to validate each. */
    private const EDITABLE = [
        'ai_driver'             => 'in:fake,claude',
        'ai_model'              => 'string|max:60',
        'ai_timeout'            => 'integer|min:5|max:120',
        'usd_inr'               => 'numeric|min:1|max:500',
        'candidate_limit'       => 'integer|min:10|max:200',
        'min_cart_places'       => 'integer|min:1|max:20',
        'min_stops_per_day'     => 'integer|min:1|max:10',
        'max_stops_per_day'     => 'integer|min:1|max:12',
        // Per-group ceilings. The lowest that applies wins, so these are
        // reductions on max_stops_per_day rather than a separate scale.
        'pacing_max_family'      => 'integer|min:1|max:12',
        'pacing_max_young_child' => 'integer|min:1|max:12',
        'pacing_max_large_group' => 'integer|min:1|max:12',
        'pacing_young_child_age' => 'integer|min:0|max:17',
        'pacing_large_group_size' => 'integer|min:2|max:60',
        'trip_types'             => 'string|max:255',
        // 0 switches the free-text note box off entirely — the one lever
        // worth having over a field that reaches the model.
        'max_note_chars'         => 'integer|min:0|max:2000',
        'max_days'              => 'integer|min:1|max:30',
        'max_towns'             => 'integer|min:1|max:10',
        'default_radius_km'     => 'numeric|min:5|max:200',
        'interest_parent_codes' => 'string|max:500',
        'free_plans_on_signup'  => 'integer|min:0|max:100',
        'free_plans_referral'   => 'integer|min:0|max:100',
        'rate_limit_per_hour'   => 'integer|min:1|max:1000',
        'rate_limit_per_day'    => 'integer|min:1|max:5000',
        'daily_spend_ceiling'   => 'numeric|min:0|max:100000',
        'cache_enabled'         => 'in:true,false,1,0',
        'cache_ttl_hours'       => 'integer|min:0|max:8760',
    ];

    public function __construct(
        private SpendGuard $spend,
        private ItineraryCache $cache,
        private CreditLedger $credits,
    ) {
    }

    // ── 1. Overview ─────────────────────────────────────────────────────────

    /**
     * POST /admin/v2/ai/overview
     *
     * The daily spend line with its threshold is the point of this screen: it
     * catches an abuse loop or a retry bug in hours rather than at the month's
     * invoice.
     */
    public function overview(Request $request)
    {
        $days = max(1, min(90, (int) $request->input('days', 30)));
        $from = now()->subDays($days - 1)->startOfDay();

        return $this->sendResponse([
            'spend'      => $this->spend->summary(),
            'cache'      => $this->cacheSummary(),
            'daily'      => $this->daily($from),
            'outcomes'   => $this->outcomes($from),
            'failures'   => $this->failures($from),
            'quality'    => $this->quality($from),
            'areas'      => $this->areas($from),
            'window_days' => $days,
        ], 'AI overview.');
    }

    /** Generations, cost and cache hits per day. */
    private function daily($from): array
    {
        return AiLog::where('created_at', '>=', $from)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy(DB::raw('DATE(created_at)'))
            ->get([
                DB::raw('DATE(created_at) as day'),
                DB::raw('COUNT(*) as generations'),
                DB::raw("SUM(status = 'cached') as cached"),
                DB::raw("SUM(status = 'failed') as failed"),
                DB::raw('SUM(cost_inr) as cost_inr'),
            ])
            ->map(fn ($row) => [
                'day'         => (string) $row->day,
                'generations' => (int) $row->generations,
                'cached'      => (int) $row->cached,
                'failed'      => (int) $row->failed,
                'cost_inr'    => round((float) $row->cost_inr, 4),
            ])
            ->all();
    }

    private function outcomes($from): array
    {
        $rows = AiLog::where('created_at', '>=', $from)
            ->groupBy('status')
            ->get(['status', DB::raw('COUNT(*) as runs')])
            ->mapWithKeys(fn ($row) => [$row->status => (int) $row->runs]);

        $total  = (int) $rows->sum();
        $cached = (int) ($rows['cached'] ?? 0);
        $failed = (int) ($rows['failed'] ?? 0);

        return [
            'total'   => $total,
            'success' => (int) ($rows['success'] ?? 0),
            'cached'  => $cached,
            'failed'  => $failed,
            // The hit rate is over everything that was asked, which is the
            // number that moves unit cost.
            'cache_hit_rate' => $total > 0 ? round($cached / $total * 100, 1) : 0.0,
            'failure_rate'   => $total > 0 ? round($failed / $total * 100, 1) : 0.0,
        ];
    }

    /** Why generations failed. Credits returned should be rare. */
    private function failures($from): array
    {
        return AiLog::where('created_at', '>=', $from)
            ->where('status', 'failed')
            ->whereNotNull('failure_reason')
            ->groupBy('failure_reason')
            ->orderByDesc('count')
            ->get(['failure_reason', DB::raw('COUNT(*) as count')])
            ->map(fn ($row) => [
                'reason' => $row->failure_reason,
                'count'  => (int) $row->count,
            ])
            ->all();
    }

    /**
     * Hallucination as a metric rather than a complaint, plus the quality
     * proxies: how many stops a plan ends up with against how many days were
     * asked for.
     */
    private function quality($from): array
    {
        $row = AiLog::where('created_at', '>=', $from)
            ->where('status', '!=', 'failed')
            ->first([
                DB::raw('COUNT(*) as runs'),
                DB::raw('SUM(candidates_sent) as offered'),
                DB::raw('SUM(JSON_LENGTH(COALESCE(dropped_site_ids, JSON_ARRAY()))) as dropped'),
                DB::raw('AVG(valid_sites_used) as avg_stops'),
                DB::raw('AVG(days_generated) as avg_days'),
                DB::raw('AVG(latency_ms) as avg_latency'),
                DB::raw('AVG(tokens_in) as avg_tokens_in'),
                DB::raw('SUM(cache_read_tokens) as prompt_cache_reads'),
            ]);

        $dropped = (int) ($row->dropped ?? 0);
        $used    = (int) ($row->runs ?? 0);

        return [
            'runs'        => $used,
            'dropped_ids' => $dropped,
            // Against stops actually placed, not against the candidate set —
            // the model only ever names a handful of the places it is offered,
            // so dividing by `offered` would flatter it.
            'dropped_per_run' => $used > 0 ? round($dropped / $used, 2) : 0.0,
            'avg_stops'       => round((float) ($row->avg_stops ?? 0), 1),
            'avg_days'        => round((float) ($row->avg_days ?? 0), 1),
            'avg_latency_ms'  => (int) round((float) ($row->avg_latency ?? 0)),
            'avg_tokens_in'   => (int) round((float) ($row->avg_tokens_in ?? 0)),
            // Zero while our frozen prefix is under the model's cacheable
            // minimum. Shown so it is a fact on screen, not an assumption.
            'prompt_cache_reads' => (int) ($row->prompt_cache_reads ?? 0),
        ];
    }

    /**
     * Where travellers actually want to go. This is the number that tells
     * vendor acquisition which taluka to work next.
     */
    private function areas($from): array
    {
        return AiLog::where('created_at', '>=', $from)
            ->whereNotNull('input_filters')
            ->get(['input_filters'])
            ->flatMap(fn ($log) => (array) data_get($log->input_filters, 'town_site_ids', []))
            ->countBy()
            ->sortDesc()
            ->take(15)
            ->pipe(function ($counts) {
                $names = Site::whereIn('id', $counts->keys())->pluck('name', 'id');

                return $counts->map(fn ($trips, $id) => [
                    'site_id' => (int) $id,
                    'name'    => $names[$id] ?? "#{$id}",
                    'trips'   => $trips,
                ])->values()->all();
            });
    }

    private function cacheSummary(): array
    {
        $stats = $this->cache->stats();

        return $stats + ['enabled' => AiConfig::getBool('cache_enabled', true)];
    }

    // ── 2. Generations ──────────────────────────────────────────────────────

    /**
     * POST /admin/v2/ai/logs
     *
     * The list is a way into the detail drawer, which is the real debugging
     * surface — so this stays deliberately thin and leaves the heavy columns
     * (the candidate set, the raw response) to `getLog`.
     */
    public function logs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'status'  => 'nullable|in:success,failed,cached',
            'type'    => 'nullable|in:plan,cart',
            'flagged' => 'nullable|boolean',
            'user_id' => 'nullable|integer',
            'from'    => 'nullable|date',
            'to'      => 'nullable|date|after_or_equal:from',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $logs = AiLog::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->boolean('flagged'), fn ($q) => $q->where('is_flagged', true))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            // Selected before paginating, not passed to it: `paginateSafe`
            // takes no arguments, so a column list handed to it is silently
            // ignored and every column — the raw responses included — loads.
            ->select([
                'id', 'user_id', 'trip_id', 'type', 'status', 'driver', 'model',
                'tokens_in', 'tokens_out', 'cost_inr', 'latency_ms',
                'valid_sites_used', 'days_generated', 'failure_reason',
                'is_flagged', 'admin_reviewed', 'input_filters', 'created_at',
            ])
            ->latest('id')
            ->paginateSafe();

        $logs->getCollection()->transform(fn (AiLog $log) => [
            'id'         => $log->id,
            'user_id'    => $log->user_id,
            'trip_id'    => $log->trip_id,
            'type'       => $log->type,
            'status'     => $log->status,
            'driver'     => $log->driver,
            'model'      => $log->model,
            'tokens'     => ['in' => $log->tokens_in, 'out' => $log->tokens_out],
            'cost_inr'   => (float) $log->cost_inr,
            'latency_ms' => $log->latency_ms,
            'stops'      => $log->valid_sites_used,
            'days'       => $log->days_generated,
            'failure_reason' => $log->failure_reason,
            'flagged'    => (bool) $log->is_flagged,
            'reviewed'   => (bool) $log->admin_reviewed,
            'towns'      => (array) data_get($log->input_filters, 'town_site_ids', []),
            'created_at' => $log->created_at,
        ]);

        return $this->sendResponse($logs, 'AI generation log.');
    }

    /**
     * POST /admin/v2/ai/getLog
     *
     * The five things that make a complaint answerable in a minute: the
     * filters sent, every candidate offered, the raw response before
     * validation, which ids were dropped, and the plan as the traveller saw
     * it. Without them it is guesswork.
     */
    public function getLog(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer|exists:ai_logs,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $log = AiLog::with(['trip:id,title,status,days,base_site_id,itinerary'])
            ->findOrFail($request->integer('id'));

        $dropped = (array) ($log->dropped_site_ids ?? []);

        return $this->sendResponse([
            'id'         => $log->id,
            'status'     => $log->status,
            'type'       => $log->type,
            'created_at' => $log->created_at,
            'user'       => $this->userStub($log->user_id),
            'call'       => [
                'driver'      => $log->driver,
                'model'       => $log->model,
                'tokens_in'   => $log->tokens_in,
                'tokens_out'  => $log->tokens_out,
                'cache_read_tokens'  => $log->cache_read_tokens,
                'cache_write_tokens' => $log->cache_write_tokens,
                'cost_inr'    => (float) $log->cost_inr,
                'latency_ms'  => $log->latency_ms,
                'prompt_hash' => $log->prompt_hash,
            ],
            'filters'    => $log->input_filters,
            'candidates' => [
                'count' => $log->candidates_sent,
                // Named, not just counted: "was the place they wanted even
                // offered?" is the first question worth asking.
                'sites' => $this->candidateStubs($log),
            ],
            'raw_response' => $log->ai_response,
            'dropped'      => [
                'count'    => count($dropped),
                'site_ids' => array_values($dropped),
            ],
            'result' => [
                'stops' => $log->valid_sites_used,
                'days'  => $log->days_generated,
                'failure_reason' => $log->failure_reason,
                'error_message'  => $log->error_message,
            ],
            'itinerary' => $log->trip?->itinerary,
            'moderation' => [
                'flagged'     => (bool) $log->is_flagged,
                'flag_reason' => $log->flag_reason,
                'reviewed'    => (bool) $log->admin_reviewed,
                'reviewed_at' => $log->reviewed_at,
            ],
        ], 'AI generation detail.');
    }

    /**
     * POST /admin/v2/ai/reviewLog — flag a generation, or mark it handled.
     */
    public function review(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'          => 'required|integer|exists:ai_logs,id',
            'flagged'     => 'nullable|boolean',
            'flag_reason' => 'nullable|string|max:500',
            'reviewed'    => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $log = AiLog::findOrFail($request->integer('id'));

        $log->forceFill(array_filter([
            'is_flagged'     => $request->has('flagged') ? $request->boolean('flagged') : null,
            'flag_reason'    => $request->input('flag_reason'),
            'admin_reviewed' => $request->has('reviewed') ? $request->boolean('reviewed') : null,
            'reviewed_by'    => $request->boolean('reviewed') ? auth()->id() : null,
            'reviewed_at'    => $request->boolean('reviewed') ? now() : null,
        ], fn ($v) => $v !== null))->save();

        return $this->sendResponse(['id' => $log->id], 'Updated.');
    }

    // ── 3. Settings ─────────────────────────────────────────────────────────

    /** POST /admin/v2/ai/config — every row, with its bounds, for the form. */
    public function config(Request $request)
    {
        $rows = AiConfig::orderBy('key')->get(['key', 'value', 'description']);

        return $this->sendResponse([
            'config' => $rows->map(fn ($row) => [
                'key'         => $row->key,
                'value'       => $row->value,
                'description' => $row->description,
                // An unlisted key is shown but not editable here: changing it
                // safely needs a rule, and inventing one in the UI is how a
                // model id becomes "claude-haiku" and every call starts
                // failing.
                'editable'    => isset(self::EDITABLE[$row->key]),
            ])->all(),
            'spend' => $this->spend->summary(),
        ], 'AI settings.');
    }

    /**
     * POST /admin/v2/ai/updateConfig
     *
     * One key per call, validated against its own rule. Writing through
     * `AiConfig::set` rather than the query builder is what busts the cache —
     * the values are read through a one-hour cache, so a direct update would
     * appear to do nothing for an hour.
     */
    public function updateConfig(Request $request)
    {
        $key = (string) $request->input('key');

        if (!isset(self::EDITABLE[$key])) {
            return $this->sendError('That setting cannot be changed here.', '', 422);
        }

        $validator = Validator::make($request->all(), [
            'key'   => 'required|string',
            'value' => 'required|' . self::EDITABLE[$key],
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        AiConfig::set($key, $request->input('value'));

        return $this->sendResponse([
            'key'   => $key,
            'value' => AiConfig::get($key),
        ], 'Setting saved.');
    }

    // ── 4. Quota and credits ────────────────────────────────────────────────

    /**
     * POST /admin/v2/ai/userCredits
     *
     * Search is exact on email, mobile or name, because those columns are
     * encrypted and reachable only through their blind index — a LIKE over
     * ciphertext matches nothing, which is worse than refusing.
     */
    public function userCredits(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search'  => 'required_without:user_id|nullable|string|max:150',
            'user_id' => 'required_without:search|nullable|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $user = $request->filled('user_id')
            ? User::find($request->integer('user_id'))
            : $this->findByIdentity(trim((string) $request->input('search')));

        if (!$user) {
            return $this->sendError(
                'No account matches that. Email, mobile and name have to match exactly.',
                '',
                404
            );
        }

        $rows = Wallet::where('user_id', $user->id)
            ->whereIn('bonus_id', DB::table('bonus_types')->where('code', 'trip_credit')->pluck('id'))
            ->latest('id')
            ->limit(100)
            ->get(['id', 'amount', 'description', 'created_at']);

        return $this->sendResponse([
            'user' => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'mobile'    => $user->mobile,
                'verified'  => $user->email_verified_at !== null,
                'joined_at' => $user->created_at,
            ],
            'balance' => $this->credits->balance($user),
            'ledger'  => $rows->map(fn ($row) => [
                'id'          => $row->id,
                'amount'      => (int) $row->amount,
                // grant:signup · grant:referral · grant:admin ·
                // spend:trip:<id> · refund:trip:<id>
                'source'      => $row->description,
                'created_at'  => $row->created_at,
            ])->all(),
            'generations' => AiLog::where('user_id', $user->id)->count(),
        ], 'Trip credits.');
    }

    /**
     * POST /admin/v2/ai/grantCredits
     *
     * The reason is written to the ledger like any other row, so a goodwill
     * grant is as auditable as a signup one.
     */
    public function grantCredits(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'credits' => 'required|integer|min:1|max:100',
            'reason'  => 'required|string|max:60',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $user = User::findOrFail($request->integer('user_id'));

        // Namespaced so it can never be mistaken for a signup or referral
        // grant, and so the farming query below stays meaningful.
        $balance = $this->credits->grant(
            $user,
            $request->integer('credits'),
            'admin:' . str_replace(':', ' ', trim((string) $request->input('reason')))
        );

        return $this->sendResponse([
            'user_id' => $user->id,
            'balance' => $balance,
        ], 'Credits granted.');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Exact match on the blind index, the only search these columns allow. */
    private function findByIdentity(string $term): ?User
    {
        $index = User::makeBlindIndex($term);

        return User::where('email_hash', $index)
            ->orWhere('mobile_hash', $index)
            ->orWhere('name_hash', $index)
            ->first();
    }

    private function userStub(?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $user = User::find($userId);

        return $user ? [
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
        ] : ['id' => $userId, 'name' => null, 'email' => null];
    }

    /**
     * The candidate set, named.
     *
     * The ids are not stored on the log — only the count is, deliberately,
     * since sixty ids per row would dominate the table. What is stored is the
     * dropped ids and the filters, so this names the dropped ones and whatever
     * the plan used. Enough to answer "was it offered", which is the question.
     */
    private function candidateStubs(AiLog $log): array
    {
        $ids = collect((array) ($log->dropped_site_ids ?? []))
            ->merge(collect((array) data_get($log->trip?->itinerary, 'days', []))
                ->flatMap(fn ($day) => collect($day['stops'] ?? [])->pluck('site.id')))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Site::whereIn('id', $ids)
            ->get(['id', 'name', 'submission_status', 'verification_status'])
            ->map(fn (Site $site) => [
                'id'       => $site->id,
                'name'     => $site->name,
                'approved' => $site->submission_status === 'approved',
                'verified' => $site->verification_status === 'verified',
                'dropped'  => in_array($site->id, (array) ($log->dropped_site_ids ?? []), true),
            ])
            ->all();
    }
}
