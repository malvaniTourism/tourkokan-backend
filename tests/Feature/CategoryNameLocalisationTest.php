<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Site;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A Marathi reader must get Marathi category names.
 *
 * `Category::getNameAttribute()` returns `mr_name` when the authenticated
 * user's language is Marathi — but ONLY if `mr_name` was selected. On a
 * partial eager-load it reads as null, `null == ""` is true in PHP, and the
 * accessor falls back to English with nothing to warn anyone.
 *
 * That is not hypothetical. It has been fixed three times: 65d6a5e did the
 * planner's candidate query, a later commit did the itinerary assembler it
 * had missed, and this one did the thirty-nine remaining selects across the
 * site, route, gallery and landing-page controllers. The structural test
 * below exists so there is not a fourth.
 */
class CategoryNameLocalisationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_marathi_reader_gets_the_marathi_category_name(): void
    {
        $category = Category::create([
            'name' => 'City', 'mr_name' => 'शहर', 'code' => 'city',
            'icon' => '', 'status' => true,
        ]);

        $owner = User::factory()->create(['language' => 'mr']);
        $site  = Site::create([
            'name'              => 'Devgad',
            'description'       => 'A Konkan town.',
            'user_id'           => $owner->id,
            'status'            => true,
            'submission_status' => 'approved',
            'latitude'          => 16.38,
            'longitude'         => 73.38,
        ]);
        $site->categories()->attach($category->id);

        $this->actingAs($owner, 'api');

        $loaded = Site::with(['categories:id,name,mr_name,code'])->find($site->id);

        $this->assertSame(
            'शहर',
            $loaded->categories->first()->name,
            'The accessor needs mr_name in the select or it silently serves English'
        );
    }

    public function test_an_english_reader_is_unaffected_by_carrying_mr_name(): void
    {
        $category = Category::create([
            'name' => 'City', 'mr_name' => 'शहर', 'code' => 'city',
            'icon' => '', 'status' => true,
        ]);

        $owner = User::factory()->create(['language' => 'en']);
        $site  = Site::create([
            'name'              => 'Devgad',
            'description'       => 'A Konkan town.',
            'user_id'           => $owner->id,
            'status'            => true,
            'submission_status' => 'approved',
            'latitude'          => 16.38,
            'longitude'         => 73.38,
        ]);
        $site->categories()->attach($category->id);

        $this->actingAs($owner, 'api');

        $loaded = Site::with(['categories:id,name,mr_name,code'])->find($site->id);

        $this->assertSame('City', $loaded->categories->first()->name);
    }

    /**
     * The structural half. A partial select that forgets `mr_name` is a bug
     * that shows up only to Marathi readers, on whichever screen happens to
     * use that query — so it is caught here, in the source, rather than by
     * someone noticing English on one page.
     */
    public function test_no_category_eager_load_forgets_the_marathi_name(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(base_path() . '/', '', $file->getPathname());

            // API/V1 is unrouted legacy: `route:list` has no api/v1 entry and
            // the controllers are not imported anywhere.
            if (str_contains($relative, 'Controllers/API/V1/')) {
                continue;
            }

            foreach (file($file->getPathname()) as $line) {
                // Commented-out code is not served to anyone, and a check that
                // cries wolf over it is a check people learn to ignore.
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '//')
                    || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '#')) {
                    continue;
                }

                // A partial eager-load of the category relation: the name
                // followed by a column list. The full relation (no colon)
                // loads every column and is fine.
                preg_match_all('/categor(?:ies|y):id,[a-z_,]+/', $line, $matches);

                foreach ($matches[0] as $select) {
                    if (!str_contains($select, 'name')) {
                        continue;                  // not selecting a name at all
                    }
                    if (str_contains($select, 'mr_name')) {
                        continue;                  // correct
                    }

                    $offenders[] = "{$relative}: {$select}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These selects load a category name without mr_name, so the accessor "
            . "cannot return Marathi and serves English instead:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * The same bug wearing different clothes.
     *
     * CategoryService takes its parent columns as a `fields` option that ends
     * up in `->select()`, so the relation-string check above cannot see it.
     * That is exactly how it survived the sweep: the subCategories eager-load
     * named mr_name and translated, the parents took the caller's list and
     * did not — Marathi readers got "Destination" above "शहर".
     */
    public function test_no_category_field_list_forgets_the_marathi_name(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(base_path() . '/', '', $file->getPathname());
            $source   = file_get_contents($file->getPathname());

            // `'fields' => ['id', 'name', ...]` — CategoryService's option.
            preg_match_all("/'fields'\s*=>\s*\[([^\]]*)\]/", $source, $matches);

            foreach ($matches[1] as $list) {
                if (!str_contains($list, "'name'")) {
                    continue;
                }
                if (str_contains($list, "'mr_name'")) {
                    continue;
                }

                $offenders[] = "{$relative}: fields => [{$list}]";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A category field list selects `name` without `mr_name`, so the "
            . "accessor answers in English whatever the reader's language:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * Sites and products, same trap, wider surface.
     *
     * Site translates THREE fields through accessors — name, tag_line and
     * description — each falling back to English when its mr_ column was not
     * selected. Products and product categories do not use an accessor at
     * all: the app localises them itself (`localised()` in
     * src/Services/marketplace.js reads obj.mr_name), so an omitted column
     * has the same effect by a different route — the app has nothing to
     * prefer and shows English.
     *
     * Relations are matched by name because that is what a select string
     * carries. A name here that stops pointing at a translatable model costs
     * a harmless extra column; one that is missing costs a Marathi reader.
     */
    public function test_no_site_or_product_select_forgets_its_translation(): void
    {
        $relations = [
            // -> Site
            'site', 'sites', 'sourcePlace', 'destinationPlace', 'liveSites', 'baseSite',
            // -> Category
            'category', 'categories', 'subCategories', 'allSubCategories',
            'placeCategory', 'siteCategories', 'children', 'parent',
            // -> Product / ProductCategory
            'product', 'products', 'productCategory',
        ];

        $pairs = [
            'name'        => 'mr_name',
            'tag_line'    => 'mr_tag_line',
            'description' => 'mr_description',
        ];

        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(base_path() . '/', '', $file->getPathname());

            // API/V1 is unrouted legacy — no route:list entry, no importer.
            if (str_contains($relative, 'Controllers/API/V1/')) {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '//')
                    || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '#')) {
                    continue;
                }

                preg_match_all("/'([A-Za-z_.]+):([a-z_,\s]+)'/", $line, $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $parts    = explode('.', $match[1]);
                    $relation = end($parts);

                    if (!in_array($relation, $relations, true)) {
                        continue;
                    }

                    $columns = array_map('trim', explode(',', $match[2]));

                    foreach ($pairs as $base => $translated) {
                        if (in_array($base, $columns, true)
                            && !in_array($translated, $columns, true)) {
                            $offenders[] = sprintf(
                                '%s:%d  %s: selects %s without %s',
                                $relative,
                                $number + 1,
                                $relation,
                                $base,
                                $translated
                            );
                        }
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These selects drop a translation column, so a Marathi reader gets "
            . "English — through Site's accessor, or through the app's own "
            . "localised() for products:\n  "
            . implode("\n  ", $offenders)
        );
    }
}
