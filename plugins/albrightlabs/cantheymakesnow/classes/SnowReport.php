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
            // Three decimals (~100m) is plenty for a forecast grid and keeps share URLs tidy.
            $query = ['lat' => round((float) $lat, 3), 'lon' => round((float) $lon, 3)];
        }

        $result = $this->build((float) $lat, (float) $lon);
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
        }

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
     * Fetch NOAA data for a point and assemble everything the result view needs.
     * Returns the result array or ['error' => code].
     */
    public function build(float $lat, float $lon): array
    {
        $points = $this->noaa->points($this->weather->customRound($lat), $this->weather->customRound($lon));
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
        $timeZone = $props['timeZone'] ?? 'America/New_York';
        $now = Carbon::now('UTC');

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

        $current = $series[0];
        $tier = $this->weather->snowmakingTier($current['wetBulbC']);
        $seriesEnd = end($series)['time']->copy()->addHour();
        $windows = $this->weather->snowmakingWindows($series);

        $city = $props['relativeLocation']['properties']['city'] ?? null;
        $state = $props['relativeLocation']['properties']['state'] ?? null;
        // GeoJSON order: [lon, lat]
        $cityCoords = $props['relativeLocation']['geometry']['coordinates'] ?? null;
        $hasCityCoords = is_array($cityCoords) && isset($cityCoords[0], $cityCoords[1]);

        $elevationM = $gridProps['elevation']['value'] ?? null;
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
            'now' => $current + [
                'tier' => $tier['tier'],
                'label' => $tier['label'],
                'timeLabel' => $current['time']->copy()->setTimezone($timeZone)->format('g A'),
            ],
            'windows' => $this->weather->describeWindows($windows, $now, $timeZone, $seriesEnd),
            'chart' => (new ForecastChart())->build($series, $timeZone, $lat, $lon),
            'hourly' => array_map(fn ($p) => $p + [
                'label' => $p['time']->copy()->setTimezone($timeZone)->format('D g A'),
            ], $series),
            'elevationFt' => $elevationM !== null ? (int) round($elevationM * 3.28084) : null,
            'updatedLabel' => $updated ? $updated->setTimezone($timeZone)->format('g:i A') : null,
            'observation' => $this->observation($gridId, (int) $gridX, (int) $gridY, $timeZone, $now),
        ];
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
        $answer = $this->weather->snowmakingTier($result['now']['wetBulbC'])['snow'] ? 'YES' : 'NO';
        return "{$answer}: can they make snow in {$result['place']}?";
    }
    public function metaDescription(array $result): string
    {
        $f = round($this->weather->toFahrenheit($result['now']['wetBulbC']));
        $c = round($result['now']['wetBulbC'], 1);
        return "{$result['now']['label']} in {$result['place']}: {$f}°F ({$c}°C) wet-bulb. {$result['windows']['text']}";
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
                return ['error' => 'not_found'];
            case 'OVER_QUERY_LIMIT':
                return ['error' => 'over_limit'];
            default:
                return ['error' => 'geocode_unavailable'];
        }
    }
}
