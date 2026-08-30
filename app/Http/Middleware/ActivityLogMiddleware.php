<?php

namespace App\Http\Middleware;

use App\Jobs\LogUserActivityJob;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ActivityLogMiddleware
{
    // ── Skip these routes entirely ───────────────────────────────────────────
    private const SKIP_ROUTES = [
        'v2/healthcheck',
        'v2/getAppVersion',
        'admin/login',
        'admin/v2/auth/login',
        'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // On the request, not on $this: Laravel resolves a fresh middleware instance for
        // the terminate phase, so a property set here would read back as 0 there.
        $request->attributes->set('log_start_time', (int) (microtime(true) * 1000));

        return $next($request);
    }

    // terminate() fires AFTER the HTTP response is sent to the client — zero latency impact
    public function terminate(Request $request, Response $response): void
    {
        $path = $request->path();

        foreach (self::SKIP_ROUTES as $skip) {
            if (str_ends_with($path, $skip)) {
                return;
            }
        }

        $startTime      = (int) $request->attributes->get('log_start_time', 0);
        $responseTimeMs = $startTime > 0
            ? (int) (microtime(true) * 1000) - $startTime
            : 0;

        // Parse success from response body
        $success = false;
        try {
            $body    = json_decode($response->getContent(), true) ?? [];
            $success = ($body['success'] ?? false) === true;
        } catch (\Throwable) {}

        // Strip log_ prefix from request attributes so keys match DB columns directly:
        //   log_entity_type  → entity_type
        //   log_entity_id    → entity_id
        //   log_entity_name  → entity_name
        //   log_meta_data    → meta_data
        $logAttrs = collect($request->attributes->all())
            ->only(['log_entity_type', 'log_entity_id', 'log_entity_name', 'log_meta_data'])
            ->mapWithKeys(fn($value, $key) => [str_replace('log_', '', $key) => $value])
            ->filter(fn($value) => !is_null($value))
            ->toArray();

        $payload = array_merge([
            'user_id'          => auth('api')->id(),
            'route'            => '/' . $path,
            'method'           => $request->method(),
            'ip_address'       => $request->ip(),
            'user_agent'       => substr($request->userAgent() ?? '', 0, 300),
            'platform'         => $this->resolvePlatform($request),
            'app_version'      => $request->attributes->get('app_version'),
            'success'          => $success,
            'response_time_ms' => $responseTimeMs,
        ], $logAttrs);

        LogUserActivityJob::dispatch($payload)->onQueue('analytics');
    }

    private function resolvePlatform(Request $request): string
    {
        $ua  = strtolower($request->userAgent() ?? '');
        $src = strtolower($request->header('X-App-Source', ''));

        if ($src === 'mobile' || str_contains($ua, 'okhttp') || str_contains($ua, 'expo')) {
            return 'mobile';
        }

        if ($src === 'admin' || str_contains($request->path(), 'admin')) {
            return 'admin';
        }

        return 'web';
    }
}
