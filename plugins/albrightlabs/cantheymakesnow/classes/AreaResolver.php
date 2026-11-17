<?php namespace AlbrightLabs\CanTheyMakeSnow\Classes;

/**
 * Turns what someone typed (a resort name or a town) or their location into
 * a point and a town name, for area alerts and the trip planner. Resort
 * names cost nothing; towns use the (throttled) Google geocode; NOAA names
 * the nearest town and confirms the point is in the US.
 */
class AreaResolver
{
    /**
     * An area's centre and name: ['label', 'lat', 'lon'] rounded to two
     * decimals (about a kilometre), or ['error' => message].
     */
    public static function resolve(string $place, ?float $lat, ?float $lon): array
    {
        $report = new SnowReport();
        if ($lat !== null && $lon !== null) {
            if (!$report->isValidCoordinate($lat, $lon)) {
                return ['error' => "Those coordinates don't look right. Try typing your town instead."];
            }
        } elseif ($resort = $report->matchResortName($place)) {
            return ['label' => $resort->label, 'lat' => round((float) $resort->lat, 2), 'lon' => round((float) $resort->lon, 2)];
        } else {
            $geocode = $report->geocode($place);
            if (isset($geocode['error'])) {
                return ['error' => SnowReport::errorMessage($geocode['error'])];
            }
            [$lat, $lon] = [$geocode['lat'], $geocode['lon']];
        }

        // NOAA names the nearest town for free, and confirms the point is in the US.
        $points = (new NoaaClient())->points(round($lat, 2), round($lon, 2));
        if (!$points['ok']) {
            return ['error' => SnowReport::errorMessage($points['error'] === 'not_found' ? 'outside_us' : 'weather_unavailable')];
        }
        $town = $points['data']['properties']['relativeLocation']['properties'] ?? [];
        $label = isset($town['city'], $town['state']) ? "{$town['city']}, {$town['state']}" : mb_substr($place ?: 'your location', 0, 120);

        return ['label' => $label, 'lat' => round($lat, 2), 'lon' => round($lon, 2)];
    }

}
