<?php

namespace App\Services;

use App\Models\Gallery;
use App\Models\Product;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Cleans up what a site's deletion leaves behind that foreign keys cannot.
 *
 * When a site is deleted, MySQL cascades the rows that hold a real FK — products (and
 * through them variants, daily stats, leads, view events), category_site, addresses,
 * routes, route_stops. But the **polymorphic** relations carry no FK (a morph column can't
 * reference one table), so they orphan:
 *
 *   galleries · comments · ratings · favourites · contacts · banners
 *
 * …for the site itself **and** for every product it owns — and the products' gallery image
 * files sit in S3 forever. This runs on `Site::deleting`, before the cascade fires, while
 * the products are still queryable.
 *
 * Wrapped and logged, never thrown: a cleanup failure must not block a legitimate delete.
 */
class SiteDeletionCleaner
{
    /** Morph relations with no foreign key, so they never cascade. */
    private const MORPH_TABLES = [
        'galleries'  => 'galleryable',
        'comments'   => 'commentable',
        'ratings'    => 'rateable',
        'favourites' => 'favouritable',
        'contacts'   => 'contactable',
        'banners'    => 'bannerable',
    ];

    public function clean(Site $site): void
    {
        try {
            $productIds = Product::withTrashed()->where('site_id', $site->id)->pluck('id');

            // getMorphClass(), not ::class — a model in the morph map stores its alias
            // ("site"), not the FQCN, so matching on the class name would silently miss it.
            $siteType    = $site->getMorphClass();
            $productType = (new Product())->getMorphClass();

            // Files first — once the gallery rows are gone the paths are unrecoverable.
            $this->deleteStoredFiles($productType, $productIds);
            $this->deleteStoredFiles($siteType, collect([$site->id]));

            $this->purgeMorphs($productType, $productIds);
            $this->purgeMorphs($siteType, collect([$site->id]));
        } catch (\Throwable $e) {
            Log::warning('SiteDeletionCleaner failed', ['site' => $site->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Remove the S3/disk files behind a morph owner's gallery before its rows vanish.
     */
    private function deleteStoredFiles(string $type, $ownerIds): void
    {
        if ($ownerIds->isEmpty()) {
            return;
        }

        Gallery::where('galleryable_type', $type)
            ->whereIn('galleryable_id', $ownerIds)
            ->pluck('path')
            ->each(function ($path) {
                // Legacy rows may already hold a full URL; only storage-relative paths are
                // ours to delete.
                if ($path && !str_starts_with($path, 'http') && Storage::exists($path)) {
                    Storage::delete($path);
                }
            });
    }

    private function purgeMorphs(string $type, $ownerIds): void
    {
        if ($ownerIds->isEmpty()) {
            return;
        }

        foreach (self::MORPH_TABLES as $table => $morph) {
            DB::table($table)
                ->where("{$morph}_type", $type)
                ->whereIn("{$morph}_id", $ownerIds)
                ->delete();
        }
    }
}
