<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Shared time-scoping for the dashboard analytics endpoints: `days` (last N) or an
 * explicit `date_from`/`date_to`, bucketed by day, week or month.
 *
 * Charts must not have holes, so the bucket list is generated from the range rather
 * than from whatever the query happened to return — a day with no traffic still
 * needs a zero point or the line jumps.
 */
class AnalyticsPeriod
{
    public const GRANULARITIES = ['day', 'week', 'month'];

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $granularity,
    ) {}

    public static function fromRequest(array $input): self
    {
        $granularity = in_array($input['granularity'] ?? 'day', self::GRANULARITIES, true)
            ? $input['granularity'] ?? 'day'
            : 'day';

        // An explicit range wins; otherwise fall back to the last N days (default 30).
        if (! empty($input['date_from']) && ! empty($input['date_to'])) {
            $from = CarbonImmutable::parse($input['date_from'])->startOfDay();
            $to   = CarbonImmutable::parse($input['date_to'])->endOfDay();
        } else {
            $days = max(1, min(365, (int) ($input['days'] ?? 30)));
            $to   = CarbonImmutable::today()->endOfDay();
            $from = $to->subDays($days - 1)->startOfDay();
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        return new self($from, $to, $granularity);
    }

    /** The SQL expression that collapses a timestamp into this period's bucket. */
    public function bucketExpression(string $column = 'created_at'): string
    {
        return match ($this->granularity) {
            'week'  => "DATE(DATE_SUB({$column}, INTERVAL WEEKDAY({$column}) DAY))",
            'month' => "DATE(DATE_FORMAT({$column}, '%Y-%m-01'))",
            default => "DATE({$column})",
        };
    }

    /**
     * Every bucket in the range, in order — the skeleton rows are filled from the
     * query and whatever is missing stays at zero.
     *
     * @return array<int, string>  Y-m-d keys
     */
    public function buckets(): array
    {
        [$step, $start] = match ($this->granularity) {
            'week'  => ['1 week',  $this->from->startOfWeek()],
            'month' => ['1 month', $this->from->startOfMonth()],
            default => ['1 day',   $this->from],
        };

        $out = [];
        foreach (CarbonPeriod::create($start, $step, $this->to) as $point) {
            $out[] = $point->format('Y-m-d');
        }

        return $out;
    }

    /** @return array{0: string, 1: string} inclusive datetime bounds for WHERE */
    public function bounds(): array
    {
        return [$this->from->format('Y-m-d H:i:s'), $this->to->format('Y-m-d H:i:s')];
    }

    public static function rules(): array
    {
        return [
            'days'        => 'sometimes|integer|min:1|max:365',
            'date_from'   => 'sometimes|date',
            'date_to'     => 'sometimes|date',
            'granularity' => 'sometimes|in:day,week,month',
        ];
    }
}
