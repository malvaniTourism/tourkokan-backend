<?php

namespace Tests\Feature;

use App\Models\Site;
use Tests\ApiTestCase;

/**
 * routes:group-localities groups a village's landmarks under it, but only when the bare
 * village stop actually exists. A namesake with no bare stop — "Ambedkar Chowk" /
 * "Ambedkar Nagar" (named after a person, in different places) — must never merge.
 */
class GroupStopLocalitiesTest extends ApiTestCase
{
    public function test_a_real_village_with_a_bare_stop_is_grouped(): void
    {
        Site::create(['name' => 'Are', 'status' => true]);
        Site::create(['name' => 'Are School', 'status' => true]);
        Site::create(['name' => 'Are Mandir', 'status' => true]);

        $this->artisan('routes:group-localities --apply')->assertOk();

        $this->assertSame('Are', Site::where('name', 'Are')->value('locality'));
        $this->assertSame('Are', Site::where('name', 'Are School')->value('locality'));
        $this->assertSame('Are', Site::where('name', 'Are Mandir')->value('locality'));
    }

    public function test_a_namesake_with_no_bare_stop_is_not_grouped(): void
    {
        Site::create(['name' => 'Ambedkar Chowk', 'status' => true]);
        Site::create(['name' => 'Ambedkar Nagar', 'status' => true]);
        Site::create(['name' => 'Ambedkar College', 'status' => true]);

        $this->artisan('routes:group-localities --apply')->assertOk();

        foreach (['Ambedkar Chowk', 'Ambedkar Nagar', 'Ambedkar College'] as $name) {
            $this->assertNull(Site::where('name', $name)->value('locality'),
                "{$name} must stay ungrouped — there is no bare 'Ambedkar' place.");
        }
    }

    public function test_a_junction_is_not_pulled_into_the_village(): void
    {
        Site::create(['name' => 'Are', 'status' => true]);
        Site::create(['name' => 'Are School', 'status' => true]);
        Site::create(['name' => 'Are Fata', 'status' => true]);

        $this->artisan('routes:group-localities --apply')->assertOk();

        $this->assertSame('Are', Site::where('name', 'Are School')->value('locality'));
        $this->assertNull(Site::where('name', 'Are Fata')->value('locality'),
            'A junction is its own place, never part of the village group.');
    }

    public function test_generic_civic_names_never_group(): void
    {
        Site::create(['name' => 'Tahsil Office, Devgad', 'status' => true]);
        Site::create(['name' => 'Tahsil Office, Malvan', 'status' => true]);

        $this->artisan('routes:group-localities --apply')->assertOk();

        $this->assertNull(Site::where('name', 'Tahsil Office, Devgad')->value('locality'));
        $this->assertNull(Site::where('name', 'Tahsil Office, Malvan')->value('locality'));
    }
}
