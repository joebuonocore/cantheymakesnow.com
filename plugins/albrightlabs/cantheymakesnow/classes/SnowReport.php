<?php namespace AlbrightLabs\CanTheyMakeSnow\Classes;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use AlbrightLabs\CanTheyMakeSnow\Models\Resort;

/**
 * Turns a request (free-text address, coordinates or resort slug) into a
 * complete snowmaking report: geocoding, NOAA grid forecast, 48-hour series,
 * next window, chart geometry and the nearest station observation.
 * Shared by the WeatherData component and the share-image route.
 */
class SnowReport
{
    const HTTP_TIMEOUT = 10;
    const HTTP_CONNECT_TIMEOUT = 5;
    const FORECAST_HOURS = 48;
    const SNOW_HOURS = 72;
    const OBSERVATION_MAX_AGE_SECONDS = 7200;
    const CACHE_TTL_GEOCODE = 86400;

    const ERRORS = [
        'resort_not_found' => "We don't know that resort. Try searching for it by name.",
        'bad_coords' => "Those coordinates don't look right. Try entering a city and state instead.",
        'not_configured' => 'Location lookup is not configured. Please try again later.',
        'geocode_unreachable' => 'The Google Maps API is unreachable right now. Please try again later.',
        'geocode_unavailable' => 'Location lookup is temporarily unavailable. Please try again later.',
        'not_found' => "We couldn't find that location. Try a different city and state.",
        'over_limit' => 'Too many lookups right now. Please try again in a moment.',
        'rate_limited' => "You're checking a lot of places quickly. Please wait a minute and try again.",
        'outside_us' => 'NOAA forecasts only cover the US and its territories, so we can\'t check that location.',
        'weather_unavailable' => "We couldn't load NOAA weather data for that location. Please try again shortly.",
    ];

    protected WeatherService $weather;
    protected NoaaClient $noaa;

    public function __construct(?WeatherService $weather = null, ?NoaaClient $noaa = null)
    {
        $this->weather = $weather ?: new WeatherService();
        $this->noaa = $noaa ?: new NoaaClient();
    }

    /**
     * Resolve request input into a report.
     * Returns null when there is nothing to look up, ['error' => code], or
     * ['result' => array, 'resort' => ?Resort, 'query' => array, 'address' => ?string].
     */
    public function resolve($address, $lat, $lon, $resortSlug = null): ?array
    {
        $address = is_string($address) ? trim($address) : null;
        $resortSlug = is_string($resortSlug) ? trim($resortSlug) : null;
        $hasCoords = ($lat !== null && $lat !== '') || ($lon !== null && $lon !== '');

        if (!$address && !$hasCoords && !$resortSlug) {
            return null;
        }

        // Typing a resort's exact name is treated like picking it.
        $resort = null;
        if ($resortSlug) {
            $resort = Resort::isEnabled()->where('slug', $resortSlug)->first();
            if (!$resort) {
                return ['error' => 'resort_not_found'];
            }
        } elseif ($address) {
            $resort = $this->matchResortName($address);
        }

        if ($resort) {
            [$lat, $lon] = [$resort->lat, $resort->lon];
            $query = ['resort' => $resort->slug];
        } elseif ($address) {
            $geocode = $this->geocode($address);
            if (isset($geocode['error'])) {
                return $geocode;
            }
            [$lat, $lon] = [$geocode['lat'], $geocode['lon']];
            $query = ['address' => $address];
        } else {
            if (!$this->isValidCoordinate($lat, $lon)) {
                return ['error' => 'bad_coords'];
            }
            // Resorts are a fixed, cached set; arbitrary points are where uncached NOAA calls come from.
            if (!Throttle::attempt('lookup')) {
                return ['error' => 'rate_limited'];
            }
            // Three decimals (~100m) is plenty for a forecast grid and keeps share URLs tidy.
            $query = ['lat' => round((float) $lat, 3), 'lon' => round((float) $lon, 3)];
        }

        $result = $this->build((float) $lat, (float) $lon, (bool) $resort);
        if (isset($result['error'])) {
            return $result;
        }

        if ($resort) {
            $result['place'] = $resort->label;
            $result['resort'] = [
                'slug' => $resort->slug,
                'name' => $resort->name,
                'baseFt' => $resort->base_elev_ft,
                'summitFt' => $resort->summit_elev_ft,
            ];
            $result['pinLat'] = (float) $resort->lat;
            $result['pinLon'] = (float) $resort->lon;
            $result['zones'] = $this->resortZones($resort, $result, Carbon::now('UTC'));
        }
        $result['answer'] = $this->weather->combineZones($result['zones'] ?? ['here' => $result]);
        unset($result['series']);

        $result['query'] = $query;
        $result['shareUrl'] = rtrim(url('/'), '/') . '/?' . http_build_query($query);

        return ['result' => $result, 'resort' => $resort, 'query' => $query, 'address' => $address];
    }

    public static function errorMessage(string $code): string
    {
        return self::ERRORS[$code] ?? self::ERRORS['weather_unavailable'];
    }
    /**
     * Find an enabled resort whose name (optionally followed by its state) matches exactly.
     */
    public function matchResortName(string $text): ?Resort
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($text)));
        $name = trim(preg_replace('/,?\s+[a-z]{2}$/', '', $normalized));

        return Resort::isEnabled()
            ->where(function ($q) use ($normalized, $name) {
                $q->whereRaw('LOWER(name) = ?', [$normalized])->orWhereRaw('LOWER(name) = ?', [$name]);
            })
            ->orderByRaw('LOWER(name) = ? DESC', [$normalized])
            ->first();
    }
    public function isValidCoordinate($lat, $lon): bool
    {
        return is_numeric($lat) && is_numeric($lon)
            && (float) $lat >= -90 && (float) $lat <= 90
            && (float) $lon >= -180 && (float) $lon <= 180;
    }
    /**
     * NOAA's hourly forecast for the grid cell containing a point. Returns
     * ['error' => code] or the grid ids, properties, time zone and the
     * 48-hour series.
     */
    protected function gridForecast(float $lat, float $lon, Carbon $now, bool $precise = false): array
    {
        // Resort points (a fixed, cached set) use 4 decimals: rounding to 2
        // can shift a summit ~1 km into a lower grid cell. PHP prints these
        // floats without trailing zeros, so NOAA doesn't redirect them.
        $points = $precise
            ? $this->noaa->points(round($lat, 4), round($lon, 4))
            : $this->noaa->points($this->weather->customRound($lat), $this->weather->customRound($lon));
        if (!$points['ok']) {
            return ['error' => $points['error'] === 'not_found' ? 'outside_us' : 'weather_unavailable'];
        }

        $props = $points['data']['properties'] ?? [];
        $gridId = $props['gridId'] ?? null;
        $gridX = $props['gridX'] ?? null;
        $gridY = $props['gridY'] ?? null;
        if (!$gridId || $gridX === null || $gridY === null) {
            return ['error' => 'weather_unavailable'];
        }

        $grid = $this->noaa->gridpoints($gridId, (int) $gridX, (int) $gridY);
        if (!$grid['ok']) {
            return ['error' => 'weather_unavailable'];
        }

        $gridProps = $grid['data']['properties'] ?? [];
        $series = $this->weather->hourlySeries(
            $gridProps['temperature']['values'] ?? [],
            $gridProps['relativeHumidity']['values'] ?? [],
            $gridProps['temperature']['uom'] ?? 'wmoUnit:degC',
            $now,
            self::FORECAST_HOURS
        );
        if (!$series) {
            return ['error' => 'weather_unavailable'];
        }

        $elevationM = $gridProps['elevation']['value'] ?? null;
        return [
            'props' => $props,
            'gridId' => $gridId,
            'gridX' => (int) $gridX,
            'gridY' => (int) $gridY,
            'grid' => "{$gridId}:{$gridX},{$gridY}",
            'gridProps' => $gridProps,
            'timeZone' => $props['timeZone'] ?? 'America/New_York',
            'elevationFt' => $elevationM !== null ? (int) round($elevationM * 3.28084) : null,
            'series' => $series,
        ];
    }

    /**
     * Snowmaking answer, windows and chart for one forecast series (a place,
     * or a resort's base or summit).
     */
    protected function zoneFromSeries(array $series, string $timeZone, float $lat, float $lon, Carbon $now): array
    {
        $current = $series[0];
        $tier = $this->weather->snowmakingTier($current['wetBulbC']);
        $seriesEnd = end($series)['time']->copy()->addHour();
        $windows = $this->weather->snowmakingWindows($series);

        return [
            'now' => $current + [
                'tier' => $tier['tier'],
                'label' => $tier['label'],
                'timeLabel' => $current['time']->copy()->setTimezone($timeZone)->format('g A'),
            ],
            'windows' => $this->weather->describeWindows($windows, $now, $timeZone, $seriesEnd),
            'windowList' => $windows,
            'chart' => (new ForecastChart())->build($series, $timeZone, $lat, $lon),
            'hourly' => array_map(fn ($p) => $p + [
                'label' => $p['time']->copy()->setTimezone($timeZone)->format('D g A'),
            ], $series),
        ];
    }

    /**
     * Base and summit zones for a resort. The summit uses NOAA's forecast for
     * the summit's own grid cell when it has one; otherwise (no summit point,
     * or it falls in the base's cell) it's estimated from the base forecast
     * with standard lapse rates, and marked as estimated.
     */
    protected function resortZones(Resort $resort, array $result, Carbon $now): array
    {
        $baseZone = array_intersect_key($result, array_flip(['now', 'windows', 'windowList', 'chart', 'hourly']));
        $zones = ['base' => $baseZone + [
            'label' => 'Base',
            'elevationFt' => $resort->base_elev_ft,
            'gridElevationFt' => $result['elevationFt'],
            'estimated' => false,
        ]];

        $summitFt = $resort->summit_elev_ft;
        $summit = null;
        if ($resort->summit_lat !== null && $resort->summit_lon !== null) {
            $forecast = $this->gridForecast((float) $resort->summit_lat, (float) $resort->summit_lon, $now, true);
            if (!isset($forecast['error']) && $forecast['grid'] !== $result['grid']) {
                $summit = $this->zoneFromSeries($forecast['series'], $forecast['timeZone'], (float) $resort->summit_lat, (float) $resort->summit_lon, $now)
                    + ['gridElevationFt' => $forecast['elevationFt'], 'estimated' => false];
            }
        }
        if (!$summit && $summitFt && $result['elevationFt'] !== null && $summitFt - $result['elevationFt'] >= 300) {
            $series = $this->weather->adjustForElevation($result['series'], ($summitFt - $result['elevationFt']) / 3.28084);
            $summit = $this->zoneFromSeries($series, $result['timeZone'], (float) $resort->lat, (float) $resort->lon, $now)
                + ['gridElevationFt' => null, 'estimated' => true];
        }
        if ($summit) {
            $zones['summit'] = $summit + ['label' => 'Summit', 'elevationFt' => $summitFt];
        }
        return $zones;
    }

    /**
     * Fetch NOAA data for a point and assemble everything the result view needs.
     * Returns the result array or ['error' => code].
     */
    public function build(float $lat, float $lon, bool $precise = false): array
    {
        $now = Carbon::now('UTC');
        $forecast = $this->gridForecast($lat, $lon, $now, $precise);
        if (isset($forecast['error'])) {
            return $forecast;
        }
        ['props' => $props, 'gridId' => $gridId, 'gridX' => $gridX, 'gridY' => $gridY, 'gridProps' => $gridProps, 'timeZone' => $timeZone, 'series' => $series] = $forecast;

        $zone = $this->zoneFromSeries($series, $timeZone, $lat, $lon, $now);

        $city = $props['relativeLocation']['properties']['city'] ?? null;
        $state = $props['relativeLocation']['properties']['state'] ?? null;
        // GeoJSON order: [lon, lat]
        $cityCoords = $props['relativeLocation']['geometry']['coordinates'] ?? null;
        $hasCityCoords = is_array($cityCoords) && isset($cityCoords[0], $cityCoords[1]);

        $updated = isset($gridProps['updateTime']) ? Carbon::parse($gridProps['updateTime']) : null;

        return [
            'place' => $city && $state ? "{$city}, {$state}" : sprintf('%.2f, %.2f', $lat, $lon),
            'city' => $city,
            'state' => $state,
            'timeZone' => $timeZone,
            // Map pins use NOAA's coordinates for the city, never the visitor's own.
            'pinLat' => $hasCityCoords ? round((float) $cityCoords[1], 6) : round($lat, 1),
            'pinLon' => $hasCityCoords ? round((float) $cityCoords[0], 6) : round($lon, 1),
            'hasCityCoords' => $hasCityCoords,
            'grid' => $forecast['grid'],
            'series' => $series,
            'elevationFt' => $forecast['elevationFt'],
            'updatedLabel' => $updated ? $updated->setTimezone($timeZone)->format('g:i A') : null,
            'observation' => $this->observation($gridId, $gridX, $gridY, $timeZone, $now),
            'conditions' => $this->weather->conditionsAt($gridProps, $now) + ['night' => $this->isNight($now, $lat, $lon)],
            'snow' => $this->snowForecast($gridProps, $now, $timeZone, $lat, $lon),
            'outlook' => $this->outlook($gridId, $gridX, $gridY),
        ] + $zone;
    }

    /**
     * Snowfall over the next 72 hours: totals, chart, and the biggest storm
     * starting in the next 48 hours (the "powder" headline). Null without data.
     */
    protected function snowForecast(array $gridProps, Carbon $now, string $timeZone, float $lat, float $lon): ?array
    {
        $buckets = $this->weather->snowfallBuckets(
            $gridProps['snowfallAmount']['values'] ?? [],
            $gridProps['snowfallAmount']['uom'] ?? null,
            $now,
            self::SNOW_HOURS
        );
        if (!$buckets) {
            return null;
        }

        $storms = $this->weather->storms($buckets);
        $powder = $this->weather->biggestStorm($storms, $now);
        if ($powder) {
            $local = $now->copy()->setTimezone($timeZone);
            $powder['byLabel'] = $this->weather->relativeTime($powder['end']->copy()->setTimezone($timeZone), $local);
            $powder['startLabel'] = $this->weather->relativeTime($powder['start']->copy()->setTimezone($timeZone), $local);
        }

        return [
            'totals' => $this->weather->snowTotals($buckets),
            'storms' => $storms,
            'powder' => $powder,
            'chart' => (new SnowChart())->build($buckets, $timeZone, $lat, $lon),
        ];
    }

    protected function outlook(string $gridId, int $gridX, int $gridY): array
    {
        $response = $this->noaa->forecast($gridId, $gridX, $gridY);
        return $response['ok'] ? $this->weather->parseOutlook($response['data']) : [];
    }

    protected function isNight(Carbon $at, float $lat, float $lon): bool
    {
        $sun = date_sun_info($at->timestamp, $lat, $lon);
        if (!is_int($sun['sunrise']) || !is_int($sun['sunset'])) {
            return $sun['sunrise'] === false;
        }
        return $at->timestamp < $sun['sunrise'] || $at->timestamp >= $sun['sunset'];
    }

    /**
     * Latest nearby station reading, if fresh enough to be a useful reality check.
     */
    protected function observation(string $gridId, int $gridX, int $gridY, string $timeZone, Carbon $now): ?array
    {
        $response = $this->noaa->latestObservation($gridId, $gridX, $gridY);
        if (!$response['ok']) {
            return null;
        }

        $observation = $this->weather->parseObservation($response['data']);
        if (!$observation || $now->timestamp - $observation['time']->timestamp > self::OBSERVATION_MAX_AGE_SECONDS) {
            return null;
        }

        $station = $response['data']['station'];
        return $observation + [
            'stationId' => $station['id'],
            'stationName' => $station['name'],
            'timeLabel' => $observation['time']->copy()->setTimezone($timeZone)->format('g:i A'),
            'tier' => $this->weather->snowmakingTier($observation['wetBulbC'])['tier'],
        ];
    }
    public function metaTitle(array $result): string
    {
        $answer = $result['answer']['snow'] ? 'YES' : 'NO';
        return "{$answer}: can they make snow in {$result['place']}?";
    }
    public function metaDescription(array $result): string
    {
        $temp = fn (float $c) => round($this->weather->toFahrenheit($c)) . '°F (' . round($c, 1) . '°C)';
        $zones = $result['zones'] ?? [];
        if (count($zones) > 1) {
            $answer = $result['answer'];
            $where = self::whereText($answer['where']);
            $lead = $answer['snow'] ? "Snowmaking possible {$where} of {$result['place']}" : "Too warm to make snow at {$result['place']}";
            $parts = array_map(fn ($z) => strtolower($z['label']) . ' ' . $temp($z['now']['wetBulbC']), $zones);
            return "{$lead}: " . implode(', ', $parts) . ' wet-bulb. ' . $zones[$answer['zone']]['windows']['text'];
        }
        return "{$result['place']}: {$temp($result['now']['wetBulbC'])} wet-bulb. {$result['now']['label']}. {$result['windows']['text']}";
    }

    /**
     * "at the base", "at the summit", "at the base and summit" for zone keys.
     */
    public static function whereText(array $where): string
    {
        $names = array_map(fn ($k) => $k === 'base' ? 'base' : 'summit', $where);
        return $names ? 'at the ' . implode(' and ', $names) : '';
    }
    /**
     * Geocode a free-text address via Google Maps.
     * Returns ['lat' => float, 'lon' => float] or ['error' => code].
     */
    public function geocode(string $address): array
    {
        $cacheKey = 'geocode:' . md5(strtolower($address));
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $apiKey = env('GOOGLE_MAPS_SERVER_KEY') ?: env('GOOGLE_MAPS_API_KEY');

        if (!$apiKey) {
            return ['error' => 'not_configured'];
        }
        if (!Throttle::attempt('geocode')) {
            return ['error' => 'rate_limited'];
        }

        try {
            $response = Http::connectTimeout(self::HTTP_CONNECT_TIMEOUT)
                ->timeout(self::HTTP_TIMEOUT)
                ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'address' => $address,
                    'key' => $apiKey,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Geocode network error', ['address' => $address, 'error' => $e->getMessage()]);
            return ['error' => 'geocode_unreachable'];
        }

        if (!$response->successful()) {
            Log::warning('Geocode HTTP error', ['address' => $address, 'status' => $response->status()]);
            return ['error' => 'geocode_unavailable'];
        }

        $jsonData = $response->json();
        $gStatus = $jsonData['status'] ?? null;

        if ($gStatus === 'OK' && !empty($jsonData['results'])) {
            $first = $jsonData['results'][0];
            $country = null;
            foreach ($first['address_components'] ?? [] as $component) {
                if (in_array('country', $component['types'] ?? [], true)) {
                    $country = $component['short_name'] ?? null;
                }
            }
            $geocoded = $country && !$this->weather->isNoaaCountry($country)
                ? ['error' => 'outside_us']
                : ['lat' => (float) $first['geometry']['location']['lat'], 'lon' => (float) $first['geometry']['location']['lng']];
            Cache::put($cacheKey, $geocoded, self::CACHE_TTL_GEOCODE);
            return $geocoded;
        }

        Log::warning('Geocode non-OK', [
            'address' => $address,
            'google_status' => $gStatus,
            'google_error' => $jsonData['error_message'] ?? null,
        ]);

        switch ($gStatus) {
            case 'ZERO_RESULTS':
                // Cached so repeated nonsense queries don't each cost a geocode call.
                Cache::put($cacheKey, ['error' => 'not_found'], self::CACHE_TTL_GEOCODE);
                return ['error' => 'not_found'];
            case 'OVER_QUERY_LIMIT':
                return ['error' => 'over_limit'];
            default:
                return ['error' => 'geocode_unavailable'];
        }
    }
}
