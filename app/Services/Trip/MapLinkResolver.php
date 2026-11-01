<?php

namespace App\Services\Trip;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turns a Google Maps link a traveller pasted into a point we can reason about.
 *
 * WHY THIS IS SERVER-SIDE
 *
 * A `maps.app.goo.gl` link carries no coordinates — it is a redirect, and the
 * real URL only appears after following it. Doing that from the app would mean
 * every device hitting Google directly, with no shared timeout, no logging,
 * and no way to change the parsing when Google changes a URL shape.
 *
 * More importantly, the question that actually matters — "is this inside the
 * area we cover?" — can only be answered against the sites table. The app does
 * not have one.
 *
 * WHAT "INSIDE" MEANS
 *
 * There is no district polygon in the database, so this measures distance to
 * the nearest covered town instead. That is a deliberate approximation: it
 * answers the question the traveller is really asking, which is not "am I
 * inside an administrative boundary" but "will you be able to plan around
 * this place". A pin 60 km from the nearest town we know fails that test even
 * if it is technically inside Sindhudurg.
 */
class MapLinkResolver
{
    /**
     * How far from the nearest covered town still counts as plannable.
     *
     * Sindhudurg's talukas sit roughly 20–30 km apart, so 40 km reaches a
     * genuine gap between towns without reaching the next district.
     */
    private const INSIDE_KM = 40.0;

    public function __construct(private CandidateFetcher $candidates)
    {
    }

    /**
     * @return array{ok:bool, reason?:string, inside?:bool, latitude?:float,
     *               longitude?:float, name?:string, nearest_town?:string,
     *               distance_km?:float, nearby?:array}
     */
    public function resolve(string $url): array
    {
        $final = $this->expand($url);
        if ($final === null) {
            return ['ok' => false, 'reason' => 'unreachable'];
        }

        $point = $this->coordinates($final);
        if ($point === null) {
            return ['ok' => false, 'reason' => 'no_coordinates'];
        }

        [$lat, $lng] = $point;
        $towns = $this->candidates->towns();

        $nearestTown = null;
        $nearestKm   = null;
        foreach ($towns as $town) {
            $km = $this->km($lat, $lng, (float) $town->latitude, (float) $town->longitude);
            if ($nearestKm === null || $km < $nearestKm) {
                $nearestKm   = $km;
                $nearestTown = $town;
            }
        }

        if ($nearestKm === null) {
            return ['ok' => false, 'reason' => 'no_coverage'];
        }

        $inside = $nearestKm <= self::INSIDE_KM;

        return [
            'ok'           => true,
            'inside'       => $inside,
            'latitude'     => round($lat, 6),
            'longitude'    => round($lng, 6),
            'name'         => $this->label($final),
            'nearest_town' => $nearestTown?->name,
            'distance_km'  => round($nearestKm, 1),
            // Only useful when we are refusing: it turns "no" into a choice.
            'nearby'       => $inside ? [] : $this->nearbyCovered($lat, $lng),
        ];
    }

    /**
     * Follow the short link to whatever it really points at.
     *
     * HEAD rather than GET: the redirect chain is all we need, and a Maps page
     * is megabytes we would download and throw away. Short timeout because a
     * traveller is watching a spinner.
     */
    private function expand(string $url): ?string
    {
        try {
            $res = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->withOptions(['allow_redirects' => ['track_redirects' => true]])
                ->head($url);

            $chain = $res->getHeader('X-Guzzle-Redirect-History');
            $last  = is_array($chain) && $chain !== [] ? end($chain) : null;

            return $last ?: (string) $url;
        } catch (\Throwable $e) {
            Log::info('[mapLink] could not expand', ['message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Pull a latitude/longitude out of a resolved Maps URL.
     *
     * Google uses several shapes and has changed them before, so each pattern
     * is tried in turn rather than assuming one. `!3d…!4d` is the place's own
     * point; `@lat,lng` is the map centre, which is close enough when the
     * place marker is missing.
     */
    private function coordinates(string $url): ?array
    {
        $patterns = [
            '/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/',
            '/@(-?\d+\.\d+),(-?\d+\.\d+)/',
            '/[?&]q=(-?\d+\.\d+),\s*(-?\d+\.\d+)/',
            '/[?&]ll=(-?\d+\.\d+),\s*(-?\d+\.\d+)/',
            '/[?&]destination=(-?\d+\.\d+),\s*(-?\d+\.\d+)/',
        ];

        foreach ($patterns as $re) {
            if (preg_match($re, $url, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];
                if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                    return [$lat, $lng];
                }
            }
        }

        return null;
    }

    /** The place name Google put in the path, when it put one there. */
    private function label(string $url): ?string
    {
        if (preg_match('#/place/([^/@?]+)#', $url, $m)) {
            $name = urldecode(str_replace('+', ' ', $m[1]));
            $name = trim(preg_replace('/\s+/', ' ', $name));
            return $name !== '' ? mb_substr($name, 0, 120) : null;
        }

        return null;
    }

    /** The closest places we DO cover, so a refusal offers somewhere to go. */
    private function nearbyCovered(float $lat, float $lng, int $limit = 2): array
    {
        $sites = Site::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->select('id', 'name', 'latitude', 'longitude')
            ->get();

        return $sites
            ->map(fn (Site $s) => [
                'id'          => (int) $s->id,
                'name'        => $s->name,
                'distance_km' => round(
                    $this->km($lat, $lng, (float) $s->latitude, (float) $s->longitude),
                    1
                ),
            ])
            ->sortBy('distance_km')
            ->take($limit)
            ->values()
            ->all();
    }

    /** Haversine, in kilometres. */
    private function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthKm = 6371;
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return $earthKm * 2 * asin(min(1, sqrt($a)));
    }
}
