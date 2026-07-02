<?php

namespace App\Services;

use App\Models\PushNotificationToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FirebaseService
{
    private ?string $serverKey;
    private ?string $fcmUrl;

    public function __construct()
    {
        $this->serverKey = config('services.firebase.server_key');
        $this->fcmUrl    = config('services.firebase.fcm_url');
    }

    /**
     * Send notification to a single device token.
     */
    public function sendToDevice(string $token, array $notification, array $data = []): array
    {
        $payload = [
            'to'           => $token,
            'notification' => $notification,
            'data'         => $data,
            'priority'     => 'high',
        ];

        return $this->send($payload);
    }

    /**
     * Send notification to multiple device tokens (up to 1000).
     */
    public function sendToMultipleDevices(array $tokens, array $notification, array $data = []): array
    {
        $chunks  = array_chunk($tokens, 1000);
        $results = [];

        foreach ($chunks as $chunk) {
            $payload = [
                'registration_ids' => $chunk,
                'notification'     => $notification,
                'data'             => $data,
                'priority'         => 'high',
            ];

            $results[] = $this->send($payload);
        }

        return $results;
    }

    /**
     * Send notification to a FCM topic.
     */
    public function sendToTopic(string $topic, array $notification, array $data = []): array
    {
        $payload = [
            'to'           => "/topics/{$topic}",
            'notification' => $notification,
            'data'         => $data,
            'priority'     => 'high',
        ];

        return $this->send($payload);
    }

    /**
     * Send to all tokens of a specific user.
     */
    public function sendToUser(int $userId, array $notification, array $data = []): array
    {
        $tokens = PushNotificationToken::where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('token')
            ->toArray();

        if (empty($tokens)) {
            return ['success' => false, 'message' => 'No active tokens for user'];
        }

        return $this->sendToMultipleDevices($tokens, $notification, $data);
    }

    /**
     * Remove invalid tokens returned by FCM.
     */
    public function cleanupInvalidTokens(array $invalidTokens): void
    {
        if (empty($invalidTokens)) return;

        PushNotificationToken::whereIn('token', $invalidTokens)
            ->update(['is_active' => false]);

        Log::info('FirebaseService: deactivated ' . count($invalidTokens) . ' invalid tokens');
    }

    /**
     * Core HTTP send method.
     */
    private function send(array $payload): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'key=' . $this->serverKey,
                'Content-Type'  => 'application/json',
            ])->post($this->fcmUrl, $payload);

            $body = $response->json();

            // Cleanup invalid tokens if multicast response
            if (isset($body['results'])) {
                $tokens        = $payload['registration_ids'] ?? [];
                $invalidTokens = [];

                foreach ($body['results'] as $index => $result) {
                    if (isset($result['error']) && in_array($result['error'], [
                        'NotRegistered',
                        'InvalidRegistration',
                    ])) {
                        $invalidTokens[] = $tokens[$index] ?? null;
                    }
                }

                $this->cleanupInvalidTokens(array_filter($invalidTokens));
            }

            return [
                'success'  => $response->successful(),
                'status'   => $response->status(),
                'response' => $body,
            ];
        } catch (\Throwable $th) {
            Log::error('FirebaseService send error: ' . $th->getMessage());
            return ['success' => false, 'message' => $th->getMessage()];
        }
    }
}
