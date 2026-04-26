<?php

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\BaseController;
use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CommentController extends BaseController
{
    /**
     * List pending comments (status = false).
     * POST /api/v2/admin/pendingComments
     */
    public function pending(Request $request)
    {
        $comments = Comment::where('status', false)
            ->with([
                'users:id,name,email',
                'commentable:id,name,user_id',
            ])
            ->latest()
            ->paginate($request->input('per_page', 20));

        return $this->sendResponse($comments, 'Pending comments fetched.');
    }

    /**
     * List all comments (any status) with optional filters.
     * POST /api/v2/admin/listComments
     */
    public function index(Request $request)
    {
        $query = Comment::with(['users:id,name,email', 'commentable:id,name,user_id'])
            ->latest();

        if ($request->has('status')) {
            $query->where('status', (bool) $request->input('status'));
        }

        if ($request->filled('commentable_type') && $request->filled('commentable_id')) {
            $query->where('commentable_type', 'App\\Models\\' . $request->commentable_type)
                  ->where('commentable_id', $request->commentable_id);
        }

        $comments = $query->paginate($request->input('per_page', 20));

        return $this->sendResponse($comments, 'Comments fetched.');
    }

    /**
     * Approve a comment — set status = true.
     * POST /api/v2/admin/approveComment
     */
    public function approve(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:comments,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        Comment::where('id', $request->id)->update(['status' => true]);

        return $this->sendResponse(null, 'Comment approved.');
    }

    /**
     * Reject (delete) a comment.
     * POST /api/v2/admin/rejectComment
     */
    public function reject(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:comments,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        Comment::where('id', $request->id)->delete();

        return $this->sendResponse(null, 'Comment rejected and deleted.');
    }
}
