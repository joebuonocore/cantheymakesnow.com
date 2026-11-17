<?php namespace AlbrightLabs\CanTheyMakeSnow\Classes;

use Carbon\Carbon;

/**
 * Trip planner: resorts in an area ranked by new snow, then snowmaking
 * hours, over the days chosen (from each resort's stored 7-day outlook).
 * Pure: no framework dependencies.
 */
class TripPlanner
{
    const RADII = [25, 50, 100, 150, 200, 300, 500];
    const DEFAULT_RADIUS = 100;

    /**
     * The next 7 days as ['date' => 'Y-m-d', 'label' => 'Today' | 'Sat 21'].
     * Dates are Eastern time, which matches every US resort's date for all
     * but the last hours of the day.
     */
    public static function days(Carbon $now): array
    {
        $local = $now->copy()->setTimezone('America/New_York')->startOfDay();
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $local->copy()->addDays($i);
            $days[] = ['date' => $d->format('Y-m-d'), 'label' => $i === 0 ? 'Today' : ($i === 1 ? 'Tomorrow' : $d->format('D j'))];
        }
        return $days;
    }

    /**
     * $resorts: arrays with name, state, url, lat, lon, fresh and daily
     * (WeatherService::dailySummary()). $area: ['lat', 'lon', 'radius'] or
     * ['state' => 'VT']. $dates: 'Y-m-d' strings.
     * Each result adds snowIn and smHours (summed over the chosen days),
     * days (those days only), best (the best day) and distanceMi.
     */
    public static function rank(array $resorts, array $area, array $dates): array
    {
        $results = [];
        foreach ($resorts as $resort) {
            if (empty($resort['fresh'])) {
                continue;
            }
            $distance = null;
            if (isset($area['state'])) {
                if ($resort['state'] !== $area['state']) {
                    continue;
                }
            } else {
                $distance = AreaAlerts::distanceMi($area['lat'], $area['lon'], (float) $resort['lat'], (float) $resort['lon']);
                if ($distance > $area['radius']) {
                    continue;
                }
            }
            $days = array_values(array_filter($resort['daily'] ?? [], fn ($d) => in_array($d['date'], $dates, true)));
            if (!$days) {
                continue;
            }
            $best = null;
            foreach ($days as $day) {
                if (($day['snowIn'] >= 0.1 || $day['smHours'] > 0) && (!$best || [$day['snowIn'], $day['smHours']] > [$best['snowIn'], $best['smHours']])) {
                    $best = $day;
                }
            }
            $results[] = $resort + [
                'snowIn' => round(array_sum(array_column($days, 'snowIn')), 1),
                'smHours' => array_sum(array_column($days, 'smHours')),
                'days' => $days,
                'best' => $best,
                'distanceMi' => $distance === null ? null : (int) round($distance),
            ];
        }
        usort($results, fn ($a, $b) => [$b['snowIn'], $b['smHours'], $a['distanceMi'] ?? 0, $a['name']] <=> [$a['snowIn'], $a['smHours'], $b['distanceMi'] ?? 0, $b['name']]);
        return $results;
    }
}
