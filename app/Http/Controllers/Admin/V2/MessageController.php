<?php

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\BaseController;
use App\Models\AdminMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MessageController extends BaseController
{
    /**
     * Send a direct message to a specific user.
     * POST /api/v2/admin/sendMessage
     */
    public function send(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|numeric|exists:users,id',
            'message' => 'required|string|max:2000',
            'subject' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $msg = AdminMessage::create([
            'user_id'  => $request->user_id,
            'admin_id' => auth()->id(),
            'subject'  => $request->subject,
            'message'  => $request->message,
            'is_read'  => false,
        ]);

        $msg->load(['admin:id,name,email', 'user:id,name,email']);

        return $this->sendResponse($msg, 'Message sent successfully.');
    }

    /**
     * List all messages sent by admin, optionally filtered by user.
     * POST /api/v2/admin/listMessages
     */
    public function index(Request $request)
    {
        $query = AdminMessage::with(['user:id,name,email', 'admin:id,name,email'])
            ->latest();

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $messages = $query->paginate($request->input('per_page', 20));

        return $this->sendResponse($messages, 'Messages fetched.');
    }

    /**
     * Delete a message (admin).
     * POST /api/v2/admin/deleteMessage
     */
    public function destroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:admin_messages,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        AdminMessage::where('id', $request->id)->delete();

        return $this->sendResponse(null, 'Message deleted.');
    }
}
