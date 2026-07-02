<?php

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\BaseController;
use App\Models\UserRoleRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserRoleRequestController extends BaseController
{
    /**
     * List all role requests with optional status filter.
     * POST /admin/v2/userRoleRequests
     */
    public function index(Request $request)
    {
        $query = UserRoleRequest::with([
            'user:id,name,email',
            'role:id,name,code',
            'reviewer:id,name',
        ])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->whereHas('user', fn($q) => $q->where('name', 'like', '%' . $request->search . '%'));
        }

        return $this->sendResponse(
            $query->paginateSafe(),
            'Role requests fetched.'
        );
    }

    /**
     * Approve a role request — attaches the role to the user.
     * POST /admin/v2/approveRoleRequest
     */
    public function approve(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'         => 'required|exists:user_role_requests,id',
            'admin_note' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $roleRequest = UserRoleRequest::with('user')->find($request->id);

        if ($roleRequest->status !== 'pending') {
            return $this->sendError('Only pending requests can be approved.', '', 422);
        }

        // Attach role if user doesn't already have it
        if (!$roleRequest->user->hasRole($roleRequest->role->code ?? '')) {
            $roleRequest->user->roles()->attach($roleRequest->role_id);
        }

        $roleRequest->update([
            'status'      => 'approved',
            'admin_note'  => $request->admin_note,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return $this->sendResponse(
            $roleRequest->load(['user:id,name,email', 'role:id,name,code', 'reviewer:id,name']),
            'Role request approved. User has been assigned the role.'
        );
    }

    /**
     * Reject a role request with an admin note.
     * POST /admin/v2/rejectRoleRequest
     */
    public function reject(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'         => 'required|exists:user_role_requests,id',
            'admin_note' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $roleRequest = UserRoleRequest::find($request->id);

        if ($roleRequest->status !== 'pending') {
            return $this->sendError('Only pending requests can be rejected.', '', 422);
        }

        $roleRequest->update([
            'status'      => 'rejected',
            'admin_note'  => $request->admin_note,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return $this->sendResponse(
            $roleRequest->load(['user:id,name,email', 'role:id,name,code', 'reviewer:id,name']),
            'Role request rejected.'
        );
    }
}
