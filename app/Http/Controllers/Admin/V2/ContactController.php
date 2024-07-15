<?php

namespace App\Http\Controllers\Admin\V2;

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
        $contacts = Contact::with([
            'user:id,name',
            'contactable:id,name,parent_id,category_id',
            'contactable.category:id,name'
        ])
            ->paginate(request()->per_page);

        return $this->sendResponse($contacts, 'Contacts successfully Retrieved...!');
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
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Contact  $contact
     * @return \Illuminate\Http\Response
     */
    public function updateQuery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:contacts,id',
            'status' => 'required|string|max:255|in:read,unread',
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
}
