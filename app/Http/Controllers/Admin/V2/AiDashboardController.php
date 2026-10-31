<?php

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\BaseController;
use Illuminate\Http\Request;

/**
 * AI Trip Planner — admin monitoring dashboard.
 *
 * SCAFFOLD: routes wired; the aggregation queries over `ai_logs`
 * (success rate, avg latency, flagged items, hallucination rate, popular
 * categories) and the flagged-log review action land in the next phase.
 * Every method currently returns HTTP 501.
 */
class AiDashboardController extends BaseController
{
    /** GET /admin/api/ai-dashboard — health summary + flagged items + errors. */
    public function dashboard(Request $request)
    {
        return $this->sendError('AI dashboard is not implemented yet (Phase 1 scaffold).', [], 501);
    }

    /** POST /admin/api/ai-logs/{id}/review — triage a flagged AI log. */
    public function review(Request $request, $id)
    {
        return $this->sendError('AI log review is not implemented yet (Phase 1 scaffold).', [], 501);
    }
}
