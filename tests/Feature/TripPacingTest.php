<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\Category;
use App\Models\Favourite;
use App\Models\SavedTrip;
use App\Models\Site;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Trip\ItineraryPrompt;
use App\Services\Trip\Pacing;
use App\Services\Trip\TripPlanner;
use Tests\ApiTestCase;

/**
 * How many stops a day, and who decides.
 *
 * The point of `Pacing` is that one object answers for every consumer. These
 * cover both halves: that the number is derived from the group at all, and
 * that nothing downstream can quietly use a different one — which is the
 * failure the whole refactor exists to prevent, and which `php -l` and a
 * green happy path will both miss.
 */
class TripPacingTest extends ApiTestCase
{
    private User $traveller;
    private Category $beach;
    private Site $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traveller = $this->userWithRole('user');
        app(CreditLedger::class)->grant($this->traveller, 20, 'test');

        $this->beach = Category::create([
            'name' => 'Beach', 'mr_name' => 'समुद्रकिनारा', 'code' => 'beach',
            'icon' => '', 'status' => true,
        ]);

        $this->base = $this->site('Malvan', 16.0590, 73.4680);
    }

    private function site(string $name, float $lat, float $lng): Site
    {
        $site = Site::create([
            'name'              => $name,
            'description'       => 'A Konkan place used for testing pacing.',
            'user_id'           => $this->traveller->id,
            'status'            => true,
            'submission_status' => 'approved',
            'latitude'          => $lat,
            'longitude'         => $lng,
        ]);

        $site->categories()->attach($this->beach->id);

        return $site;
    }

    // ── What the group implies ───────────────────────────────────────────────

    public function test_a_couple_keeps_the_ordinary_pace(): void
    {
        $pacing = Pacing::forFilters(['trip_type' => 'couple', 'group' => ['adults' => 2]]);

        $this->assertSame(2, $pacing->minPerDay);
        $this->assertSame(5, $pacing->maxPerDay);
    }

    public function test_a_family_slows_down(): void
    {
        $pacing = Pacing::forFilters(['trip_type' => 'family', 'group' => ['adults' => 2]]);

        $this->assertSame(4, $pacing->maxPerDay);
    }

    public function test_a_young_child_slows_down_further(): void
    {
        $pacing = Pacing::forFilters([
            'group' => ['adults' => 2, 'children' => 1, 'youngest_age' => 3],
        ]);

        $this->assertSame(3, $pacing->maxPerDay);
    }

    public function test_a_large_group_slows_down_whatever_the_type(): void
    {
        $pacing = Pacing::forFilters([
            'trip_type' => 'friends',
            'group'     => ['adults' => 9],
        ]);

        $this->assertSame(3, $pacing->maxPerDay);
    }

    public function test_the_lowest_ceiling_wins_rather_than_the_last_one_checked(): void
    {
        // A big family with a toddler qualifies for three rules at once.
        $pacing = Pacing::forFilters([
            'trip_type' => 'family',
            'group'     => ['adults' => 6, 'children' => 4, 'youngest_age' => 2],
        ]);

        $this->assertSame(3, $pacing->maxPerDay, 'The toddler sets the pace, not the family rule');
    }

    public function test_the_floor_never_moves(): void
    {
        // Even if an admin sets every ceiling below the floor, a day with
        // fewer than min_stops_per_day is not a day out, and the assembler
        // rejects it.
        AiConfig::set('pacing_max_young_child', 1);

        $pacing = Pacing::forFilters(['group' => ['adults' => 2, 'children' => 1, 'youngest_age' => 1]]);

        $this->assertSame(2, $pacing->minPerDay);
        $this->assertGreaterThanOrEqual($pacing->minPerDay, $pacing->maxPerDay);
    }

    public function test_an_unknown_trip_type_changes_nothing(): void
    {
        // A retired code from a stale app gets the ordinary pace, not an
        // error — the same habit that keeps `exists` off the category codes.
        $pacing = Pacing::forFilters(['trip_type' => 'pilgrimage_2019']);

        $this->assertSame(5, $pacing->maxPerDay);
    }

    public function test_answering_nothing_is_todays_behaviour(): void
    {
        $this->assertEquals(Pacing::default(), Pacing::forFilters([]));
    }

    // ── That nothing downstream uses a different number ──────────────────────

    public function test_the_prompt_states_the_range_it_was_given(): void
    {
        $pacing = Pacing::forFilters(['group' => ['adults' => 2, 'children' => 1, 'youngest_age' => 3]]);

        $prompt = app(ItineraryPrompt::class)->build(
            picked: [], suggested: [], legs: [], days: 2, pacing: $pacing
        );

        // The model is TOLD the same ceiling the assembler will enforce.
        // Told five and held to three, it writes a plan that is then thrown
        // away; told three and held to five, the setting does nothing.
        $this->assertStringContainsString('between 2 and 3 stops', $prompt['system']);
        $this->assertSame($pacing, $prompt['pacing']);
    }

    public function test_the_group_reaches_the_model_as_data(): void
    {
        $prompt = app(ItineraryPrompt::class)->build(
            picked: [], suggested: [], legs: [], days: 2,
            group: Pacing::groupFor([
                'trip_type' => 'family',
                'group'     => ['adults' => 4, 'children' => 2, 'youngest_age' => 7],
            ])
        );

        $this->assertSame(4, $prompt['payload']['group']['adults']);
        $this->assertSame(2, $prompt['payload']['group']['children']);
        $this->assertSame('family', $prompt['payload']['group']['type']);

        // It rides in the delimited data block, never in the instructions.
        $this->assertStringContainsString('<trip_data>', $prompt['user']);
        $this->assertStringNotContainsString('family', $prompt['system']);
    }

    public function test_an_unanswered_form_adds_nothing_to_the_prompt(): void
    {
        $prompt = app(ItineraryPrompt::class)->build(
            picked: [], suggested: [], legs: [], days: 2, group: Pacing::groupFor([])
        );

        $this->assertArrayNotHasKey('group', $prompt['payload']);
    }

    public function test_two_groups_asking_for_the_same_places_do_not_share_a_cached_plan(): void
    {
        $prompts = app(ItineraryPrompt::class);

        // Two parties that move at the SAME pace and differ only in who they
        // are. Pacing is held equal deliberately: if the groups also paced
        // differently the hashes would diverge on that alone, and the test
        // would pass while the group contributed nothing — which is exactly
        // what the first version of it did.
        $two   = ['group' => ['adults' => 2], 'trip_type' => 'couple'];
        $mates = ['group' => ['adults' => 2], 'trip_type' => 'friends'];

        $this->assertEquals(
            Pacing::forFilters($two),
            Pacing::forFilters($mates),
            'Precondition: these two must pace identically for this test to mean anything'
        );

        $couple = $prompts->build(
            picked: [], suggested: [], legs: [], days: 2,
            pacing: Pacing::forFilters($two), group: Pacing::groupFor($two)
        );

        $friends = $prompts->build(
            picked: [], suggested: [], legs: [], days: 2,
            pacing: Pacing::forFilters($mates), group: Pacing::groupFor($mates)
        );

        // Same places, same days, same pace — different prose. Left out of
        // the fingerprint, the second party is silently served the first
        // one's plan, and only they ever see it.
        $this->assertNotSame(
            $prompts->hash($couple),
            $prompts->hash($friends),
            'The cache key ignores who the trip is for'
        );
    }

    // ── End to end ───────────────────────────────────────────────────────────

    public function test_a_family_with_a_toddler_gets_shorter_days(): void
    {
        $picked = [];
        for ($i = 0; $i < 3; $i++) {
            $picked[] = $this->site("Picked {$i}", 16.04 + ($i / 200), 73.47);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->site("Spare {$i}", 16.06 + ($i / 200), 73.47);
        }

        $trip = SavedTrip::create([
            'user_id'      => $this->traveller->id,
            'status'       => 'draft',
            'days'         => 2,
            'base_site_id' => $this->base->id,
            'filters'      => [
                'category_codes' => ['beach'],
                'radius_km'      => 40,
                'trip_type'      => 'family',
                'group'          => ['adults' => 2, 'children' => 2, 'youngest_age' => 3],
            ],
        ]);

        foreach ($picked as $site) {
            Favourite::create([
                'user_id'           => $this->traveller->id,
                'trip_id'           => $trip->id,
                'favouritable_type' => (new Site())->getMorphClass(),
                'favouritable_id'   => $site->id,
            ]);
        }

        $result = app(TripPlanner::class)->planFromCart($trip->fresh());

        $this->assertTrue($result['ok'], 'Planning failed: ' . ($result['reason'] ?? ''));

        foreach ($result['itinerary']['days'] as $day) {
            $this->assertLessThanOrEqual(
                3,
                count($day['stops']),
                'A day too full for a three-year-old'
            );
            $this->assertGreaterThanOrEqual(2, count($day['stops']));
        }
    }

    public function test_the_assembler_trims_a_day_to_this_groups_ceiling(): void
    {
        // Straight at the assembler with a model answer that is too long.
        // The end-to-end test cannot prove this on its own: the stand-in
        // driver already arranges to the prompt's ceiling, so it never hands
        // the assembler a day to trim, and reverting the assembler's own
        // limit leaves that test green.
        $sites = [];
        for ($i = 0; $i < 6; $i++) {
            $sites[] = $this->site("Stop {$i}", 16.04 + ($i / 200), 73.47);
        }
        $ids = array_map(fn ($s) => $s->id, $sites);

        $raw = ['days' => [[
            'day'   => 1,
            'theme' => 'A day that runs long',
            'stops' => array_map(fn ($id) => [
                'site_id' => $id,
                'arrive'  => '09:00',
                'depart'  => '10:00',
                'note'    => 'A stop.',
            ], $ids),
        ]]];

        $result = app(\App\Services\Trip\ItineraryAssembler::class)->assemble(
            raw: $raw,
            allowedSiteIds: $ids,
            days: 1,
            pacing: Pacing::forFilters([
                'group' => ['adults' => 2, 'children' => 1, 'youngest_age' => 3],
            ])
        );

        $this->assertTrue($result['ok'], 'Assembly failed: ' . ($result['reason'] ?? ''));
        $this->assertCount(
            3,
            $result['itinerary']['days'][0]['stops'],
            'Six stops were offered to a party with a three-year-old'
        );
    }

    public function test_the_form_offers_the_types_and_the_defaults(): void
    {
        AiConfig::set('trip_types', 'family,friends,couple');

        $data = $this->actingAs($this->traveller, 'api')
            ->postJson('/api/v2/trip/questionSet')
            ->assertOk()
            ->json('data.group');

        $this->assertSame(2, $data['adults']['default']);
        $this->assertSame(0, $data['children']['default']);
        $this->assertSame(
            ['family', 'friends', 'couple'],
            array_column($data['types'], 'code')
        );

        // Solo is one adult, not a type. Asking twice is how the two answers
        // end up contradicting each other.
        $this->assertNotContains('solo', array_column($data['types'], 'code'));
    }

    // ── The two gaps the device walk found ───────────────────────────────────

    public function test_the_form_path_carries_the_group_to_the_planner(): void
    {
        // resolveFilters() REBUILDS the filter array from a fixed key list,
        // and that array is the only thing the planner ever sees. A key
        // missing from it is a question the traveller answered and nobody
        // read — which is what happened here: the stepper worked, because it
        // stores its filters whole, and the form path silently dropped the
        // group on the floor.
        for ($i = 0; $i < 8; $i++) {
            $this->site("Beach {$i}", 16.05 + ($i / 200), 73.47);
        }

        app(TripPlanner::class)->planFromForm($this->traveller, [
            'town_site_ids'  => [$this->base->id],
            'category_codes' => ['beach'],
            'days'           => 2,
            'group'          => ['adults' => 2, 'children' => 2, 'youngest_age' => 3],
            'trip_type'      => 'family',
        ]);

        $filters = (array) \App\Models\AiLog::latest('id')->first()->input_filters;

        $this->assertSame(
            ['adults' => 2, 'children' => 2, 'youngest_age' => 3],
            $filters['group'] ?? null,
            'The planner never saw who the trip was for'
        );
        $this->assertSame('family', $filters['trip_type'] ?? null);
    }

    public function test_the_stored_answer_cache_is_keyed_on_the_group_too(): void
    {
        // A SECOND cache, separate from the prompt fingerprint, and the one
        // the express path actually uses — it only engages when nothing has
        // been picked, which is exactly the express case. Keyed without the
        // group, a family is handed the five-stop plan written for a couple.
        $cache = app(\App\Services\Trip\ItineraryCache::class);

        $args = [
            'categoryCodes' => ['beach'],
            'days'          => 2,
            'lat'           => 16.06,
            'lng'           => 73.47,
            'radiusKm'      => 40.0,
            'stay'          => 'suggest',
            'arrival'       => null,
        ];

        $couple = $cache->key(...$args, group: ['adults' => 2, 'type' => 'couple']);
        $family = $cache->key(...$args, group: ['adults' => 2, 'children' => 2, 'type' => 'family']);

        $this->assertNotSame($couple, $family);

        // And an unanswered form still shares with another unanswered form,
        // or the cache stops earning its keep.
        $this->assertSame(
            $cache->key(...$args, group: null),
            $cache->key(...$args, group: null)
        );
    }

    // ── The youngest child's age ─────────────────────────────────────────────

    /** The bands the form is served, so a test reads the real contract. */
    private function ageBands(): array
    {
        return $this->actingAs($this->traveller, 'api')
            ->postJson('/api/v2/trip/questionSet')
            ->assertOk()
            ->json('data.group.ages');
    }

    public function test_the_form_offers_the_age_bands(): void
    {
        $bands = $this->ageBands();

        $this->assertSame(['under', 'mid', 'over'], array_column($bands, 'code'));

        // "Under 4", "4 to 8", "9 or older" — the server owns the numbers,
        // the app owns the sentence they sit in.
        $this->assertSame([0, 4, 9], array_column($bands, 'from'));
        $this->assertSame([3, 8, null], array_map(fn ($b) => $b['to'], $bands));

        // Each band sends its floor: the guess leans towards more care.
        $this->assertSame([0, 4, 9], array_column($bands, 'value'));
    }

    public function test_no_age_band_straddles_the_pacing_threshold(): void
    {
        // The guard this whole design exists for. A band is ONE tap, so
        // every age inside it has to pace the same way — otherwise "my
        // youngest is 6 to 9" means two different trips and the traveller
        // cannot tell which one they asked for. The bands are generated from
        // the threshold precisely so this holds as an admin moves it, and
        // this test is what says it still does.
        foreach ([1, 2, 3, 5, 8, 11, 16, 17, 20] as $threshold) {
            AiConfig::set('pacing_young_child_age', $threshold);

            $bands = $this->ageBands();
            $this->assertNotEmpty($bands, "Threshold {$threshold} offered no bands at all");

            foreach ($bands as $band) {
                $expected = $this->maxForAge($band['value']);

                for ($age = $band['from']; $age <= ($band['to'] ?? 17); $age++) {
                    $this->assertSame(
                        $expected,
                        $this->maxForAge($age),
                        "Threshold {$threshold}: a {$age}-year-old paces unlike the rest of band '{$band['code']}'"
                    );
                }

                $this->assertGreaterThanOrEqual(0, $band['value']);
                $this->assertLessThanOrEqual(
                    17,
                    $band['value'],
                    "Threshold {$threshold}: band '{$band['code']}' sends an age the request rejects"
                );
            }
        }
    }

    private function maxForAge(int $age): int
    {
        return Pacing::forFilters([
            'group' => ['adults' => 2, 'children' => 1, 'youngest_age' => $age],
        ])->maxPerDay;
    }

    public function test_an_age_with_no_children_changes_nothing(): void
    {
        // Two answers contradicting each other, resolved in favour of the
        // count — the one the traveller set last. A saved filter set whose
        // child came back off the trip must stop pacing like a toddler's.
        $filters = ['group' => ['adults' => 2, 'children' => 0, 'youngest_age' => 2]];

        $this->assertSame(5, Pacing::forFilters($filters)->maxPerDay);

        // And the model is not told about a child who is not coming, or it
        // writes the notes for them.
        $this->assertArrayNotHasKey('youngest_age', Pacing::groupFor($filters) ?? []);
    }

    public function test_two_families_whose_youngest_differ_do_not_share_a_cached_plan(): void
    {
        $cache = app(\App\Services\Trip\ItineraryCache::class);

        $args = [
            'categoryCodes' => ['beach'],
            'days'          => 2,
            'lat'           => 16.06,
            'lng'           => 73.47,
            'radiusKm'      => 40.0,
            'stay'          => 'suggest',
            'arrival'       => null,
        ];

        $key = fn (int $age) => $cache->key(...$args, group: Pacing::groupFor([
            'trip_type' => 'family',
            'group'     => ['adults' => 2, 'children' => 2, 'youngest_age' => $age],
        ]));

        // Same towns, same days, same family size — and a plan written for a
        // two-year-old is not the plan for a fifteen-year-old.
        $this->assertNotSame($key(2), $key(15));
    }

    public function test_the_stepper_save_path_validates_the_group_like_the_express_path(): void
    {
        // Two doors into one planner. The express path validates
        // `group.adults`; the stepper saves the same answers one level down
        // as `filters.group.adults` — and `UpdateCartRequest` had no rule
        // for any of them, although its own comment promised "the same shape
        // the quote accepts". So the stepper could store a group the quote
        // would have refused, and TripPlanner reads both through the same
        // code. Both lists come from CartRequest::sharedFilterRules() now; this is
        // what says they have not drifted apart again.
        $trip = SavedTrip::create([
            'user_id'      => $this->traveller->id,
            'status'       => 'draft',
            'days'         => 2,
            'base_site_id' => $this->base->id,
        ]);

        $save = fn (array $group) => $this->actingAs($this->traveller, 'api')
            ->postJson('/api/v2/trip/updateCart', [
                'trip_id' => $trip->id,
                'filters' => ['group' => $group, 'trip_type' => 'family'],
            ]);

        $this->assertApiFailure($save(['adults' => 9999]));
        $this->assertApiFailure($save(['children' => -1]));
        $this->assertApiFailure($save(['youngest_age' => 40]));

        // And a real answer still lands on the trip, age and all, which is
        // what planFromCart reads back.
        $this->assertApiSuccess($save(['adults' => 2, 'children' => 2, 'youngest_age' => 4]));

        $this->assertSame(
            ['adults' => 2, 'children' => 2, 'youngest_age' => 4],
            (array) data_get($trip->fresh()->filters, 'group')
        );
        $this->assertSame('family', data_get($trip->fresh()->filters, 'trip_type'));
    }
}
