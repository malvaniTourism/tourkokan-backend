<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController as BaseController;
use App\Models\Banner;
use App\Models\BannerEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Advertising performance capture.
 *
 * Both endpoints mirror `CatalogController::recordProductView` deliberately — same payload
 * shape, same fire-and-forget contract, same envelope — so the app reports a banner exactly
 * the way it already reports a product.
 *
 * **Impressions are counted once per session, per placement, per day.** Packages are sold at
 * a fixed price rather than on CPM, so an impression is proof of delivery, not a billing
 * unit; the number an advertiser understands is "how many distinct people saw my ad", and
 * that figure cannot be inflated by an auto-playing carousel looping its slides. Clicks are
 * never deduplicated — a second tap is genuine repeat interest.
 *
 * Changing that rule is a one-line change to {@see self::impressionKey()}.
 *
 * See docs/banner-tracking-backend-ask.md §1.
 */
class BannerController extends BaseController
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * POST /api/v2/recordBannerImpression  { id, placement?, platform? }
     *
     * Fire when the creative actually becomes visible. Safe to call repeatedly — a repeat
     * within the same session, placement and day is accepted and ignored.
     */
    public function recordBannerImpression(Request $request)
    {
        return $this->record($request, 'impression');
    }

    /**
     * POST /api/v2/recordBannerClick  { id, placement?, platform? }
     *
     * Fire on tap, before handing off to the browser, so the click survives a failed
     * `openURL`.
     */
    public function recordBannerClick(Request $request)
    {
        return $this->record($request, 'click');
    }

    // ── Internals ────────────────────────────────────────────────────────────────

    private function record(Request $request, string $type)
    {
        $validator = Validator::make($request->all(), [
            'id'        => 'required|numeric|exists:banners,id',
            'placement' => 'nullable|string|max:60',
            'platform'  => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        // Only a live banner accrues numbers. An expired or unpublished creative must not
        // keep reporting, or an advertiser is shown activity from outside their campaign.
        $banner = Banner::active()->find($request->id);

        if (!$banner) {
            return $this->sendError('Banner not found.', '', 404);
        }

        $placement = $request->placement;

        $key = $type === 'impression'
            ? $this->impressionKey($banner->id, $placement, $this->sessionHash($request))
            : (string) Str::uuid();

        // A duplicate impression is a normal, expected call from a carousel — not an error.
        if ($type === 'impression' && BannerEvent::where('dedupe_key', $key)->exists()) {
            return $this->sendResponse(['id' => $banner->id, 'counted' => false], 'Already recorded.');
        }

        try {
            DB::transaction(function () use ($request, $banner, $type, $placement, $key) {
                BannerEvent::create([
                    'banner_id'      => $banner->id,
                    'user_id'        => auth()->id(),
                    'event_type'     => $type,
                    'placement_code' => $placement,
                    'session_hash'   => $this->sessionHash($request),
                    'platform'       => $request->platform,
                    'ip_hash'        => $this->ipHash($request),
                    'dedupe_key'     => $key,
                ]);

                // Denormalised for fast display on the banner row; banner_events stays the
                // reporting source.
                $banner->increment($type === 'impression' ? 'impressions' : 'clicks');
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Two devices racing the same first impression — the unique index is the
            // authority, and losing the race is a success from the caller's point of view.
            if ($this->isDuplicate($e)) {
                return $this->sendResponse(['id' => $banner->id, 'counted' => false], 'Already recorded.');
            }

            throw $e;
        }

        return $this->sendResponse(['id' => $banner->id, 'counted' => true], 'Recorded.');
    }

    /**
     * One impression per banner, per placement, per session, per day.
     *
     * Drop the date to make it once per session for the whole campaign; drop the session to
     * count every view. Whoever prices the packages owns this line.
     */
    private function impressionKey(int $bannerId, ?string $placement, string $session): string
    {
        return hash('sha256', implode('|', [
            $bannerId,
            $placement ?? '-',
            $session,
            now()->toDateString(),
        ]));
    }

    private function isDuplicate(\Illuminate\Database\QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    /**
     * Stable per person+device, and reversible by nobody — same construction as the product
     * metering pipeline uses.
     */
    private function sessionHash(Request $request): string
    {
        return hash_hmac(
            'sha256',
            (auth()->id() ?? 'guest') . '|' . $request->ip() . '|' . $request->userAgent(),
            config('app.key')
        );
    }

    private function ipHash(Request $request): ?string
    {
        return $request->ip() ? hash_hmac('sha256', $request->ip(), config('app.key')) : null;
    }
}
