<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class HealthCheckController extends BaseController
{
    public function check()
    {
        $results = [];
        $allHealthy = true;

        // ── 1. Database ────────────────────────────────────────────────
        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1');
            $results['database'] = $this->ok('MySQL connected');
        } catch (\Throwable $e) {
            $results['database'] = $this->fail($e->getMessage());
            $allHealthy = false;
        }

        // ── 2. Redis ───────────────────────────────────────────────────
        try {
            $key = 'healthcheck_ping_' . time();
            Cache::store('redis')->put($key, 'pong', 5);
            $val = Cache::store('redis')->get($key);
            Cache::store('redis')->forget($key);

            if ($val === 'pong') {
                $results['redis'] = $this->ok('Redis read/write OK');
            } else {
                $results['redis'] = $this->fail('Redis write succeeded but read returned unexpected value');
                $allHealthy = false;
            }
        } catch (\Throwable $e) {
            $results['redis'] = $this->fail($e->getMessage());
            $allHealthy = false;
        }

        // ── 3. AWS S3 ──────────────────────────────────────────────────
        try {
            $key = 'healthcheck/ping.txt';
            Storage::disk('s3')->put($key, 'ok');
            Storage::disk('s3')->delete($key);
            $results['s3'] = $this->ok('S3 upload/delete OK — bucket: ' . config('filesystems.disks.s3.bucket'));
        } catch (\Throwable $e) {
            $results['s3'] = $this->fail($e->getMessage());
            $allHealthy = false;
        }

        // ── 4. SMTP / Mail ─────────────────────────────────────────────
        try {
            // Just verify the transport can be created (no email actually sent)
            $transport = Mail::mailer()->getSymfonyTransport();
            $transport->start();
            $results['mail'] = $this->ok('SMTP transport connected — host: ' . config('mail.mailers.smtp.host'));
        } catch (\Throwable $e) {
            $results['mail'] = $this->fail($e->getMessage());
            $allHealthy = false;
        }

        // ── 5. MSG91 (SMS) ─────────────────────────────────────────────
        try {
            $authKey = config('services.msg91.auth_key');
            if (empty($authKey)) {
                $results['msg91'] = $this->warn('MSG91_AUTH_KEY not configured');
            } else {
                $response = Http::timeout(5)
                    ->withHeaders(['authkey' => $authKey])
                    ->get('https://api.msg91.com/api/v5/account');

                $results['msg91'] = $response->successful()
                    ? $this->ok('MSG91 reachable — HTTP ' . $response->status())
                    : $this->warn('MSG91 responded HTTP ' . $response->status());
            }
        } catch (\Throwable $e) {
            $results['msg91'] = $this->fail($e->getMessage());
            $allHealthy = false;
        }

        // ── 6. Google Maps Geocoding ───────────────────────────────────
        try {
            $apiKey = config('geocoder.key');
            if (empty($apiKey)) {
                $results['google_maps'] = $this->warn('GOOGLE_MAPS_GEOCODING_API_KEY not configured');
            } else {
                $response = Http::timeout(5)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'address' => 'Mumbai',
                    'key'     => $apiKey,
                ]);

                $body   = $response->json();
                $status = $body['status'] ?? 'UNKNOWN';

                $results['google_maps'] = in_array($status, ['OK', 'ZERO_RESULTS'])
                    ? $this->ok('Google Maps API reachable — status: ' . $status)
                    : $this->fail('Google Maps API error — status: ' . $status);

                if (!in_array($status, ['OK', 'ZERO_RESULTS'])) {
                    $allHealthy = false;
                }
            }
        } catch (\Throwable $e) {
            $results['google_maps'] = $this->fail($e->getMessage());
            $allHealthy = false;
        }

        // ── 7. Firebase FCM ────────────────────────────────────────────
        try {
            $serverKey  = config('services.firebase.server_key');
            $projectId  = config('services.firebase.project_id');

            if (empty($serverKey)) {
                $results['firebase'] = $this->warn('FIREBASE_SERVER_KEY not configured — skipping live check');
            } else {
                $response = Http::timeout(5)
                    ->withHeaders([
                        'Authorization' => 'key=' . $serverKey,
                        'Content-Type'  => 'application/json',
                    ])
                    ->post(config('services.firebase.fcm_url'), [
                        'registration_ids' => ['healthcheck_dry_run'],
                        'dry_run'          => true,
                        'notification'     => ['title' => 'ping'],
                    ]);

                $results['firebase'] = $response->status() !== 401
                    ? $this->ok('Firebase FCM reachable — HTTP ' . $response->status())
                    : $this->fail('Firebase FCM auth failed — invalid server key');

                if ($response->status() === 401) {
                    $allHealthy = false;
                }
            }
        } catch (\Throwable $e) {
            $results['firebase'] = $this->fail($e->getMessage());
            $allHealthy = false;
        }

        return response()->json([
            'success'   => $allHealthy,
            'status'    => $allHealthy ? 'healthy' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'services'  => $results,
        ], $allHealthy ? 200 : 207);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function ok(string $message): array
    {
        return ['status' => 'ok', 'message' => $message];
    }

    private function fail(string $message): array
    {
        return ['status' => 'fail', 'message' => $message];
    }

    private function warn(string $message): array
    {
        return ['status' => 'warn', 'message' => $message];
    }
}
