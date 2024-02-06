<?php

namespace App\Http\Controllers\User\V2;

use App\Models\Contact;
use Illuminate\Http\Request;
use Validator;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\BaseController as BaseController;

class ContactController extends BaseController
{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $contacts = Contact::paginate(10);

        return $this->sendResponse($contacts, 'Contacts successfully Retrieved...!');
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
            'user_id' => 'required|numeric|exists:users,id',
            'name' => 'required|string|between:2,100',
            'email' => 'sometimes|string|email|between:2,200',
            'phone' => 'sometimes|numeric',
            'message' => 'required',
            'contactable_type' => 'sometimes|required_with:contactable_id|string',
            'contactable_id' => 'sometimes|required_with:contactable_type|numeric',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $contact = new Contact;

        $contact->user_id = $request->get('user_id');

        $contact->name = $request->get('name');

        $contact->email = $request->get('email');

        $contact->phone = $request->get('phone');

        $contact->message = $request->get('message');

        if ($request->has('contactable_id') && $request->has('contactable_type')) {
            // $data = json_encode(DB::table($request->contactable_type)->find($request->contactable_id));//getData($request->commentable_id, $request->commentable_type);

            $data = getData($request->contactable_id, $request->contactable_type);

            if (!$data) {
                return $this->sendError($request->contactable_type . ' Not Exist..!', '', 400);
            }

            $contact->contactable()->associate($data);
        }

        // $contact = $contact->save();       
        $contact = Contact::create(json_decode($contact, true));

        return $this->sendResponse($contact, 'Query submited successfully...!');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Contact  $contact
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $contact = Contact::find($id);

        if (is_null($contact)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($contact, 'Contact successfully Retrieved...!');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Contact  $contact
     * @return \Illuminate\Http\Response
     */
    public function edit(Contact $contact)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Contact  $contact
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //add polymorphic relationship 
        $validator = Validator::make($request->all(), [
            'user_id' => 'numeric|exists:users,id',
            'name' => 'string|between:2,100',
            'email' => 'sometimes|string|email|between:2,200',
            'phone' => 'sometimes|numeric',
            'message' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $contact = Contact::find($id);

        if (is_null($contact)) {
            return $this->sendError('Empty', [], 404);
        }

        $contact->update($request->all());

        return $this->sendResponse($contact, 'contacts updated successfully...!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Contact  $contact
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $contact = Contact::find($id);

        if (is_null($contact)) {
            return $this->sendError('Empty', [], 404);
        }

        $contact->delete($id);

        return $this->sendResponse($contact, 'contact deleted successfully...!');
    }
}
