<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController;
use App\Models\Roles;
use App\Models\UserRoleRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserRoleRequestController extends BaseController
{
    /**
     * Submit a request to be assigned an additional role.
     * POST /api/v2/requestRole
     *
     * Validation: 1 pending request per role per user at a time.
     * User cannot request a role they already have.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'role_code' => 'required|string|exists:roles,code',
            'reason'    => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $user = auth()->user();
        $role = Roles::where('code', $request->role_code)->first();

        // Roles that cannot be self-requested
        $restricted = ['superadmin', 'admin'];
        if (in_array($role->code, $restricted)) {
            return $this->sendError('You cannot request this role.', '', 403);
        }

        // User already has this role
        if ($user->hasRole($role->code)) {
            return $this->sendError("You already have the '{$role->name}' role.", '', 422);
        }

        // One pending request per role — application-level check
        $existing = UserRoleRequest::where('user_id', $user->id)
            ->where('role_id', $role->id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return $this->sendError(
                "You already have a pending request for the '{$role->name}' role. Please wait for admin review.",
                '',
                422
            );
        }

        $roleRequest = UserRoleRequest::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'reason'  => $request->reason,
            'status'  => 'pending',
        ]);

        return $this->sendResponse(
            $roleRequest->load('role:id,name,code'),
            "Your request for the '{$role->name}' role has been submitted and is pending admin review."
        );
    }

    /**
     * List the authenticated user's role requests.
     * GET /api/v2/myRoleRequests
     */
    public function index(Request $request)
    {
        $requests = UserRoleRequest::where('user_id', auth()->id())
            ->with('role:id,name,code')
            ->latest()
            ->paginateSafe();

        return $this->sendResponse($requests, 'Role requests fetched.');
    }
}
