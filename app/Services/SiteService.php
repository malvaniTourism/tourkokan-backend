<?php

namespace App\Services;

use App\Models\Favourite;
use App\Models\Site;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SiteService
{
    public function __construct(protected CategoryService $categoryService) {}

    /**
     * Returns trending sites grouped by category.
     * Category codes are driven by is_hot_category flag — no hardcoding.
     * Scoped to parent site when $siteId is provided.
     *
     * @param  int|null  $siteId  parent site ID from request
     * @return array<string, Collection>  keyed by plural category code
     */
    public function getTrending(?int $siteId): array
    {
        $categoryCodes = $this->categoryService->getTrendingCodes();
        $result        = [];

        foreach ($categoryCodes as $code) {
            $sites = Site::withCount(['sites', 'photos', 'comment'])
                ->withAvg('rating', 'rate')
                ->whereStatus(true)
                ->whereHas('categories', function ($query) use ($code) {
                    $query->where('code', $code)->whereStatus(true);
                })
                ->when($siteId, fn($q) => $q->where('parent_id', $siteId))
                ->selectSub(function ($query) {
                    $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
                        ->from('favourites')
                        ->whereColumn('sites.id', 'favourites.favouritable_id')
                        ->where('favourites.favouritable_type', (new Site)->getMorphClass())
                        ->where('favourites.user_id', auth()->id());
                }, 'is_favorite')
                ->latest()
                ->limit(5)
                ->get()
                ->map(function ($site) {
                    $site->rating_avg_rate = number_format((float) $site->rating_avg_rate, 1);
                    return $site;
                });

            if ($sites->isNotEmpty()) {
                $result[Str::plural($code)] = $sites;
            }
        }

        // One eager-load pass over every trending site at once (instead of per-site
        // queries inside the loop). MySQL 8 window functions enforce the per-parent limits.
        $merged = new EloquentCollection(collect($result)->flatten(1));
        $merged->load(['categories:id,name,code,parent_id,icon,status,is_hot_category']);
        self::loadSiteEngagement($merged);

        return $result;
    }

    /**
     * Eager-load gallery/comment trees (with per-parent limits) onto a set of sites,
     * preserving the exact response shape of the old per-site lazy queries.
     */
    public static function loadSiteEngagement(EloquentCollection $sites, int $galleryLimit = 5): void
    {
        if ($sites->isEmpty()) {
            return;
        }

        $sites->load([
            'gallery' => fn($q) => $q->orderBy('id')->limit($galleryLimit),
            'comment' => fn($q) => $q
                ->select('id', 'parent_id', 'user_id', 'comment', 'commentable_type', 'commentable_id')
                ->orderBy('id')->limit(5),
            // comments before users so the serialized key order matches the old output
            'comment.comments' => fn($q) => $q
                ->select('id', 'parent_id', 'user_id', 'comment', 'commentable_type', 'commentable_id')
                ->orderBy('id')->limit(5),
            'comment.comments.users:id,name,email,profile_picture',
            'comment.users:id,name,email,profile_picture',
        ]);

        // The old code called ->get() on the belongsTo users(), which yields a
        // collection (serialized as an array). Eager loading yields a single model
        // (serialized as an object) — wrap it back into a collection so the JSON
        // contract stays identical.
        foreach ($sites as $site) {
            foreach ($site->comment as $comment) {
                foreach ($comment->comments as $reply) {
                    self::wrapUsersRelation($reply);
                }
                self::wrapUsersRelation($comment);
            }
        }
    }

    private static function wrapUsersRelation($comment): void
    {
        $user = $comment->getRelation('users');

        if (!$user instanceof EloquentCollection) {
            $comment->setRelation('users', new EloquentCollection($user ? [$user] : []));
        }
    }

    /**
     * Returns up to 4 hot sites.
     *
     * When $siteId is provided:
     *   1. Hot places belonging to that site (is_hot_place = true, parent_id = $siteId)
     *   2. Falls back to any 4 random sites under that site if no hot places found
     *
     * When $siteId is null:
     *   Random 4 hot places from the entire sites table
     *
     * @param  int|null  $siteId  parent site ID from request
     * @return Collection
     */
    public function getHotSites(?int $siteId): Collection
    {
        $baseQuery = Site::select('id', 'name', 'mr_name', 'tag_line', 'logo', 'icon', 'image', 'is_hot_place', 'parent_id')
            ->withAvg('rating', 'rate')
            ->whereStatus(true)
            ->selectSub(function ($query) {
                $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
                    ->from('favourites')
                    ->whereColumn('sites.id', 'favourites.favouritable_id')
                    ->where('favourites.favouritable_type', (new Site)->getMorphClass())
                    ->where('favourites.user_id', auth()->id());
            }, 'is_favorite')
            ->inRandomOrder()
            ->limit(4);

        if ($siteId) {
            $hotSites = (clone $baseQuery)
                ->where('parent_id', $siteId)
                ->where('is_hot_place', true)
                ->get();

            if ($hotSites->isEmpty()) {
                $hotSites = (clone $baseQuery)
                    ->where('parent_id', $siteId)
                    ->get();
            }
        } else {
            $hotSites = (clone $baseQuery)
                ->where('is_hot_place', true)
                ->get();
        }

        $hotSites->load([
            'categories:id,name,code,parent_id,icon,status,is_hot_category',
            'gallery' => fn($q) => $q->orderBy('id')->limit(3),
        ]);

        foreach ($hotSites as $site) {
            $site->rating_avg_rate = number_format((float) $site->rating_avg_rate, 1);
        }

        return $hotSites;
    }
}
