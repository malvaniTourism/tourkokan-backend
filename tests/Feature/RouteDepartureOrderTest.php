<?php

namespace Tests\Feature;

use App\Models\Route;
use App\Models\RouteStops;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * Bus route search ordering.
 *
 * The case that matters is a traveller boarding mid-route: a bus that starts at 06:00 two
 * districts away can reach their stop *after* one that started at 08:00 nearby. Ordering on
 * `routes.start_time` would show those two in the wrong order, so the search orders on the
 * departure time at the stop the traveller actually boards at.
 */
class RouteDepartureOrderTest extends ApiTestCase
{
    private User $traveller;
    private Site $source;
    private Site $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traveller   = $this->userWithRole('user');
        $this->source      = $this->makeSite('Kankavli');
        $this->destination = $this->makeSite('Malvan');
    }

    private function makeSite(string $name): Site
    {
        return Site::create([
            'name'              => $name,
            'description'       => 'Stop used for route ordering tests.',
            'status'            => true,
            'submission_status' => 'approved',
        ]);
    }

    /**
     * @param  string  $startTime      the route's own origin departure
     * @param  string  $sourceDept     departure at the searched source stop
     */
    private function makeRoute(string $name, string $startTime, string $sourceDept): Route
    {
        $route = Route::create([
            'name'                 => $name,
            'source_place_id'      => $this->source->id,
            'destination_place_id' => $this->destination->id,
            'start_time'           => $startTime,
            'end_time'             => '20:00:00',
            'status'               => true,
        ]);

        RouteStops::create([
            'route_id'  => $route->id,
            'site_id'   => $this->source->id,
            'serial_no' => 1,
            'dept_time' => $sourceDept,
            'distance'  => 0,
        ]);

        RouteStops::create([
            'route_id'  => $route->id,
            'site_id'   => $this->destination->id,
            'serial_no' => 2,
            'dept_time' => '19:00:00',
            'distance'  => 40,
        ]);

        return $route;
    }

    private function search(array $payload = [])
    {
        return $this->actingAs($this->traveller, 'api')
            ->postJson('/api/v2/routes', $payload);
    }

    public function test_results_are_ordered_by_departure_from_the_searched_source(): void
    {
        // Deliberately inserted out of order, and with origin times that disagree with the
        // order at the boarding stop.
        $this->makeRoute('Late at source',  '06:00:00', '14:00:00');
        $this->makeRoute('First at source', '08:00:00', '09:00:00');
        $this->makeRoute('Mid at source',   '07:00:00', '11:30:00');

        $response = $this->assertApiSuccess($this->search([
            'source_place_id'      => $this->source->id,
            'destination_place_id' => $this->destination->id,
        ]));

        $names = collect($response->json('data.data'))->pluck('name')->all();

        $this->assertSame(
            ['First at source', 'Mid at source', 'Late at source'],
            $names,
            'Ordering must follow departure at the boarding stop, not the route origin time.'
        );
    }

    public function test_routes_with_no_recorded_time_sort_last_not_first(): void
    {
        // A duty sheet with no usable departure imports as 00:00:00, not NULL, because
        // routes.start_time is NOT NULL. Sorted naively that reads as a midnight bus and
        // leads every result, pushing the real 05:00 departures below it.
        $this->makeRoute('Unknown time', '00:00:00', '00:00:00');
        $this->makeRoute('Early bus',    '05:00:00', '05:10:00');
        $this->makeRoute('Later bus',    '09:00:00', '09:10:00');

        $names = collect($this->assertApiSuccess($this->search())->json('data.data'))
            ->pluck('name')->all();

        $this->assertSame(['Early bus', 'Later bus', 'Unknown time'], $names,
            'A 00:00:00 start time means "time unknown" and must sort last, not as midnight.');
    }

    public function test_unknown_boarding_time_sorts_last_when_searching_from_a_stop(): void
    {
        $this->makeRoute('No time at stop', '06:00:00', '00:00:00');
        $this->makeRoute('Boards at 07:00', '08:00:00', '07:00:00');
        $this->makeRoute('Boards at 10:00', '05:00:00', '10:00:00');

        $names = collect($this->assertApiSuccess($this->search([
            'source_place_id'      => $this->source->id,
            'destination_place_id' => $this->destination->id,
        ]))->json('data.data'))->pluck('name')->all();

        $this->assertSame(['Boards at 07:00', 'Boards at 10:00', 'No time at stop'], $names,
            'An unknown departure at the boarding stop must sort last, not as midnight.');
    }

    public function test_the_source_departure_time_is_returned(): void
    {
        $this->makeRoute('Morning', '06:00:00', '09:15:00');

        $response = $this->assertApiSuccess($this->search([
            'source_place_id'      => $this->source->id,
            'destination_place_id' => $this->destination->id,
        ]));

        $row = $response->json('data.data.0');

        $this->assertArrayHasKey('source_dept_time', $row);
        $this->assertSame('09:15:00', $row['source_dept_time']);
    }

    /**
     * The ordering carries a `IS NULL` guard so an absent time sorts last. There is no test
     * for it because `route_stops.dept_time` is NOT NULL, so the case is unreachable through
     * the schema — the guard covers only a route whose source stop is missing entirely, which
     * the search's own filter already excludes. It is kept as cheap insurance against
     * inconsistent data, not as live behaviour.
     */
    public function test_an_unfiltered_search_orders_by_route_start_time(): void
    {
        $this->makeRoute('Second', '11:00:00', '11:00:00');
        $this->makeRoute('First',  '07:00:00', '07:00:00');

        $response = $this->assertApiSuccess($this->search());

        $names = collect($response->json('data.data'))->pluck('name')->all();

        $this->assertSame(['First', 'Second'], $names);
    }

    public function test_listroutes_is_also_ordered_by_start_time(): void
    {
        $this->makeRoute('Later',  '15:00:00', '15:00:00');
        $this->makeRoute('Sooner', '05:30:00', '05:30:00');

        $response = $this->assertApiSuccess(
            $this->actingAs($this->traveller, 'api')->postJson('/api/v2/listroutes')
        );

        $names = collect($response->json('data.data'))->pluck('name')->all();

        $this->assertSame(['Sooner', 'Later'], $names);
    }
}
