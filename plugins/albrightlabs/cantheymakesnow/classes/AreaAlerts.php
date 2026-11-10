<?php namespace AlbrightLabs\CanTheyMakeSnow\Classes;

use Carbon\Carbon;

/**
 * Area powder alerts: which resorts near a place have a storm coming,
 * from what's stored on each resort (no NOAA calls). Pure: no framework
 * dependencies.
 *
 * The storms returned have the shape AlertRules::powderAlert() expects,
 * so an area gets the same threshold, spacing and once-per-storm rules as
 * a single resort: storms at different resorts that overlap in time count
 * as one event.
 */
class AreaAlerts
{
    const EARTH_RADIUS_MI = 3958.8;

    /**
     * A resort's stored storm is only trusted if it was checked this recently.
     */
    const FRESH_HOURS = 3;

    /**
     * Great-circle distance in miles.
     */
    public static function distanceMi(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return self::EARTH_RADIUS_MI * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Storms at resorts within $radiusMi of a point, biggest first.
     * $resorts: arrays with name, state, url, lat, lon, stormIn, stormStart,
     * stormEnd (Carbon), timeZone and checkedAt (Carbon).
     * Each storm: ['start', 'end', 'totalIn', 'resort' => the resort array, 'distanceMi'].
     */
    public static function storms(float $lat, float $lon, int $radiusMi, array $resorts, Carbon $now): array
    {
        $storms = [];
        foreach ($resorts as $resort) {
            if (empty($resort['stormIn']) || empty($resort['stormStart']) || empty($resort['stormEnd'])) {
                continue;
            }
            if (empty($resort['checkedAt']) || $now->timestamp - $resort['checkedAt']->timestamp > self::FRESH_HOURS * 3600) {
                continue;
            }
            $distance = self::distanceMi($lat, $lon, (float) $resort['lat'], (float) $resort['lon']);
            if ($distance > $radiusMi) {
                continue;
            }
            $storms[] = [
                'start' => $resort['stormStart'],
                'end' => $resort['stormEnd'],
                'totalIn' => (float) $resort['stormIn'],
                'resort' => $resort,
                'distanceMi' => $distance,
            ];
        }
        usort($storms, fn ($a, $b) => [$b['totalIn'], $a['resort']['name']] <=> [$a['totalIn'], $b['resort']['name']]);
        return $storms;
    }

    /**
     * The storms worth listing in an alert: at or above the threshold (as
     * rounded for display), starting within the powder lookahead and not over.
     */
    public static function qualifying(array $storms, int $thresholdIn, Carbon $now): array
    {
        return array_values(array_filter($storms, fn ($storm) => round($storm['totalIn']) >= $thresholdIn
            && $storm['start']->timestamp - $now->timestamp < AlertRules::POWDER_LOOKAHEAD_HOURS * 3600
            && $storm['end']->timestamp > $now->timestamp));
    }
}
