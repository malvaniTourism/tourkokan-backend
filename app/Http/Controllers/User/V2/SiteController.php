<?php

namespace App\Http\Controllers\User\V2;

use App\Models\Site;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use App\Models\Category;
use App\Services\SiteService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SiteController extends BaseController
{
    public function __construct(protected SiteService $siteService)
    {
        $this->middleware('auth:api');
    }

    public function listCities()
    {
        $user = auth()->user();

        $city = Site::withCount(['gallery', 'comment'])
            ->with(['gallery', 'comment', 'categories:id,name,code,parent_id,icon,status,is_hot_category'])
            ->selectSub(function ($query) use ($user) {
                $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
                    ->from('favourites')
                    ->whereColumn('sites.id', 'favourites.favouritable_id')
                    ->where('favourites.favouritable_type', Site::class)
                    ->where('favourites.user_id', $user->id);
            }, 'is_favorite')
            ->whereHas('categories', function ($query) {
                $query->where('code', 'city');
            })->paginate(10);

        return $this->sendResponse($city, 'Cities successfully Retrieved...!');
    }

    public function getSite(Request $request)
    {
        $user_id = auth()->id();

        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:sites,id'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $city = Site::withCount(['sites', 'gallery', 'comment'])
            ->withAvg('rating', 'rate')
            ->with([
                'categories:id,name,code,parent_id,icon,status,is_hot_category',
                'sites' => function ($query) {
                    $query->with('categories:id,name,code,parent_id,icon,status,is_hot_category')
                        ->limit(5);
                },
                'comment' => function ($query) {
                    $query->select('id', 'parent_id', 'user_id', 'comment', 'commentable_type', 'commentable_id')
                        ->limit(5);
                },
                'comment.comments' => function ($query) {
                    $query->select('id', 'parent_id', 'user_id', 'comment', 'commentable_type', 'commentable_id')
                        ->limit(5);
                },
                'comment.users' => function ($query) {
                    $query->select('id', 'name', 'email', 'profile_picture');
                },
                'comment.comments.users' => function ($query) {
                    $query->select('id', 'name', 'email', 'profile_picture');
                },
                'gallery'
            ])
            ->selectSub(function ($query) use ($user_id) {
                $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
                    ->from('favourites')
                    ->whereColumn('sites.id', 'favourites.favouritable_id')
                    ->where('favourites.favouritable_type', Site::class)
                    ->where('favourites.user_id', $user_id);
            }, 'is_favorite')
            ->latest()
            ->limit(5)
            ->find($request->id);

        $city->setAttribute('trending',  $this->siteService->getTrending($request->id));
        $city->setAttribute('hot_sites', $this->siteService->getHotSites($request->id));

        return $this->sendResponse($city, 'Site successfully Retrieved...!');
    }

    public function stops()
    {
        $places = Site::with([
            'site:id,name,icon',
            'categories:id,name,code,parent_id,icon,status,is_hot_category'
        ])
            ->whereIn('bus_stop_type', ['Depo', 'Stop'])
            ->select('id', 'name', 'parent_id', 'icon', 'status', 'is_hot_place', 'bus_stop_type')
            ->paginate(10);

        return $this->sendResponse($places, 'Stops successfully Retrieved...!');
    }

    public function sites(Request $request)
    {
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'search'    => 'sometimes|nullable|string|alpha|max:255',
            'type'      => 'sometimes|required|string|max:255|in:bus',
            'apitype'   => 'required|string|max:255|in:list,dropdown',
            'category'  => ($request->has('type') || $request->has('global')) ? 'nullable|exists:categories,code' : 'nullable|required_without:parent_id|exists:categories,code',
            'parent_id' => 'nullable|required_with:parent_id|exists:sites,parent_id',
            'global'    => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $withArr = [
            'site:id,parent_id,name',
            'sites' => function ($query) use ($user) {
                $query->select(
                    'id', 'name', 'mr_name', 'parent_id', 'image',
                    'domain_name', 'description', 'tag_line',
                    'bus_stop_type', 'icon', 'status',
                )
                    ->with(['rate:id,user_id,rate,rateable_type,rateable_id,status', 'gallery'])
                    ->where('is_hot_place', true)
                    ->selectSub(function ($query) use ($user) {
                        $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
                            ->from('favourites')
                            ->whereColumn('sites.id', 'favourites.favouritable_id')
                            ->where('favourites.favouritable_type', Site::class)
                            ->where('favourites.user_id', $user->id);
                    }, 'is_favorite')
                    ->orderBy('name', 'asc')
                    ->withAvg("rating", 'rate');
            },
            'sites.comment',
            'gallery',
            'comment',
            'categories:id,name,code,parent_id,icon,status,is_hot_category',
            'rate:id,user_id,rate,rateable_type,rateable_id,status',
            'address:id,email,phone,latitude,longitude,addressable_type,addressable_id'
        ];

        if ($request->apitype == 'dropdown') {
            $withArr = ['categories:id,name,code,parent_id,icon,status,is_hot_category'];
        }

        $sites = Site::with($withArr);

        if (isValidReturn($request, 'category') == "emergency") {
            $category = Category::with('subCategories')->where('code', 'emergency')->first();
            if ($category) {
                $ids = $category->subCategories->pluck('id');
                $sites = $sites->whereHas('categories', function ($query) use ($ids) {
                    $query->whereIn('id', $ids);
                });
            } else {
                $sites = $sites->whereNull('id');
            }
        } else {
            if ($request->has('category')) {
                $sites = $sites->whereHas('categories', function ($query) use ($request) {
                    $query->where('code', $request->category);
                });
            }
        }

        if ($request->has('parent_id')) {
            $sites = $sites->where('parent_id', '=', $request->parent_id);
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

        $sites = $sites->select(isValidReturn(config('grid.siteApiTypes.' . $request->apitype), 'columns', '*'));

        if ($request->apitype != 'dropdown') {
            $sites = $sites->selectSub(function ($query) use ($user) {
                $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
                    ->from('favourites')
                    ->whereColumn('sites.id', 'favourites.favouritable_id')
                    ->where('favourites.favouritable_type', Site::class)
                    ->where('favourites.user_id', $user->id);
            }, 'is_favorite')
                ->withAvg("rating", 'rate')
                ->withCount(['gallery', 'comment']);
        }

        $sites = $sites->paginate($request->get('per_page', 15));

        return $this->sendResponse($sites, 'Sites successfully Retrieved...!');
    }

    // ── Site Onboarding (user submits / manages own listings) ────────────────

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
                $resolved = (string) $response->effectiveUri();
                if (!empty($resolved)) {
                    $url = $resolved;
                }
            } catch (\Throwable $e) {
                // fall through to regex on original URL
            }
        }

        $patterns = [
            '/\/maps\/search\/(-?\d+\.?\d*),\+?\s*(-?\d+\.?\d*)/',
            '/@(-?\d+\.\d+),(-?\d+\.\d+)/',
            '/[?&]q=(-?\d+\.\d+),\+?(-?\d+\.\d+)/',
            '/[?&]ll=(-?\d+\.\d+),\+?(-?\d+\.\d+)/',
            '/\/place\/[^\/]+\/@(-?\d+\.\d+),(-?\d+\.\d+)/',
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
            'image'        => 'nullable|mimes:jpeg,jpg,png,webp|max:2048',
            'logo'         => 'nullable|mimes:jpeg,jpg,png,webp|max:1024',
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

    public function mySubmissions(Request $request)
    {
        $submissions = Site::where('user_id', auth()->id())
            ->with('categories:id,name,code')
            ->select('id', 'name', 'image', 'status', 'submission_status', 'rejection_reason', 'created_at', 'updated_at')
            ->latest()
            ->paginate($request->input('per_page', 15));

        return $this->sendResponse($submissions, 'Submissions fetched.');
    }

    public function updateSubmission(Request $request)
    {
        if (is_string($request->input('categories'))) {
            $request->merge(['categories' => json_decode($request->input('categories'), true)]);
        }

        $siteId = $request->input('id');
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
            'image'        => 'nullable|mimes:jpeg,jpg,png,webp|max:2048',
            'logo'         => 'nullable|mimes:jpeg,jpg,png,webp|max:1024',
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
            ->whereIn('submission_status', ['pending', 'rejected', 'approved'])
            ->first();

        if (!$site) {
            return $this->sendError('Submission not found or cannot be edited.', '', 404);
        }

        $input          = $request->except(['id', 'categories']);
        $previousStatus = $site->submission_status;

        if (in_array($previousStatus, ['rejected', 'approved'])) {
            $input['submission_status'] = 'pending';
            $input['rejection_reason']  = null;

            if ($previousStatus === 'approved') {
                $input['status']    = false;
                $input['meta_data'] = array_merge($site->meta_data ?? [], [
                    'resubmission' => [
                        'message'         => 'Site details were updated by the owner and require re-approval.',
                        'previous_status' => $previousStatus,
                        'updated_at'      => now()->toDateTimeString(),
                    ],
                ]);
            }
        }

        foreach (['logo', 'image'] as $field) {
            if ($file = $request->file($field)) {
                $rawPath = $site->getRawOriginal($field);
                if ($rawPath && Storage::exists($rawPath)) {
                    Storage::delete($rawPath);
                }
                $input[$field] = uploadFile($file, config('constants.upload_path.site'))['path'];
            }
        }

        $site->update($input);

        if ($request->has('categories')) {
            $site->categories()->sync($request->input('categories'));
        }

        return $this->sendResponse(
            array_merge($site->load('categories:id,name,code')->toArray(), [
                'meta_data' => $site->meta_data,
            ]),
            $previousStatus === 'approved'
                ? 'Your place has been updated and sent for re-approval.'
                : 'Submission updated. It is now under review again.'
        );
    }

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

        foreach (['logo', 'image'] as $field) {
            $rawPath = $site->getRawOriginal($field);
            if ($rawPath && Storage::exists($rawPath)) {
                Storage::delete($rawPath);
            }
        }

        $site->delete();

        return $this->sendResponse(null, 'Submission deleted.');
    }
}
