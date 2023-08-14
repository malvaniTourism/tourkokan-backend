<?php

namespace App\Http\Controllers\API\V1;

use App\Models\Roles;
use Illuminate\Http\Request;
use Validator;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\Log;

class RolesController extends BaseController
{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    // public function __construct() {
    //     $this->middleware('auth:api');
    // }

    public function roleDD()
    {
        try {
            $roles = Roles::whereNotIn('name', ["SuperAdmin", "Admin", "User"])->get();;

            return $this->sendResponse($roles, 'Roles Dropdown');
        } catch (\Throwable $th) {
            throw $th;
            Log::error($th->getMessage());
        }
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $roles = Roles::with('users')->paginate(10);
        return $this->sendResponse($roles, 'Roles successfully Retrieved...!');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|unique:roles|between:2,100',
            'display_name' => 'required|string|unique:roles|between:2,100',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $role = Roles::create($request->all());

        return $this->sendResponse($role, 'Roles added successfully...!');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Roles  $roles
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id)
    {
        $role = Roles::find($id);

        if (is_null($role)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($role, 'Role successfully Retrieved...!');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Roles  $roles
     * @return \Illuminate\Http\Response
     */
    public function getAllUsers(Request $request, $id)
    {
        $users = Roles::find($id)->users;

        if (is_null($users)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($users, 'Role successfully Retrieved...!');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Roles  $roles
     * @return \Illuminate\Http\Response
     */
    public function edit(Roles $roles)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Roles  $roles
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|between:2,100',
            'display_name' => 'required|string|between:2,100',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $role = Roles::find($id);

        if (is_null($role)) {
            return $this->sendError('Empty', [], 404);
        }

        $role->update($request->all());

        return $this->sendResponse($role, 'Roles updated successfully...!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Roles  $roles
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $id)
    {
        $role = Roles::find($id);

        if (is_null($role)) {
            return $this->sendError('Empty', [], 404);
        }

        $role->delete($request->all());

        return $this->sendResponse($role, 'Roles deleted successfully...!');
    }
}
