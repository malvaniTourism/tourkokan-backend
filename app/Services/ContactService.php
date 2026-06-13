<?php

namespace App\Services;

use App\Models\Contact;

class ContactService
{
    /**
     * Get recent queries for a user.
     *
     * $options = [
     *   'user_id' => int,       // required
     *   'limit'   => int,       // default: 10
     *   'status'  => string,    // optional: unread|read|replied
     *   'counts'  => bool,      // default: false — include status counts
     * ]
     */
    public function getForUser(array $options = [])
    {
        $userId = $options['user_id'] ?? null;
        $limit  = $options['limit']   ?? 10;
        $status = $options['status']  ?? null;
        $withCounts = $options['counts'] ?? false;

        $query = Contact::where('user_id', $userId)
            ->when($status, fn($q) => $q->where('status', $status))
            ->orderBy('created_at', 'DESC');

        $contacts = $query->limit($limit)->get();

        if (!$withCounts) {
            return $contacts;
        }

        $counts = Contact::where('user_id', $userId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'data'   => $contacts,
            'counts' => [
                'all'     => $counts->sum(),
                'unread'  => $counts->get('unread', 0),
                'read'    => $counts->get('read', 0),
                'replied' => $counts->get('replied', 0),
            ],
        ];
    }

    /**
     * Get paginated queries for a user.
     * per_page is taken from the request globally (default 15, max 30).
     *
     * $options = [
     *   'user_id'  => int,      // required
     *   'status'   => string,   // optional: unread|read|replied
     * ]
     */
    public function getPaginatedForUser(array $options = [])
    {
        $userId  = $options['user_id'] ?? null;
        $status  = $options['status']  ?? null;

        $counts = Contact::where('user_id', $userId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $contacts = Contact::where('user_id', $userId)
            ->when($status, fn($q) => $q->where('status', $status))
            ->orderBy('created_at', 'DESC')
            ->paginateSafe();

        $response = $contacts->toArray();
        $response['counts'] = [
            'all'     => $counts->sum(),
            'unread'  => $counts->get('unread', 0),
            'read'    => $counts->get('read', 0),
            'replied' => $counts->get('replied', 0),
        ];

        return $response;
    }
}
