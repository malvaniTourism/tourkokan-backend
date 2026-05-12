<?php

namespace App\Services;

use App\Models\Favourite;
use App\Models\Site;
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
                        ->where('favourites.favouritable_type', Site::class)
                        ->where('favourites.user_id', auth()->id());
                }, 'is_favorite')
                ->latest()
                ->limit(5)
                ->get()
                ->map(function ($site) {
                    $site->rating_avg_rate = number_format($site->rating_avg_rate, 1);
                    return $site;
                });

            $sites->load(['categories:id,name,code,parent_id,icon,status,is_hot_category']);

            foreach ($sites as $site) {
                $site->setRelation('gallery', $site->gallery()->limit(5)->get());
                $site->setRelation('comment', $site->comment()
                    ->select('id', 'parent_id', 'user_id', 'comment', 'commentable_type', 'commentable_id')
                    ->limit(5)
                    ->get()
                    ->each(function ($comment) {
                        $comment->setRelation('comments', $comment->comments()
                            ->select('id', 'parent_id', 'user_id', 'comment', 'commentable_type', 'commentable_id')
                            ->limit(5)
                            ->get()
                            ->each(fn($reply) => $reply->setRelation(
                                'users',
                                $reply->users()->select('id', 'name', 'email', 'profile_picture')->get()
                            ))
                        );
                        $comment->setRelation('users', $comment->users()->select('id', 'name', 'email', 'profile_picture')->get());
                    })
                );
            }

            if ($sites->isNotEmpty()) {
                $result[Str::plural($code)] = $sites;
            }
        }

        return $result;
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
                    ->where('favourites.favouritable_type', Site::class)
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

        $hotSites->load(['categories:id,name,code,parent_id,icon,status,is_hot_category']);

        foreach ($hotSites as $site) {
            $site->rating_avg_rate = number_format($site->rating_avg_rate, 1);
            $site->setRelation('gallery', $site->gallery()->limit(3)->get());
        }

        return $hotSites;
    }
}
