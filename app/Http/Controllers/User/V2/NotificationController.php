<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController;
use App\Models\PushNotificationToken;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class NotificationController extends BaseController
{
    private FirebaseService $firebase;

    public function __construct(FirebaseService $firebase)
    {
        $this->firebase = $firebase;
    }

    /**
     * Register or refresh a push notification token.
     * POST /api/v2/registerPushToken
     */
    public function registerToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token'       => 'required|string',
            'device_type' => 'required|in:android,ios,web',
            'device_id'   => 'nullable|string',
            'device_name' => 'nullable|string',
            'app_version' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        try {
            $user = auth()->user();

            // Upsert: match on user+token, update metadata
            PushNotificationToken::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'token'   => $request->token,
                ],
                [
                    'device_type' => $request->device_type,
                    'device_id'   => $request->device_id,
                    'device_name' => $request->device_name,
                    'app_version' => $request->app_version,
                    'is_active'   => true,
                ]
            );

            return $this->sendResponse(null, 'Push token registered successfully');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Unregister a push notification token (logout from device).
     * POST /api/v2/unregisterPushToken
     */
    public function unregisterToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        try {
            PushNotificationToken::where('user_id', auth()->id())
                ->where('token', $request->token)
                ->update(['is_active' => false]);

            return $this->sendResponse(null, 'Push token unregistered successfully');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Get all registered devices for the authenticated user.
     * POST /api/v2/getDevices
     */
    public function getDevices(Request $request)
    {
        try {
            $devices = PushNotificationToken::where('user_id', auth()->id())
                ->where('is_active', true)
                ->select('id', 'device_type', 'device_id', 'device_name', 'app_version', 'created_at', 'updated_at')
                ->orderByDesc('updated_at')
                ->get();

            return $this->sendResponse($devices, 'Devices fetched successfully');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }

    /**
     * Send a test notification to the authenticated user's devices.
     * POST /api/v2/testNotification
     */
    public function testNotification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title'   => 'nullable|string|max:255',
            'message' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        try {
            $user = auth()->user();

            $notification = [
                'title' => $request->input('title', 'Test Notification'),
                'body'  => $request->input('message', 'This is a test notification from TourKokan'),
                'sound' => 'default',
            ];

            $data = [
                'type'   => 'test',
                'userId' => (string) $user->id,
            ];

            $result = $this->firebase->sendToUser($user->id, $notification, $data);

            return $this->sendResponse($result, 'Test notification sent');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            throw $th;
        }
    }
}
