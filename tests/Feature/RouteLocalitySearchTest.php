<?php

namespace Tests\Feature;

use App\Models\Route;
use App\Models\RouteStops;
use App\Models\Site;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * Locality-aware route search: a search for "Are" finds a route that stops at "Are School"
 * or "Are Mandir" (same village), but NOT one that only passes "Are Fata" (the junction).
 * A stop with no locality keeps exact-match behaviour, so nothing else changes.
 */
class RouteLocalitySearchTest extends ApiTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->userWithRole('user');
    }

    private function site(string $name, ?string $locality = null): Site
    {
        return Site::create(['name' => $name, 'locality' => $locality, 'status' => true]);
    }

    private function route(string $name, array $stops): Route
    {
        $route = Route::create([
            'name' => $name, 'source_place_id' => $stops[0]->id,
            'destination_place_id' => end($stops)->id, 'status' => true,
            'start_time' => '09:00:00', 'end_time' => '10:00:00',
        ]);
        foreach ($stops as $i => $s) {
            RouteStops::create([
                'route_id' => $route->id, 'site_id' => $s->id, 'serial_no' => $i + 1,
                'distance' => $i * 5, 'arr_time' => '00:00:00', 'dept_time' => '00:00:00',
            ]);
        }
        return $route;
    }

    private function search(int $from, int $to): array
    {
        return $this->actingAs($this->user, 'api')
            ->postJson('/api/v2/routes', ['source_place_id' => $from, 'destination_place_id' => $to])
            ->json('data.data');
    }

    public function test_a_search_for_the_locality_finds_routes_stopping_at_any_sub_stop(): void
    {
        $devgad   = $this->site('Devgad');
        $are      = $this->site('Are', 'Are');
        $areSchool= $this->site('Are School', 'Are');
        $areMandir= $this->site('Are Mandir', 'Are');
        $nirom    = $this->site('Nirom');

        // Route stops at Are School / Are Mandir — never the bare "Are".
        $r = $this->route('Devgad To Nirom', [$devgad, $areSchool, $areMandir, $nirom]);

        $names = collect($this->search($devgad->id, $are->id))->pluck('name');

        $this->assertContains('Devgad To Nirom', $names,
            'Searching "Are" must find a route that stops at Are School / Are Mandir.');
    }

    public function test_a_junction_only_route_is_not_returned_for_the_village(): void
    {
        $devgad  = $this->site('Devgad');
        $are     = $this->site('Are', 'Are');
        $this->site('Are School', 'Are');
        $areFata = $this->site('Are Fata', 'Are Fata'); // own locality — the junction
        $malvan  = $this->site('Malvan');

        // Bus only passes the junction on its way to Malvan.
        $this->route('Devgad To Malvan Via Are Fata', [$devgad, $areFata, $malvan]);

        $names = collect($this->search($devgad->id, $are->id))->pluck('name');

        $this->assertNotContains('Devgad To Malvan Via Are Fata', $names,
            'A bus that only passes Are Fata must not be returned as serving Are village.');
    }

    public function test_a_stop_without_a_locality_still_matches_exactly(): void
    {
        $a = $this->site('Alpha');       // no locality
        $b = $this->site('Beta');        // no locality
        $c = $this->site('Gamma');
        $this->route('Alpha To Beta', [$a, $b, $c]);

        $this->assertNotEmpty($this->search($a->id, $b->id), 'Exact match still works.');
        $this->assertEmpty($this->search($b->id, $a->id), 'Direction still respected (b after a).');
    }
}
