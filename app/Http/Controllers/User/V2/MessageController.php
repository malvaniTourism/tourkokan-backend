<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController;
use App\Models\AdminMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MessageController extends BaseController
{
    /**
     * Get inbox messages for the authenticated user.
     * POST /api/v2/myMessages
     */
    public function inbox(Request $request)
    {
        $messages = AdminMessage::where('user_id', auth()->id())
            ->with(['admin:id,name'])
            ->latest()
            ->paginate($request->input('per_page', 20));

        return $this->sendResponse($messages, 'Inbox fetched.');
    }

    /**
     * Mark a message as read.
     * POST /api/v2/readMessage
     */
    public function markRead(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:admin_messages,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        AdminMessage::where('id', $request->id)
            ->where('user_id', auth()->id())
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return $this->sendResponse(null, 'Message marked as read.');
    }

    /**
     * Get unread message count for the authenticated user.
     * POST /api/v2/unreadMessageCount
     */
    public function unreadCount()
    {
        $count = AdminMessage::where('user_id', auth()->id())
            ->where('is_read', false)
            ->count();

        return $this->sendResponse(['count' => $count], 'Unread count fetched.');
    }
}
