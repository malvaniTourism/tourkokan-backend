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
        $counts = Contact::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $contacts = Contact::with([
            'user:id,name',
            'adminUser:id,name',
            'contactable:id,name,parent_id',
            'contactable.categories:id,name'
        ])
            ->when(request()->status, fn($q) => $q->where('status', request()->status))
            ->orderBy('created_at', 'DESC')
            ->paginateSafe();

        $response = $contacts->toArray();
        $response['counts'] = [
            'all'     => $counts->sum(),
            'unread'  => $counts->get('unread', 0),
            'read'    => $counts->get('read', 0),
            'replied' => $counts->get('replied', 0),
        ];

        return $this->sendResponse($response, 'Contacts successfully Retrieved...!');
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
            'status' => 'required|string|max:255|in:read,unread,replied',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $contact = Contact::find($request->id);

        if (is_null($contact)) {
            return $this->sendError('Empty', [], 404);
        }

        $contact->update(['status' => $request->status]);

        return $this->sendResponse($contact, 'contacts updated successfully...!');
    }

    public function replyQuery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:contacts,id',
            'reply' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $contact = Contact::find($request->id);

        if (is_null($contact)) {
            return $this->sendError('Empty', [], 404);
        }

        $contact->update([
            'reply' => $request->reply,
            'admin_user_id' => auth()->id(),
            'status' => 'replied',
        ]);

        return $this->sendResponse($contact, 'Reply sent successfully...!');
    }
}
