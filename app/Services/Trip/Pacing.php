<?php

namespace App\Services\Trip;

use App\Models\AiConfig;

/**
 * How many stops a day this particular group can manage.
 *
 * WHY THIS IS AN OBJECT AND NOT TWO CONFIG READS.
 *
 * `min_stops_per_day` and `max_stops_per_day` used to be read in six separate
 * places, each calling AiConfig itself: the planner's capacity guard, the
 * planner's not-enough-places check, the assembler's thin-day rejection, the
 * assembler's top-up ceiling, the prompt that TELLS the model the range, and
 * the fake driver. Three of those enforce a number, one states it, one guards
 * the gate, and nothing kept them in agreement.
 *
 * That is not a theoretical risk. The fake driver already sliced its answer to
 * `days * max_stops_per_day` while the assembler treated a dropped pick as
 * fatal, so the stand-in produced plans its own validator rejected — wrong on
 * every machine, because `ai_driver` defaults to fake.
 *
 * Make the numbers vary per trip and that class of bug multiplies: lower the
 * prompt's ceiling for a family but not the assembler's and the setting
 * silently does nothing; do it the other way and the assembler rejects the
 * plan it asked for, after the credit is spent. So the range is resolved ONCE
 * per generation, here, and handed to everything that needs it.
 *
 * WHY THE NUMBERS LEAN LOW.
 *
 * An over-full day is a bad trip. An under-full one is a traveller who adds a
 * stop. The floor never moves: below two stops it is not a day out, and the
 * assembler's thin-day rule exists for good reason.
 */
final class Pacing
{
    private function __construct(
        public readonly int $minPerDay,
        public readonly int $maxPerDay,
    ) {
    }

    /** The configured range, with nothing known about who is going. */
    public static function default(): self
    {
        return new self(
            max(1, (int) AiConfig::get('min_stops_per_day', 2)),
            max(1, (int) AiConfig::get('max_stops_per_day', 5)),
        );
    }

    /**
     * The range for one trip's filters.
     *
     * Every ceiling that applies is collected and the LOWEST wins, so a large
     * family with a toddler gets the toddler's pace rather than whichever rule
     * happened to be checked last.
     *
     * An unrecognised `trip_type` changes nothing. A stale app sending a type
     * that has since been retired should get the ordinary pace, not an error
     * it cannot explain to anyone — the same reasoning that keeps `exists` off
     * the category codes.
     */
    public static function forFilters(array $filters): self
    {
        $base = self::default();

        $group    = (array) data_get($filters, 'group', []);
        $adults   = max(0, (int) ($group['adults'] ?? 0));
        $children = max(0, (int) ($group['children'] ?? 0));
        $youngest = $group['youngest_age'] ?? null;
        $type     = (string) data_get($filters, 'trip_type', '');

        $ceilings = [$base->maxPerDay];

        // A family moves at the pace of whoever is slowest to get back in the
        // car. "family" alone is the gentle case; a young child is the real
        // constraint, and it applies whatever the type says.
        if ($type === 'family' || $children > 0) {
            $ceilings[] = (int) AiConfig::get('pacing_max_family', 4);
        }

        $youngAge = (int) AiConfig::get('pacing_young_child_age', 8);

        if ($youngest !== null && (int) $youngest <= $youngAge) {
            $ceilings[] = (int) AiConfig::get('pacing_max_young_child', 3);
        }

        // One bus, one lunch decision, one person who needs the toilet.
        $large = (int) AiConfig::get('pacing_large_group_size', 8);

        if ($adults + $children >= $large) {
            $ceilings[] = (int) AiConfig::get('pacing_max_large_group', 3);
        }

        $max = max($base->minPerDay, min($ceilings));

        return new self($base->minPerDay, $max);
    }

    /**
     * What the model is told about who it is planning for.
     *
     * Data, never instructions — it rides in the payload beside the places,
     * under the same rule as `traveller_note`. Null when nothing was asked,
     * so an unanswered form adds nothing to the prompt.
     *
     * @return array<string,mixed>|null
     */
    public static function groupFor(array $filters): ?array
    {
        $group    = (array) data_get($filters, 'group', []);
        $adults   = (int) ($group['adults'] ?? 0);
        $children = (int) ($group['children'] ?? 0);
        $type     = trim((string) data_get($filters, 'trip_type', ''));

        if ($adults < 1 && $children < 1 && $type === '') {
            return null;
        }

        return array_filter([
            'adults'       => $adults ?: null,
            'children'     => $children ?: null,
            'youngest_age' => $group['youngest_age'] ?? null,
            'type'         => $type ?: null,
        ], fn ($v) => $v !== null);
    }
}
