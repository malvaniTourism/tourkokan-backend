<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\Site;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Walks the whole app-side vendor journey against a running server, pausing wherever an
 * admin has to act so the approval can be done by hand in the admin panel.
 *
 * Every call goes over real HTTP to the URL you point it at, so what passes here is what the
 * mobile app will get. The one exception is the account itself: a verified dummy user is
 * seeded directly, because registration needs an OTP round trip the script cannot complete.
 *
 *   php artisan vendor:walkthrough
 *   php artisan vendor:walkthrough --url=https://api.tourkokan.com --email=me@example.com
 *
 * See docs/app-api-integration.md and docs/vendor-products-api.md.
 */
class VendorWalkthrough extends Command
{
    protected $signature = 'vendor:walkthrough
                            {--url=http://127.0.0.1:8000 : Base URL of the running API}
                            {--email= : Reuse an existing account instead of seeding a dummy one}
                            {--password=secret123 : Password for the account}
                            {--keep : Leave the created data behind when finished}';

    protected $description = 'Interactive end-to-end walkthrough of the vendor product flow';

    private string $base;
    private ?string $token = null;
    private array $created = [];

    public function handle(): int
    {
        $this->base = rtrim($this->option('url'), '/');

        $this->line('');
        $this->info('  Tourkokan — vendor journey walkthrough');
        $this->line("  API: {$this->base}");
        $this->line('');
        $this->comment('  This drives the APP side only. Whenever an admin has to approve');
        $this->comment('  something, the script stops and waits for you to do it in the');
        $this->comment('  admin panel, then continues when you confirm.');
        $this->line('');

        if (!$this->serverIsUp()) {
            $this->error("  Cannot reach {$this->base}. Start the server first:");
            $this->line('      php artisan serve');

            return self::FAILURE;
        }

        try {
            $user = $this->step1_account();

            if ($user->fresh()->load('roles')->hasRole('vendor')) {
                $this->heading(2, 'Vendor role');
                $this->ok('already granted — skipping the request and approval steps');
            } else {
                $this->step2_requestVendorRole();
                $this->step3_waitForRoleApproval($user);
            }
            $site     = $this->step4_addSite();
            $this->step5_waitForSiteApproval($site);
            [$category, $schema] = $this->step6_pickCategory($site);
            $product  = $this->step7_addProduct($site, $category, $schema);
            $this->step8_uploadImage($product);
            $this->step9_submitForReview($product);
            $this->step10_waitForProductApproval($product);
            $this->step11_verifyPublic($product);
        } catch (\Throwable $e) {
            $this->line('');
            $this->error('  Stopped: ' . $e->getMessage());
            $this->summary();

            return self::FAILURE;
        }

        $this->summary();
        $this->cleanup();

        return self::SUCCESS;
    }

    // ── Steps ───────────────────────────────────────────────────────────────────

    private function step1_account(): User
    {
        $this->heading(1, 'Account');

        $password = $this->option('password');

        $user = $this->option('email')
            ? $this->existingUser($this->option('email'))
            : $this->makeDummyUser($password);

        // Everything from here on is the API. Only the account itself is seeded locally.
        $login = $this->post('auth/login', [
            'email'    => $this->plainEmail($user),
            'password' => $password,
        ], auth: false);

        // The JWT comes back as data.access_token, not data.token.
        $this->token = data_get($login, 'data.access_token');

        if (!$this->token) {
            throw new \RuntimeException('Login returned no token: ' . json_encode($login));
        }

        $this->ok("logged in — user #{$user->id}");

        return $user;
    }

    /**
     * Seed a verified dummy account directly, rather than going through registration.
     *
     * Registration would need an OTP round trip the script cannot complete, and creates a
     * wallet row that complicates cleanup. A fresh account per run also keeps re-runs
     * clean: an account that already holds the vendor role cannot request it again.
     */
    private function makeDummyUser(string $password): User
    {
        $email  = 'walkthrough+' . Str::lower(Str::random(6)) . '@tourkokan.test';
        $mobile = (string) random_int(7000000000, 9999999999);

        $user = User::create([
            'name'       => 'Walkthrough Vendor',
            'email'      => $email,
            'mobile'     => $mobile,
            'password'   => bcrypt($password),
            'language'   => 'en',
            'isVerified' => true,          // seeded verified — no OTP step to complete
            'uid'        => Str::random(10),
        ]);

        // Mirror what registration assigns, so the account starts exactly like a real one.
        if ($tourist = \App\Models\Roles::where('code', 'tourist')->first()) {
            $user->roles()->attach($tourist->id);
        }

        $this->created['user'] = $user->id;
        $this->ok("seeded dummy user #{$user->id} — {$email} / {$password}");
        $this->line('      verified, role: tourist (as a real registration would leave it)');

        return $user;
    }

    private function existingUser(string $email): User
    {
        $user = User::findByEmail($email);

        if (!$user) {
            throw new \RuntimeException("No user found for {$email}.");
        }

        if (!$user->isVerified) {
            $user->forceFill(['isVerified' => true])->saveQuietly();
            $this->warn('      account was unverified — marked verified so login can proceed');
        }

        $this->created['user'] = $user->id;
        $this->ok("reusing user #{$user->id} — {$email}");

        return $user;
    }

    /** Email is encrypted at rest; the accessor gives the plain value back. */
    private function plainEmail(User $user): string
    {
        return $user->email;
    }

    private function step2_requestVendorRole(): void
    {
        $this->heading(2, 'Request the vendor role');

        $res = $this->post('requestRole', [
            'role_code' => 'vendor',
            'reason'    => 'Walkthrough: I run a resort in Tarkarli and want to list my rooms.',
        ]);

        $this->ok(data_get($res, 'message'));
    }

    private function step3_waitForRoleApproval(User $user): void
    {
        $this->pause(
            'Admin panel → Role Requests → approve the pending "vendor" request',
            fn() => $user->fresh()->load('roles')->hasRole('vendor'),
            'the user still does not have the vendor role'
        );
        $this->ok('vendor role granted');
    }

    private function step4_addSite(): Site
    {
        $this->heading(4, 'Submit the business as a site');

        $categoryId = $this->chooseSiteCategory();

        $res = $this->post('addSite', [
            'name'        => 'Walkthrough Resort ' . Str::random(4),
            'description' => 'A sea-facing resort in Tarkarli created by the walkthrough script.',
            'categories'  => [$categoryId],
            'latitude'    => 16.0512,
            'longitude'   => 73.4680,
            'pin_code'    => '416606',
        ]);

        $siteId = data_get($res, 'data.id');
        $site   = Site::find($siteId);
        $this->created['site'] = $siteId;

        $this->ok(data_get($res, 'message'));
        $this->line("      site #{$siteId} — status: " . data_get($res, 'data.submission_status'));

        return $site;
    }

    private function step5_waitForSiteApproval(Site $site): void
    {
        $this->line('');
        $this->comment('  Note: you can already add products while the site is pending —');
        $this->comment('  they just cannot be approved until the site is live. This script');
        $this->comment('  waits so the whole flow is visible in order.');

        $this->pause(
            "Admin panel → Pending Sites → approve \"{$site->name}\" (site #{$site->id})",
            fn() => $site->fresh()->submission_status === 'approved' && $site->fresh()->status,
            'the site is not approved and published yet'
        );

        $fresh = $site->fresh();
        $this->ok('site approved and live' . ($fresh->is_primary ? ' — auto-marked primary' : ''));
    }

    private function step6_pickCategory(Site $site): array
    {
        $this->heading(6, 'What can this outlet sell?');

        $allowed = $this->post('allowedProductCategories', ['site_id' => $site->id]);
        $options = data_get($allowed, 'data', []);

        if (empty($options)) {
            throw new \RuntimeException(
                "No product categories are allowed for this site's categories. "
                . 'Run: php artisan db:seed --class=VendorCategorySeeder'
            );
        }

        $this->ok(count($options) . ' category/categories available:');
        foreach ($options as $o) {
            $this->line("      · {$o['name']}  ({$o['code']}, booking_type: {$o['booking_type']})");
        }

        $chosen = $options[0];
        $this->line('');
        $this->ok("using: {$chosen['name']}");

        $schemaRes = $this->post('categoryAttributeSchema', ['product_category_id' => $chosen['id']]);
        $schema    = data_get($schemaRes, 'data.attribute_schema', []);

        $this->ok('attribute schema — the app renders its form from this:');
        foreach ($schema as $key => $spec) {
            $req = ($spec['required'] ?? false) ? ' *required' : '';
            $opt = isset($spec['options']) ? ' [' . implode('/', $spec['options']) . ']' : '';
            $this->line("      · {$key}: {$spec['type']}{$opt} — \"{$spec['label']}\"{$req}");
        }

        return [$chosen, $schema];
    }

    private function step7_addProduct(Site $site, array $category, array $schema): Product
    {
        $this->heading(7, 'Add the product');

        $attributes = $this->fabricateAttributes($schema);
        $this->line('      generated attributes: ' . json_encode($attributes));

        $res = $this->post('addProduct', [
            'site_id'             => $site->id,
            'product_category_id' => $category['id'],
            'name'                => 'Walkthrough Room ' . Str::random(4),
            'description'         => 'Created by the walkthrough script to exercise the vendor flow.',
            'base_price'          => 2400,
            'unit'                => 'per_night',
            'attributes'          => $attributes,
        ]);

        $productId = data_get($res, 'data.id');
        $this->created['product'] = $productId;

        $this->ok(data_get($res, 'message'));
        $this->line("      product #{$productId} — status: " . data_get($res, 'data.status'));

        $variant = data_get($res, 'data.variants.0');
        if ($variant) {
            $this->ok("default variant auto-created: \"{$variant['name']}\" at {$variant['price']}");
        }

        return Product::find($productId);
    }

    private function step8_uploadImage(Product $product): void
    {
        $this->heading(8, 'Upload a photo');

        $path = storage_path('app/walkthrough-sample.png');
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        $res = Http::withToken($this->token)
            ->acceptJson()
            ->attach('image', file_get_contents($path), 'room.png')
            ->post("{$this->base}/api/v2/uploadProductMedia", ['id' => $product->id])
            ->json();

        @unlink($path);

        if (!data_get($res, 'success')) {
            throw new \RuntimeException('uploadProductMedia: ' . json_encode(data_get($res, 'message')));
        }

        $this->ok('image uploaded' . (data_get($res, 'data.is_cover') ? ' — became the cover' : ''));
    }

    private function step9_submitForReview(Product $product): void
    {
        $this->heading(9, 'Submit for review');

        $res = $this->post('submitProductForReview', ['id' => $product->id]);

        $this->ok(data_get($res, 'message'));
        $this->line('      status: ' . $product->fresh()->status);
    }

    private function step10_waitForProductApproval(Product $product): void
    {
        $this->pause(
            "Admin panel → Pending Products → approve \"{$product->name}\" (product #{$product->id})",
            fn() => $product->fresh()->status === 'approved',
            'the product is not approved yet'
        );
        $this->ok('product approved');
    }

    private function step11_verifyPublic(Product $product): void
    {
        $this->heading(11, 'Confirm it is publicly visible');

        $list = $this->post('listProducts', ['search' => $product->name]);
        $rows = data_get($list, 'data.data', []);

        if (empty($rows)) {
            throw new \RuntimeException('The approved product does not appear in listProducts.');
        }

        $row = $rows[0];
        $this->ok("found in the public catalog: {$row['name']}");
        $this->line('      price shown to a tourist: '
            . (data_get($row, 'default_variant.sale_price') ?? data_get($row, 'default_variant.price'))
            . ' ' . $row['currency'] . ' ' . $row['unit']);

        $detail = $this->post('productDetail', ['id' => $product->id]);
        $this->ok('productDetail returned ' . count(data_get($detail, 'data.variants', [])) . ' variant(s), '
            . count(data_get($detail, 'data.gallery', [])) . ' image(s)');

        $this->post('recordProductView', ['id' => $product->id, 'platform' => 'walkthrough']);
        $this->post('recordProductLead', ['id' => $product->id, 'lead_type' => 'whatsapp',
                                          'message' => 'Is this available next weekend?']);

        $fresh = $product->fresh();
        $this->ok("engagement recorded — views: {$fresh->views_count}, leads: {$fresh->leads_count}");
        $this->line('');
        $this->comment('      (vendor analytics stay at zero until the nightly rollup runs:');
        $this->comment('       php artisan products:rollup-stats)');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /**
     * Build a valid value for every field the schema declares, so the request exercises the
     * same path the app's dynamic form takes.
     */
    private function fabricateAttributes(array $schema): array
    {
        $out = [];

        foreach ($schema as $key => $spec) {
            $out[$key] = match ($spec['type'] ?? 'string') {
                'int'     => (int) ($spec['min'] ?? 1),
                'decimal' => (float) ($spec['min'] ?? 1),
                'bool'    => true,
                'enum'    => $spec['options'][0] ?? null,
                'multi'   => array_slice($spec['options'] ?? [], 0, 1),
                'date'    => now()->toDateString(),
                'time'    => '12:00',
                'text'    => 'Filled in by the walkthrough script.',
                default   => 'Walkthrough',
            };
        }

        return array_filter($out, fn($v) => $v !== null);
    }

    private function chooseSiteCategory(): int
    {
        // Prefer a leaf business category the seeder wires to product categories.
        foreach (['hotel_rooms', 'restaurant', 'grocery_store', 'carpenter'] as $code) {
            if ($c = Category::where('code', $code)->first()) {
                $this->ok("site category: {$c->name} ({$code})");

                return $c->id;
            }
        }

        $c = Category::whereNotNull('parent_id')->first();

        if (!$c) {
            throw new \RuntimeException('No site categories exist. Run: php artisan db:seed --class=CategorySeeder');
        }

        $this->warn("      falling back to site category: {$c->name}");

        return $c->id;
    }

    private function post(string $endpoint, array $payload, bool $auth = true): array
    {
        $req = Http::acceptJson()->timeout(20);
        if ($auth && $this->token) {
            $req = $req->withToken($this->token);
        }

        $res  = $req->post("{$this->base}/api/v2/{$endpoint}", $payload);
        $json = $res->json() ?? [];

        // This API answers 200 on failure, so the flag is the only reliable signal.
        if (($json['success'] ?? false) !== true) {
            $msg = $json['message'] ?? $res->body();
            throw new \RuntimeException(
                "{$endpoint} failed (HTTP {$res->status()}): "
                . (is_string($msg) ? $msg : json_encode($msg))
            );
        }

        return $json;
    }

    /**
     * Stop until the admin has acted, then verify rather than trust — re-prompting if the
     * approval has not actually landed.
     */
    private function pause(string $instruction, callable $check, string $failureHint): void
    {
        $this->line('');
        $this->line('  ' . str_repeat('─', 66));
        $this->warn('   WAITING FOR YOU');
        $this->line("   {$instruction}");
        $this->line('  ' . str_repeat('─', 66));

        while (true) {
            if (!$this->confirm('   Done? Continue to the next step', true)) {
                throw new \RuntimeException('Stopped at your request.');
            }

            if ($check()) {
                return;
            }

            $this->error("   Checked, but {$failureHint}.");
            $this->line('   Approve it in the panel and try again (Ctrl+C to abort).');
        }
    }

    private function serverIsUp(): bool
    {
        try {
            Http::timeout(5)->get("{$this->base}/up");

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function heading(int $n, string $title): void
    {
        $this->line('');
        $this->info("  [{$n}] {$title}");
    }

    private function ok(string $msg): void
    {
        $this->line("      <fg=green>✔</> {$msg}");
    }

    private function summary(): void
    {
        $this->line('');
        $this->line('  ' . str_repeat('─', 66));
        $this->info('   Created during this run');
        foreach ($this->created as $type => $id) {
            $this->line('   ' . str_pad($type, 10) . '#' . $id);
        }
        $this->line('  ' . str_repeat('─', 66));
    }

    private function cleanup(): void
    {
        if ($this->option('keep')) {
            $this->comment('   --keep given, leaving the data in place.');

            return;
        }

        if (!$this->confirm('   Delete the data this run created?', false)) {
            return;
        }

        // Never let a cleanup problem look like a failed run — by this point everything
        // being tested has already passed.
        try {
            Product::where('id', $this->created['product'] ?? 0)->forceDelete();
            Site::where('id', $this->created['site'] ?? 0)->delete();

            if (!$this->option('email')) {
                $id = $this->created['user'] ?? 0;
                DB::table('user_roles')->where('user_id', $id)->delete();
                DB::table('vendor_subscriptions')->where('user_id', $id)->delete();
                DB::table('user_role_requests')->where('user_id', $id)->delete();

                // The seeded account owns nothing else, so it can go entirely. Falls back
                // to a soft delete if some other table turns out to reference it.
                try {
                    User::where('id', $id)->forceDelete();
                } catch (\Throwable) {
                    User::where('id', $id)->delete();
                }
            }

            $this->ok('cleaned up');
        } catch (\Throwable $e) {
            $this->warn('   Cleanup could not finish: ' . $e->getMessage());
            $this->line('   The run itself succeeded — remove the ids above by hand if needed.');
        }
    }
}
