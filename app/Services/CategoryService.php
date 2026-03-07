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

        // Always ensure 'id' is selected so eager loads work
        if ($fields !== ['*'] && !in_array('id', $fields)) {
            $fields[] = 'id';
        }

        $with = [
            'subCategories' => fn($q) => $q
                ->select('id', 'name', 'mr_name', 'code', 'parent_id', 'icon', 'is_hot_category')
                ->withCount('sites')
                ->has('sites')
                ->latest(),
        ];

        if ($category) {
            $with['subCategories.sites'] = fn($q) => $q->select('id', 'name', 'meta_data');
        }

        $query = Category::with($with)
            ->select($fields)
            ->withCount(['sites', 'subCategories'])
            ->whereNotIn('code', ['country', 'state', 'city', 'district', 'village', 'area'])
            ->whereStatus(true)
            ->latest()
            ->when($category, fn($q) => $q->where('code', $category))
            ->when(!$category, fn($q) => $q->whereNull('parent_id')->whereHas('subCategories.sites'));

        if (!$paginate) {
            return $query->get();
        }

        $categories = $query->paginate($perPage);

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
    }
}
