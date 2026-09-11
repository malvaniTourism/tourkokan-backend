<?php

namespace Tests\Feature;

use App\Models\BusType;
use App\Models\Route;
use App\Models\RouteStops;
use App\Models\Site;
use Illuminate\Support\Facades\File;
use Tests\ApiTestCase;

/**
 * `routes:sync` is the safe, idempotent update path: correcting a Final CSV and re-running is
 * the whole workflow, and running it twice must change nothing. These pin the behaviours that
 * `routes:import` could not give — updating an existing route, reordering, dropping a removed
 * stop, and being a genuine no-op on an unchanged file.
 */
class RoutesSyncTest extends ApiTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        BusType::firstOrCreate(['type' => 'Ordinary Express'], ['logo' => null]);
        $this->dir = base_path('excels/Routes/Final');
        File::ensureDirectoryExists($this->dir);
    }

    /** Write a Final-shaped CSV with the given stop rows for one route. */
    private function writeCsv(string $taluka, string $routeNo, string $name, array $stops): string
    {
        $path = "{$this->dir}/{$taluka}_prod_routes.csv";
        $fh = fopen($path, 'w');
        fputcsv($fh, ['Route No', 'Route Name', 'From Stop Name', 'Till Stop Name',
                      'Bstop Name', 'dist_km', 'Departure_Time', 'Arrival_Time',
                      'Distance_KM', 'Frequency']);
        $from = $stops[0];
        $till = $stops[count($stops) - 1];
        foreach ($stops as $i => $stop) {
            fputcsv($fh, [$routeNo, $name, $from, $till, $stop, $i * 5,
                          '09:00', '10:00', ($i) * 5, 'DAILY']);
        }
        fclose($fh);
        return $path;
    }

    private function stopNames(int $routeId): array
    {
        return RouteStops::where('route_id', $routeId)->orderBy('serial_no')
            ->get()->map(fn($s) => Site::find($s->site_id)->getRawOriginal('name'))->all();
    }

    protected function tearDown(): void
    {
        File::delete("{$this->dir}/synctest_prod_routes.csv");
        parent::tearDown();
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->writeCsv('synctest', '9001', 'A To C', ['A', 'B', 'C']);

        $this->artisan('routes:sync synctest')->assertOk();

        $this->assertDatabaseMissing('routes', ['route_no' => '9001']);
    }

    public function test_apply_creates_the_route_and_ordered_stops(): void
    {
        $this->writeCsv('synctest', '9001', 'A To C', ['A', 'B', 'C']);

        $this->artisan('routes:sync synctest --apply')->assertOk();

        $route = Route::where('route_no', '9001')->firstOrFail();
        $this->assertSame(['A', 'B', 'C'], $this->stopNames($route->id));
    }

    public function test_reorder_and_rename_are_applied_not_appended(): void
    {
        $this->writeCsv('synctest', '9001', 'A To C', ['A', 'B', 'C']);
        $this->artisan('routes:sync synctest --apply');
        $route = Route::where('route_no', '9001')->firstOrFail();

        // Corrected CSV: B renamed to "B Mandir", order changed, C dropped, D added.
        $this->writeCsv('synctest', '9001', 'A To D', ['A', 'B Mandir', 'D']);
        $this->artisan('routes:sync synctest --apply')->assertOk();

        $this->assertSame(['A', 'B Mandir', 'D'], $this->stopNames($route->id),
            'Stops must match the corrected CSV exactly — not the old C, not a duplicated B.');
        // The route row itself is the same record, updated in place.
        $this->assertSame($route->id, Route::where('route_no', '9001')->first()->id);
        $this->assertSame('A To D', $route->fresh()->name);
    }

    public function test_running_twice_on_the_same_csv_is_a_no_op(): void
    {
        $this->writeCsv('synctest', '9001', 'A To C', ['A', 'B', 'C']);
        $this->artisan('routes:sync synctest --apply');
        $route = Route::where('route_no', '9001')->firstOrFail();
        $stopIdsAfterFirst = RouteStops::where('route_id', $route->id)->count();

        $this->artisan('routes:sync synctest --apply')->assertOk();

        $this->assertSame(1, Route::where('route_no', '9001')->count(), 'No duplicate route.');
        $this->assertSame($stopIdsAfterFirst, RouteStops::where('route_id', $route->id)->count(),
            'Re-running an unchanged CSV must not add or remove stops.');
        $this->assertSame(['A', 'B', 'C'], $this->stopNames($route->id));
    }

    public function test_consecutive_duplicate_stops_collapse(): void
    {
        $this->writeCsv('synctest', '9001', 'A To C', ['A', 'B', 'B', 'C']);
        $this->artisan('routes:sync synctest --apply');
        $route = Route::where('route_no', '9001')->firstOrFail();

        $this->assertSame(['A', 'B', 'C'], $this->stopNames($route->id),
            'The same stop twice in a row is one physical stop.');
    }
}
