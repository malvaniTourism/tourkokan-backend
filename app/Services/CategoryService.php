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
    public function getPaginated($category = null, $page = 1)
    {
        $cacheKey = 'categories_' . ($category ?? 'all') . '_page_' . $page;

        return Cache::remember($cacheKey, 86400, function () use ($category) {
            $with = ['subCategories:id,name,mr_name,code,parent_id,icon,is_hot_category'];

            if ($category) {
                $with['subCategories.sites'] = fn($q) => $q->select('id', 'name', 'meta_data');
            }

            $categories = Category::with($with)
                ->select('*')
                ->whereNotIn('code', ['country', 'state', 'city', 'district', 'village', 'area'])
                ->whereStatus(true)
                ->when($category, fn($q) => $q->where('code', $category))
                ->when(!$category, fn($q) => $q->whereNull('parent_id'))
                ->paginate(10);

            if ($category) {
                $categories->getCollection()->transform(function ($cat) {
                    $cat->subCategories->transform(function ($subCategory) {
                        $subCategory->setRelation('sites', $subCategory->sites->take(15));
                        return $subCategory;
                    });
                    return $cat;
                });
            }

            return $categories;
        });
    }

    /**
     * Get emergency sites via subcategories of the 'emergency' category.
     * Used by LandingPageController.
     */
    public function getEmergencySites()
    {
        return Cache::remember('emergency_sites', 86400, function () {
            $category = Category::with('subCategories')->where('code', 'emergency')->first();

            if (!$category) {
                return collect();
            }

            $ids = $category->subCategories->pluck('id');

            return Site::whereHas('categories', function ($query) use ($ids) {
                $query->whereIn('id', $ids);
            })->get();
        });
    }
}
