<?php

namespace App\Console\Commands;

use App\Models\AllowedProductCategory;
use App\Models\Category;
use App\Models\Gallery;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLead;
use App\Models\ProductVariant;
use App\Models\ProductViewEvent;
use App\Models\Roles;
use App\Models\Site;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fills a local database with believable vendor data.
 *
 * Every listing is generated from the real taxonomy — the attributes come from each
 * category's own `attribute_schema` — so what you get exercises the same paths the app
 * does rather than a hand-written fixture that happens to look right.
 *
 *   php artisan demo:vendors                 seed the default 6 vendors
 *   php artisan demo:vendors --count=15
 *   php artisan demo:vendors --purge         remove everything it created
 *
 * Everything it writes is tagged in `meta_data.demo`, which is how --purge finds it again.
 * Nothing else is touched.
 */
class SeedDemoVendors extends Command
{
    protected $signature = 'demo:vendors
                            {--count=6 : How many vendors to create}
                            {--purge : Delete previously seeded demo data and stop}
                            {--days=21 : Days of view/lead history to generate}';

    protected $description = 'Seed believable vendor businesses, products and engagement for local testing';

    private const TAG = 'demo_vendor_seeder';

    /** Business names per site-category code, so a carpenter is not called "Sea View Resort". */
    private array $namesByCategory = [
        'hotel_rooms'    => ['Sagar Resort', 'Blue Wave Beach Stay', 'Konkan Kinara Resort', 'Dolphin View Hotel'],
        'restaurant'     => ['Malvani Katta', 'Samudra Thali House', 'Aai Khanaval', 'Fish Curry House'],
        'grocery_store'  => ['Kokan Kirana Stores', 'Ganesh Provision', 'Shree General Stores'],
        'carpenter'      => ['Patil Furniture Works', 'Kokan Woodcraft', 'Sawant Carpentry'],
        'electrician'    => ['Deshmukh Electricals', 'Powerline Services', 'Sai Electric Works'],
        'taxi_service'   => ['Konkan Cabs', 'Tarkarli Taxi Service', 'Sindhu Travels'],
        'tour_operator'  => ['Kokan Trails', 'Sea & Sand Tours', 'Malvan Holidays'],
        'handicraft_shop'=> ['Kokan Kalakruti', 'Shilpa Handicrafts'],
        'farm_produce'   => ['Devgad Alphonso Farm', 'Ratnagiri Mango Bagayat', 'Kokum Ghar'],
    ];

    private array $taglines = [
        'Family run since 1994', 'Right on the beach', 'Home-style Malvani food',
        'Trusted local service', 'Fresh from our own farm', 'Open all year round',
    ];

    public function handle(): int
    {
        if ($this->option('purge')) {
            return $this->purge();
        }

        if (ProductCategory::count() === 0 || AllowedProductCategory::count() === 0) {
            $this->error('The product taxonomy is empty. Seed it first:');
            $this->line('    php artisan db:seed --class=ProductCategorySeeder --force');
            $this->line('    php artisan db:seed --class=VendorCategorySeeder --force');

            return self::FAILURE;
        }

        $vendorRole = Roles::firstOrCreate(['code' => 'vendor'], ['name' => 'Vendor']);
        $touristRole = Roles::where('code', 'tourist')->first();

        // Only categories that actually permit something are usable.
        $usable = Category::whereIn('id', AllowedProductCategory::distinct()->pluck('category_id'))
            ->whereIn('code', array_keys($this->namesByCategory))
            ->get();

        if ($usable->isEmpty()) {
            $this->error('No site categories with a product whitelist were found. Run VendorCategorySeeder.');

            return self::FAILURE;
        }

        // Real villages/towns to hang the businesses off, so geo search has something to find.
        $parents = Site::whereNull('user_id')->whereNotNull('latitude')->inRandomOrder()->limit(40)->get();

        $count = max(1, (int) $this->option('count'));
        $made  = ['vendors' => 0, 'sites' => 0, 'products' => 0, 'views' => 0, 'leads' => 0];

        $this->info("Seeding {$count} demo vendors…");
        $bar = $this->output->createProgressBar($count);

        DB::transaction(function () use ($count, $usable, $parents, $vendorRole, $touristRole, &$made, $bar) {
            for ($i = 0; $i < $count; $i++) {
                $vendor = $this->makeVendor($vendorRole, $touristRole);
                $made['vendors']++;

                // Most vendors run one business; some run two or three.
                $outlets = random_int(1, 100) <= 55 ? 1 : random_int(2, 3);
                $primaryCategory = $usable->random();

                for ($o = 0; $o < $outlets; $o++) {
                    $category = $o === 0 ? $primaryCategory : $usable->random();
                    $site     = $this->makeSite($vendor, $category, $parents, isPrimary: $o === 0);
                    $made['sites']++;

                    foreach ($this->makeProducts($site, $category) as $product) {
                        $made['products']++;
                        [$v, $l] = $this->makeEngagement($product);
                        $made['views'] += $v;
                        $made['leads'] += $l;
                    }
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->line('');
        $this->line('');

        foreach ($made as $what => $n) {
            $this->line('   ' . str_pad(ucfirst($what), 10) . $n);
        }

        $this->line('');
        $this->comment('   Roll the engagement into the analytics tables with:');
        $this->comment('       php artisan products:rollup-stats --days=' . $this->option('days'));
        $this->comment('   Remove all of it again with:');
        $this->comment('       php artisan demo:vendors --purge');

        return self::SUCCESS;
    }

    // ── Creation ────────────────────────────────────────────────────────────────

    private function makeVendor(Roles $vendorRole, ?Roles $touristRole): User
    {
        $slug = Str::lower(Str::random(6));

        $user = User::create([
            'name'       => 'Demo Vendor ' . strtoupper($slug),
            'email'      => "demo+{$slug}@tourkokan.test",
            'mobile'     => (string) random_int(7000000000, 9999999999),
            'password'   => bcrypt('secret123'),
            'language'   => 'en',
            'isVerified' => true,
            'uid'        => Str::random(10),
        ]);

        if ($touristRole) {
            $user->roles()->attach($touristRole->id);
        }
        $user->roles()->attach($vendorRole->id);

        app(PlanService::class)->enrolOnFree($user);

        return $user;
    }

    private function makeSite(User $vendor, Category $category, $parents, bool $isPrimary): Site
    {
        $parent = $parents->random();
        $name   = $this->namesByCategory[$category->code][array_rand($this->namesByCategory[$category->code])];

        // Scatter around the parent so nearby-search returns a spread rather than a pile.
        $jitter = fn() => (random_int(-450, 450) / 10000);

        $site = Site::create([
            'name'              => $name . ' ' . strtoupper(Str::random(3)),
            'description'       => "{$name} is a locally run business in {$parent->name}, listed for demo purposes.",
            'tag_line'          => $this->taglines[array_rand($this->taglines)],
            'user_id'           => $vendor->id,
            'parent_id'         => $parent->id,
            'is_primary'        => $isPrimary,
            'status'            => true,
            'submission_status' => 'approved',
            'latitude'          => round((float) $parent->latitude + $jitter(), 6),
            'longitude'         => round((float) $parent->longitude + $jitter(), 6),
            'pin_code'          => (string) random_int(415600, 416999),
            'meta_data'         => ['demo' => self::TAG],
        ]);

        $site->categories()->attach($category->id);

        return $site;
    }

    /**
     * @return array<int, Product>
     */
    private function makeProducts(Site $site, Category $category): array
    {
        $allowed = AllowedProductCategory::where('category_id', $category->id)
            ->with('productCategory')
            ->get()
            ->pluck('productCategory')
            ->filter();

        if ($allowed->isEmpty()) {
            return [];
        }

        // Weighted so most listings are live; the rest give the moderation queue something
        // to show and the vendor dashboard a realistic mix.
        $statuses = ['approved', 'approved', 'approved', 'approved', 'pending', 'draft', 'rejected', 'paused'];
        $products = [];

        foreach (range(1, random_int(2, 6)) as $ignored) {
            $pc     = $allowed->random();
            $price  = $this->priceFor($pc->code);
            $status = $statuses[array_rand($statuses)];

            $product = Product::create([
                'site_id'             => $site->id,
                'product_category_id' => $pc->id,
                'name'                => $this->productName($pc),
                'slug'                => Str::slug($pc->name) . '-' . Str::lower(Str::random(6)),
                'description'         => "{$pc->name} offered by {$site->name}. Seeded for local testing.",
                'attributes'          => $this->attributesFor($pc),
                'base_price'          => $price,
                'sale_price'          => random_int(1, 4) === 1 ? round($price * 0.85, 2) : null,
                'unit'                => $this->unitFor($pc->code),
                'currency'            => 'INR',
                'tax_rate'            => [0, 5, 12, 18][array_rand([0, 5, 12, 18])],
                'status'              => $status,
                'is_featured'         => $status === 'approved' && random_int(1, 6) === 1,
                'rejection_reason'    => $status === 'rejected' ? 'Photos do not match the description.' : null,
                'sort_order'          => 0,
            ]);

            ProductVariant::create([
                'product_id' => $product->id, 'name' => 'Standard',
                'price' => $price, 'sale_price' => $product->sale_price,
                'is_default' => true, 'status' => true,
            ]);

            // A second price point on some, so variant handling gets exercised.
            if (random_int(1, 3) === 1) {
                ProductVariant::create([
                    'product_id' => $product->id, 'name' => 'Premium',
                    'price' => round($price * 1.4, 2), 'is_default' => false, 'status' => true,
                    'sort_order' => 1,
                ]);
            }

            // Gallery rows only — no files are written, so the paths will 404. Enough for
            // list/detail wiring, not for checking how an image renders.
            Gallery::create([
                'title'            => $product->name,
                'path'             => 'demo/products/placeholder.png',
                'status'           => true,
                'sort_order'       => 1,
                'is_cover'         => true,
                'galleryable_type' => Product::class,
                'galleryable_id'   => $product->id,
            ]);

            $products[] = $product;
        }

        return $products;
    }

    /**
     * Views and leads spread over recent days, so the analytics screens have a curve
     * instead of a single spike.
     *
     * @return array{0:int,1:int}
     */
    private function makeEngagement(Product $product): array
    {
        if ($product->status !== 'approved') {
            return [0, 0];
        }

        $days  = max(1, (int) $this->option('days'));
        $views = 0;
        $leads = 0;
        $types = ['call', 'call', 'whatsapp', 'whatsapp', 'directions', 'enquiry'];

        for ($d = 0; $d < $days; $d++) {
            $date = now()->subDays($d);

            foreach (range(1, random_int(0, 12)) as $ignored) {
                ProductViewEvent::create([
                    'product_id'   => $product->id,
                    'session_hash' => hash('sha256', 'demo' . random_int(1, 60) . $d),
                    'platform'     => ['android', 'ios', 'web'][array_rand(['android', 'ios', 'web'])],
                ])->forceFill(['created_at' => $date])->saveQuietly();
                $views++;
            }

            if (random_int(1, 100) <= 35) {
                foreach (range(1, random_int(1, 2)) as $ignored) {
                    ProductLead::create([
                        'product_id' => $product->id,
                        'lead_type'  => $types[array_rand($types)],
                        'platform'   => 'android',
                    ])->forceFill(['created_at' => $date, 'updated_at' => $date])->saveQuietly();
                    $leads++;
                }
            }
        }

        $product->forceFill(['views_count' => $views, 'leads_count' => $leads])->saveQuietly();

        return [$views, $leads];
    }

    // ── Value generation ────────────────────────────────────────────────────────

    /**
     * A valid value for every field the category declares — the same contract the app's
     * dynamic form fills in, so the seeded rows survive re-validation.
     */
    private function attributesFor(ProductCategory $category): array
    {
        $out = [];

        foreach ($category->attribute_schema ?? [] as $key => $spec) {
            $min = $spec['min'] ?? 1;
            $max = $spec['max'] ?? max($min + 1, 10);

            $out[$key] = match ($spec['type'] ?? 'string') {
                'int'     => random_int((int) $min, (int) $max),
                'decimal' => round(random_int((int) $min * 10, (int) $max * 10) / 10, 1),
                'bool'    => (bool) random_int(0, 1),
                'enum'    => ($spec['options'] ?? [null])[array_rand($spec['options'] ?? [null])],
                'multi'   => (array) array_slice(
                    collect($spec['options'] ?? [])->shuffle()->all(),
                    0,
                    max(1, random_int(1, min(3, count($spec['options'] ?? [1]))))
                ),
                'date'    => now()->addDays(random_int(0, 60))->toDateString(),
                'time'    => sprintf('%02d:00', random_int(6, 22)),
                'text'    => 'Seeded description for local testing.',
                default   => 'Demo',
            };
        }

        return array_filter($out, fn($v) => $v !== null && $v !== []);
    }

    private function productName(ProductCategory $pc): string
    {
        $prefix = [
            'room_night'       => ['Deluxe Sea View Room', 'Standard AC Room', 'Non-AC Family Room'],
            'menu_item'        => ['Malvani Fish Thali', 'Solkadhi', 'Prawn Koliwada', 'Chicken Sukka'],
            'thali'            => ['Veg Thali', 'Seafood Thali', 'Unlimited Malvani Thali'],
            'alphonso_mango'   => ['Alphonso Box (1 Dozen)', 'Alphonso Box (2 Dozen)'],
            'service_call'     => ['Furniture Repair Visit', 'Wiring Inspection', 'Home Service Call'],
            'taxi_transfer'    => ['Airport Transfer', 'Malvan Local Drop', 'Outstation Round Trip'],
            'vehicle_rental'   => ['Scooter Rental (Daily)', 'SUV with Driver'],
            'tour_package'     => ['2N/3D Tarkarli Package', 'Sindhudurg Fort Day Trip'],
            'retail_item'      => ['Kokum Syrup 500ml', 'Cashew W240 250g'],
            'boat_ride'        => ['Dolphin Watching Trip', 'Backwater Boat Ride'],
        ][$pc->code] ?? [$pc->name];

        return $prefix[array_rand($prefix)];
    }

    private function priceFor(string $code): float
    {
        return (float) match ($code) {
            'room_night', 'stay_package' => random_int(1200, 6500),
            'menu_item'                  => random_int(80, 450),
            'thali'                      => random_int(180, 550),
            'tour_package'               => random_int(3500, 18000),
            'taxi_transfer'              => random_int(800, 4500),
            'vehicle_rental'             => random_int(400, 2500),
            'service_call'               => random_int(300, 2000),
            'alphonso_mango'             => random_int(900, 3200),
            default                      => random_int(150, 2000),
        };
    }

    private function unitFor(string $code): string
    {
        return match ($code) {
            'room_night', 'stay_package'         => 'per_night',
            'menu_item', 'thali'                 => 'per_plate',
            'tour_package', 'boat_ride'          => 'per_person',
            'vehicle_rental'                     => 'per_hour',
            'alphonso_mango', 'farm_produce'     => 'per_kg',
            'service_call', 'repair_service'     => 'per_piece',
            default                              => 'per_piece',
        };
    }

    // ── Purge ───────────────────────────────────────────────────────────────────

    private function purge(): int
    {
        $sites = Site::whereJsonContains('meta_data->demo', self::TAG)->get();
        $users = User::where('email_hash', '!=', '')
            ->whereIn('id', $sites->pluck('user_id')->unique()->filter())
            ->get();

        if ($sites->isEmpty() && $users->isEmpty()) {
            $this->info('Nothing to purge.');

            return self::SUCCESS;
        }

        $productIds = Product::whereIn('site_id', $sites->pluck('id'))->pluck('id');

        DB::transaction(function () use ($sites, $users, $productIds) {
            Gallery::where('galleryable_type', Product::class)->whereIn('galleryable_id', $productIds)->delete();
            ProductViewEvent::whereIn('product_id', $productIds)->delete();
            ProductLead::whereIn('product_id', $productIds)->delete();
            DB::table('product_daily_stats')->whereIn('product_id', $productIds)->delete();
            ProductVariant::whereIn('product_id', $productIds)->delete();
            Product::whereIn('id', $productIds)->forceDelete();

            DB::table('category_site')->whereIn('site_id', $sites->pluck('id'))->delete();
            Site::whereIn('id', $sites->pluck('id'))->delete();

            foreach ($users as $user) {
                // A demo vendor is created directly so it has no wallet, but an account that
                // went through registration would — and that FK blocks the delete.
                DB::table('wallets')->where('user_id', $user->id)->delete();
                DB::table('user_roles')->where('user_id', $user->id)->delete();
                DB::table('vendor_subscriptions')->where('user_id', $user->id)->delete();
                DB::table('user_role_requests')->where('user_id', $user->id)->delete();
                User::where('id', $user->id)->forceDelete();
            }
        });

        $this->info("Purged {$users->count()} vendor(s), {$sites->count()} site(s), {$productIds->count()} product(s).");

        return self::SUCCESS;
    }
}
