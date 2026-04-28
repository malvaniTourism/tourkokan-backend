<?php

namespace App\Http\Controllers\Admin\V2;

use App\Models\Site;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use App\Models\Category;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SiteController extends BaseController
{
    // ── Listing / Browse ──────────────────────────────────────────────────────

    public function sites(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search'    => 'sometimes|nullable|string|alpha|max:255',
            'type'      => 'sometimes|required|string|max:255|in:bus',
            'apitype'   => 'required|string|max:255|in:list,dropdown',
            'category'  => 'nullable|exists:categories,code',
            'parent_id' => 'nullable|exists:sites,parent_id',
            'global'    => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $withArr = [
            'sites' => function ($query) {
                $query->select('id', 'name', 'mr_name', 'parent_id', 'image', 'domain_name', 'description', 'tag_line', 'bus_stop_type', 'icon', 'status')
                    ->with(['rate:id,user_id,rate,rateable_type,rateable_id,status'])
                    ->where('is_hot_place', true)
                    ->withAvg('rating', 'rate');
            },
            'sites.comment',
            'photos',
            'comment',
            'categories:id,name,code,parent_id,icon,status,is_hot_category',
            'rate:id,user_id,rate,rateable_type,rateable_id,status',
            'address:id,email,phone,latitude,longitude,addressable_type,addressable_id',
        ];

        if ($request->apitype == 'dropdown') {
            $withArr = ['categories:id,name,code,parent_id,icon,status,is_hot_category'];
        }

        $sites = Site::with($withArr);

        if (isValidReturn($request, 'category') == 'emergency') {
            $category = Category::with('subCategories')->where('code', 'emergency')->first();
            if ($category) {
                $ids = $category->subCategories->pluck('id');
                $ids->prepend($category->id);
                $sites = $sites->whereHas('categories', fn($q) => $q->whereIn('id', $ids));
            } else {
                $sites = $sites->whereNull('id');
            }
        } elseif ($request->has('category')) {
            $sites = $sites->whereHas('categories', fn($q) => $q->where('code', $request->category));
        }

        if ($request->has('parent_id')) {
            $sites = $sites->where('parent_id', $request->parent_id);
        }
        if ($request->has('global')) {
            $sites = $sites->whereNotNull('parent_id');
        }
        if ($request->has('search')) {
            $sites = $sites->where('name', 'like', $request->input('search') . '%');
        }
        if ($request->has('type') && $request->input('type') == 'bus') {
            $sites = $sites->whereIn('bus_stop_type', ['Depo', 'Stop']);
        }

        $sites = $sites->select(isValidReturn(config('grid.siteApiTypes.' . $request->apitype), 'columns', '*'))
            ->paginate(isValidReturn($request->all(), 'per_page', 15));

        return $this->sendResponse($sites, 'Sites successfully Retrieved...!');
    }

    public function getSite(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:sites,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $city = Site::withCount(['sites', 'photos', 'comment'])
            ->with([
                'categories:id,name,code,parent_id,icon,status,is_hot_category',
                'sites' => fn($q) => $q->with('categories:id,name,code,parent_id,icon,status,is_hot_category')->limit(5),
                'comment' => fn($q) => $q->select('id', 'parent_id', 'user_id', 'comment', 'commentable_type', 'commentable_id')->limit(5),
                'comment.comment' => fn($q) => $q->select('id', 'parent_id', 'user_id', 'comment', 'commentable_type', 'commentable_id')->limit(5),
                'comment.users' => fn($q) => $q->select('id', 'name', 'email', 'profile_picture'),
                'comment.comment.users' => fn($q) => $q->select('id', 'name', 'email', 'profile_picture'),
                'photos',
            ])
            ->withAvg('rating', 'rate')
            ->latest()
            ->limit(5)
            ->find($request->id);

        return $this->sendResponse($city, 'Site successfully Retrieved...!');
    }

    public function stops()
    {
        $places = Site::with(['site:id,name,icon', 'categories:id,name,code,parent_id,icon,status,is_hot_category'])
            ->whereIn('bus_stop_type', ['Depo', 'Stop'])
            ->select('id', 'name', 'parent_id', 'icon', 'status', 'is_hot_place', 'bus_stop_type')
            ->paginate(10);

        return $this->sendResponse($places, 'Stops successfully Retrieved...!');
    }

    public function searchPlace(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search'  => 'sometimes|nullable|string|alpha|max:255',
            'type'    => 'sometimes|nullable|string|max:255|in:bus',
            'apitype' => 'required|string|max:255|in:list,dropdown',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $places = Site::withCount(['sites', 'photos', 'comments'])
            ->with(['photos', 'categories:id,name,icon,status']);

        if ($request->has('search')) {
            $places = $places->where('name', 'like', '%' . $request->input('search') . '%');
        }
        if ($request->has('type') && $request->input('type') == 'bus') {
            $places->whereIn('bus_stop_type', ['Depo', 'Stop']);
        }

        $places = $places->select(isValidReturn(config('grid.siteApiTypes.' . $request->apitype), 'columns', '*'))
            ->paginate();

        return $this->sendResponse($places, 'Places successfully Retrieved...!');
    }

    // ── Admin CRUD ────────────────────────────────────────────────────────────

    public function addSite(Request $request)
    {
        if (is_string($request['categories'])) {
            $request['categories'] = json_decode($request['categories'], true);
        }

        $userId   = $request->input('user_id');
        $parentId = $request->input('parent_id');

        // Uniqueness depends on ownership:
        // - With user_id (business owner): user_id + name + lat/long must be unique (allows branches)
        // - Without user_id (public place — temple, village): name + parent_id scoped uniqueness
        $nameRule = $userId
            ? Rule::unique('sites', 'name')->where(fn($q) => $q
                ->where('user_id', $userId)
                ->where('latitude', $request->latitude)
                ->where('longitude', $request->longitude))
            : Rule::unique('sites', 'name')->where(fn($q) => $parentId
                ? $q->where('parent_id', $parentId)
                : $q->whereNull('parent_id'));

        $validator = Validator::make($request->all(), [
            'name'          => ['required', 'string', 'between:2,100', $nameRule],
            'parent_id'     => 'nullable|exists:sites,id',
            'user_id'       => 'nullable|exists:users,id',
            'categories'    => 'required|array',
            'categories.*'  => 'exists:categories,id',
            'bus_stop_type' => 'nullable|in:Stop,Depo',
            'tag_line'      => 'required|string|between:2,100',
            'description'   => 'required|string',
            'domain_name'   => 'nullable|string',
            'logo'          => 'nullable|mimes:jpeg,jpg,png|max:1024',
            'icon'          => 'nullable|mimes:jpeg,jpg,png|max:512',
            'image'         => 'nullable|mimes:jpeg,jpg,png|max:1024',
            'status'        => 'boolean:true,false',
            'latitude'      => 'nullable|required_with:longitude|between:-90,90',
            'longitude'     => 'nullable|required_with:latitude|between:-90,90',
            'pin_code'      => 'nullable|numeric',
            'speciality'    => 'nullable|json',
            'rules'         => 'nullable|json',
            'social_media'  => 'nullable|json',
            'meta_data'     => 'nullable|json',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $validatedData = $validator->validated();
        $input = $request->all();
        $uploadPath = config('constants.upload_path.site');

        foreach (['logo', 'icon', 'image'] as $field) {
            if ($image = $request->file($field)) {
                $input[$field] = uploadFile($image, $uploadPath)['path'];
            }
        }

        $site = Site::create($input);
        $site->categories()->attach($validatedData['categories']);

        return $this->sendResponse($site, 'Site added successfully...!');
    }

    public function updateSite(Request $request)
    {
        if (is_string($request->input('categories'))) {
            $request->merge(['categories' => json_decode($request->input('categories'), true)]);
        }

        $validator = Validator::make($request->all(), [
            'id'           => 'required|exists:sites,id',
            'name'         => ['sometimes', 'required', 'string', 'between:2,100', Rule::unique('sites', 'name')->ignore($request->id)],
            'parent_id'    => 'sometimes|required|exists:sites,id',
            'user_id'      => 'sometimes|required|exists:users,id',
            'categories'   => 'nullable|array',
            'categories.*' => 'exists:categories,id',
            'bus_stop_type' => 'sometimes|required|in:Stop,Depo',
            'tag_line'     => 'sometimes|required|string|between:2,100',
            'description'  => 'sometimes|required|string',
            'domain_name'  => 'sometimes|required|string',
            'logo'         => 'sometimes|required|mimes:jpeg,jpg,png|max:1024',
            'icon'         => 'sometimes|required|mimes:jpeg,jpg,png|max:512',
            'image'        => 'sometimes|required|mimes:jpeg,jpg,png|max:1024',
            'status'       => 'sometimes|required|boolean:true,false',
            'latitude'     => 'sometimes|required|required_with:longitude|between:-90,90',
            'longitude'    => 'sometimes|required|required_with:latitude|between:-90,90',
            'pin_code'     => 'sometimes|required|numeric',
            'is_hot_place' => 'sometimes|required|boolean:true,false',
            'speciality'   => 'sometimes|required|json',
            'rules'        => 'sometimes|required|json',
            'social_media' => 'sometimes|required|json',
            'meta_data'    => 'sometimes|required|json',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $site = Site::find($request->id);
        $input = $request->except('categories');
        $uploadPath = config('constants.upload_path.site');

        foreach (['logo', 'icon', 'image'] as $field) {
            if ($image = $request->file($field)) {
                if (Storage::exists($site->$field)) {
                    Storage::delete($site->$field);
                }
                $input[$field] = uploadFile($image, $uploadPath)['path'];
            }
        }

        $site->update($input);

        if ($request->has('categories')) {
            $site->categories()->sync($request->input('categories'));
        }

        return $this->sendResponse($site, 'Site updated successfully...!');
    }

    public function deleteSite(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:sites,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $site = Site::find($request->id);

        if (!$site) {
            return $this->sendError('Empty', [], 404);
        }

        foreach (['logo', 'icon', 'image'] as $field) {
            if (Storage::exists($site->$field)) {
                Storage::delete($site->$field);
            }
        }

        $site->delete();

        return $this->sendResponse($site, 'Site deleted successfully...!');
    }

    // ── User Onboarding (user submits / manages own listings) ─────────────────

    /**
     * Parse a Google Maps URL → extract lat/lng. No API key required.
     * POST /api/v2/parseMapUrl
     */
    public function parseMapUrl(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'url' => 'required|string|max:2048',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $url = $request->input('url');

        if (str_contains($url, 'goo.gl') || str_contains($url, 'maps.app.goo')) {
            try {
                $response = Http::withOptions(['allow_redirects' => true])->get($url);
                $url = $response->effectiveUri() ?? $url;
            } catch (\Throwable $e) {
                // fall through to regex on original URL
            }
        }

        $patterns = [
            '/@(-?\d+\.\d+),(-?\d+\.\d+)/',
            '/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/',
            '/[?&]ll=(-?\d+\.\d+),(-?\d+\.\d+)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $this->sendResponse([
                    'latitude'  => (float) $matches[1],
                    'longitude' => (float) $matches[2],
                ], 'Coordinates extracted successfully.');
            }
        }

        return $this->sendError(
            'Could not extract coordinates from this URL. Please use the map picker or enter manually.',
            '',
            422
        );
    }

    /**
     * User submits a new site for admin review.
     * POST /api/v2/addSite
     * Uniqueness: user_id + name + lat/long — allows same-name branches at different locations.
     */
    public function submitSite(Request $request)
    {
        if (is_string($request->input('categories'))) {
            $request->merge(['categories' => json_decode($request->input('categories'), true)]);
        }

        $userId = auth()->id();

        $validator = Validator::make($request->all(), [
            'name' => [
                'required', 'string', 'between:2,100',
                Rule::unique('sites', 'name')->where(fn($q) => $q
                    ->where('user_id', $userId)
                    ->where('latitude', $request->latitude)
                    ->where('longitude', $request->longitude)),
            ],
            'categories'   => 'required|array|min:1',
            'categories.*' => 'exists:categories,id',
            'parent_id'    => 'nullable|exists:sites,id',
            'description'  => 'required|string|min:20',
            'tag_line'     => 'nullable|string|max:100',
            'domain_name'  => 'nullable|url|max:255',
            'image'        => 'nullable|mimes:jpeg,jpg,png|max:2048',
            'logo'         => 'nullable|mimes:jpeg,jpg,png|max:1024',
            'latitude'     => 'required|numeric|between:-90,90',
            'longitude'    => 'required|numeric|between:-180,180',
            'pin_code'     => 'nullable|digits:6',
            'social_media' => 'nullable|json',
            'speciality'   => 'nullable|json',
            'rules'        => 'nullable|json',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $input = $request->except('categories');
        $input['user_id']           = $userId;
        $input['status']            = false;
        $input['submission_status'] = 'pending';

        foreach (['logo', 'image'] as $field) {
            if ($file = $request->file($field)) {
                $input[$field] = uploadFile($file, config('constants.upload_path.site'))['path'];
            }
        }

        $site = Site::create($input);
        $site->categories()->attach($request->input('categories'));

        return $this->sendResponse(
            $site->load('categories:id,name,code'),
            'Your place has been submitted and is under review. We will notify you once approved.'
        );
    }

    /**
     * List the authenticated user's own submissions.
     * POST /api/v2/mySubmissions
     */
    public function mySubmissions(Request $request)
    {
        $submissions = Site::where('user_id', auth()->id())
            ->with('categories:id,name,code')
            ->select('id', 'name', 'image', 'status', 'submission_status', 'rejection_reason', 'created_at', 'updated_at')
            ->latest()
            ->paginate($request->input('per_page', 15));

        return $this->sendResponse($submissions, 'Submissions fetched.');
    }

    /**
     * User updates a pending or rejected submission.
     * POST /api/v2/updateSubmission
     */
    public function updateSubmission(Request $request)
    {
        if (is_string($request->input('categories'))) {
            $request->merge(['categories' => json_decode($request->input('categories'), true)]);
        }

        $siteId   = $request->input('id');
        $parentId = $request->input('parent_id');

        $userId = auth()->id();

        $validator = Validator::make($request->all(), [
            'id'           => 'required|numeric|exists:sites,id',
            'name'         => [
                'sometimes', 'string', 'between:2,100',
                Rule::unique('sites', 'name')
                    ->ignore($siteId)
                    ->where(fn($q) => $q
                        ->where('user_id', $userId)
                        ->where('latitude', $request->latitude)
                        ->where('longitude', $request->longitude)),
            ],
            'categories'   => 'sometimes|array|min:1',
            'categories.*' => 'exists:categories,id',
            'parent_id'    => 'nullable|exists:sites,id',
            'description'  => 'sometimes|string|min:20',
            'tag_line'     => 'nullable|string|max:100',
            'domain_name'  => 'nullable|url|max:255',
            'image'        => 'nullable|mimes:jpeg,jpg,png|max:2048',
            'logo'         => 'nullable|mimes:jpeg,jpg,png|max:1024',
            'latitude'     => 'nullable|numeric|between:-90,90',
            'longitude'    => 'nullable|numeric|between:-180,180',
            'pin_code'     => 'nullable|digits:6',
            'social_media' => 'nullable|json',
            'speciality'   => 'nullable|json',
            'rules'        => 'nullable|json',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $site = Site::where('id', $siteId)
            ->where('user_id', $userId)
            ->whereIn('submission_status', ['pending', 'rejected'])
            ->first();

        if (!$site) {
            return $this->sendError('Submission not found or cannot be edited.', '', 404);
        }

        $input = $request->except(['id', 'categories']);

        if ($site->submission_status === 'rejected') {
            $input['submission_status'] = 'pending';
            $input['rejection_reason']  = null;
        }

        foreach (['logo', 'image'] as $field) {
            if ($file = $request->file($field)) {
                $input[$field] = uploadFile($file, config('constants.upload_path.site'))['path'];
            }
        }

        $site->update($input);

        if ($request->has('categories')) {
            $site->categories()->sync($request->input('categories'));
        }

        return $this->sendResponse(
            $site->load('categories:id,name,code'),
            'Submission updated. It is now under review again.'
        );
    }

    /**
     * User deletes a pending or rejected submission.
     * POST /api/v2/deleteSubmission
     * Approved (live) sites cannot be deleted by the user.
     */
    public function deleteSubmission(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:sites,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $site = Site::where('id', $request->id)
            ->where('user_id', auth()->id())
            ->whereIn('submission_status', ['pending', 'rejected'])
            ->first();

        if (!$site) {
            return $this->sendError('Submission not found or cannot be deleted.', '', 404);
        }

        $site->delete();

        return $this->sendResponse(null, 'Submission deleted.');
    }

    // ── Admin Submission Review ───────────────────────────────────────────────

    /**
     * List all pending site submissions.
     * POST /api/v2/admin/pendingSites
     */
    public function pendingSubmissions(Request $request)
    {
        $sites = Site::where('submission_status', 'pending')
            ->with(['categories:id,name,code', 'user:id,name,email'])
            ->latest()
            ->paginate($request->input('per_page', 20));

        return $this->sendResponse($sites, 'Pending site submissions fetched.');
    }

    /**
     * List all submissions with optional status/search filter.
     * POST /api/v2/admin/allSubmissions
     */
    public function allSubmissions(Request $request)
    {
        $query = Site::with(['categories:id,name,code', 'user:id,name,email'])->latest();

        if ($request->filled('submission_status')) {
            $query->where('submission_status', $request->submission_status);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        return $this->sendResponse(
            $query->paginate($request->input('per_page', 20)),
            'Submissions fetched.'
        );
    }

    /**
     * Approve a site submission — makes it live.
     * POST /api/v2/admin/approveSite
     */
    public function approveSite(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:sites,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $site = Site::find($request->id);
        $site->update([
            'submission_status' => 'approved',
            'status'            => true,
            'rejection_reason'  => null,
        ]);

        return $this->sendResponse($site, 'Site approved and is now live.');
    }

    /**
     * Reject a site submission with a reason.
     * POST /api/v2/admin/rejectSite
     */
    public function rejectSite(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'               => 'required|numeric|exists:sites,id',
            'rejection_reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $site = Site::find($request->id);
        $site->update([
            'submission_status' => 'rejected',
            'status'            => false,
            'rejection_reason'  => $request->rejection_reason,
        ]);

        return $this->sendResponse($site, 'Site rejected. User has been notified of the reason.');
    }
}
