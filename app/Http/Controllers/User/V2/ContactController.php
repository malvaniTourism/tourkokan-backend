<?php

namespace App\Http\Controllers\User\V2;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\BaseController as BaseController;

class ContactController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function getQueries()
    {
        $contacts = Contact::where('user_id', config('user_id'))
            ->paginate(10);

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
    public function addQuery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|between:2,100',
            'email' => 'sometimes|string|email|between:2,200',
            'phone' => 'sometimes|nullable|numeric',
            'message' => 'required',
            'contactable_type' => 'sometimes|required_with:contactable_id|string',    //query for products and other
            'contactable_id' => 'sometimes|required_with:contactable_type|numeric',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $contact = array(
            'user_id' => config('user_id') == null ? config('user_id') : null,
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'message' => $request->message,
        );

        if ($request->has(['contactable_type', 'contactable_type'])) {

            $data = getData($request->contactable_id, $request->contactable_type);

            if (!$data) {
                return $this->sendError($request->contactable_type . ' Not Exist..!', '', 400);
            }

            $contact =  $data->contacts()->create($contact);

            return $this->sendResponse($contact, 'Query submited successfully...!');
        }

        $contact = Contact::create($contact);

        return $this->sendResponse($contact, 'Query submited successfully...!');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Contact  $contact
     * @return \Illuminate\Http\Response
     */
    public function getQuery(Request $request)
    {
        $contact = Contact::find($request->id);

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
    public function updateQuery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|between:2,100',
            'email' => 'sometimes|string|email|between:2,200',
            'phone' => 'sometimes|numeric',
            'message' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $contact = Contact::find($request->id);

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
    public function deleteQuery(Request $request)
    {
        $contact = Contact::find($request->id);

        if (is_null($contact)) {
            return $this->sendError('Empty', [], 404);
        }

        $contact->delete($request->id);

        return $this->sendResponse($contact, 'contact deleted successfully...!');
    }
}
