<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;

class CategoryService
{
    /**
     * Get paginated categories with optional filter.
     * Used by CategoryController::listcategories()
     */
    /**
     * $options = [
     *   'paginate' => bool,         // default: true
     *   'fields'   => array,        // default: ['*']
     * ]
     */
    public function getPaginated($category = null, $page = 1, $perPage = 15, array $options = [])
    {
        $paginate = $options['paginate'] ?? true;
        $fields   = $options['fields']   ?? ['*'];
        $include_empty   = $options['include_empty']   ?? false;

        // Always ensure 'id' is selected so eager loads work
        if ($fields !== ['*'] && !in_array('id', $fields)) {
            $fields[] = 'id';
        }

        // Counts and "has anything to show" checks all run through liveSites so the figure
        // matches what a tourist can actually open — approved, published, not deleted.
        $with = [
            'subCategories' => fn($q) => $q
                ->select('id', 'name', 'mr_name', 'code', 'parent_id', 'icon', 'is_hot_category')
                ->withCount('liveSites as sites_count')
                ->when(!$include_empty, fn($sub) => $sub->has('liveSites'))
                ->latest(),
        ];

        if ($category) {
            $with['subCategories.liveSites'] = fn($q) => $q->select('sites.id', 'name', 'meta_data');
        }

        $query = Category::with($with)
            ->select($fields)
            ->withCount(['liveSites as sites_count', 'subCategories'])
            ->whereNotIn('code', self::LOCATION_CODES)
            ->whereStatus(true)
            ->latest()
            ->when($category, fn($q) => $q->where('code', $category))
            ->when(!$category, fn($q) => $q->whereNull('parent_id')
                ->when(!$include_empty, fn($qq) => $qq->whereHas('subCategories.liveSites')));

        if (!$paginate) {
            return $query->get();
        }

        $categories = $query->paginateSafe();

        if ($category) {
            $categories->getCollection()->transform(function ($cat) use ($perPage) {
                $cat->subCategories->transform(function ($subCategory) use ($perPage) {
                    // Expose the trimmed live sites under `sites` — the key the app reads —
                    // while the count above and this list now share the same live scope.
                    $subCategory->setRelation('sites', $subCategory->liveSites->take($perPage ?? 15));
                    $subCategory->unsetRelation('liveSites');
                    return $subCategory;
                });
                return $cat;
            });
        }

        return $categories;
    }

    /**
     * Returns codes of all active hot categories.
     * Used by SiteService::getTrending() to build the trending section dynamically.
     * Result is cached for 60 minutes — clear with Cache::forget('trending_category_codes').
     *
     * @return array<string>
     */
    protected const LOCATION_CODES = ['country', 'state', 'district'];

    /**
     * Returns codes of all active hot categories that have at least one active site.
     * Location-type categories are excluded — trending is for place types only.
     * Result is cached for 60 minutes — clear with Cache::forget('trending_category_codes').
     *
     * @return array<string>
     */
    public function getTrendingCodes(): array
    {
        return Cache::remember('trending_category_codes', 60, function () {
            return Category::whereStatus(true)
                // ->where('is_hot_category', true)
                ->whereNotIn('code', self::LOCATION_CODES)
                ->whereExists(function ($query) {
                    $query->select(\DB::raw(1))
                        ->from('category_site')
                        ->join('sites', 'sites.id', '=', 'category_site.site_id')
                        ->whereColumn('category_site.category_id', 'categories.id')
                        ->where('sites.status', true);
                })
                ->pluck('code')
                ->toArray();
        });
    }
}
