<?php namespace AlbrightLabs\CanTheyMakeSnow\Components;

use Carbon\Carbon;
use Cms\Classes\ComponentBase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use AlbrightLabs\CanTheyMakeSnow\Classes\ForecastChart;
use AlbrightLabs\CanTheyMakeSnow\Classes\NoaaClient;
use AlbrightLabs\CanTheyMakeSnow\Classes\WeatherService;
use AlbrightLabs\CanTheyMakeSnow\Models\Location;

/**
 * WeatherData Component
 *
 * Looks up a place (free-text address or coordinates), pulls NOAA's hourly
 * grid forecast and nearest station observation, and exposes a single
 * `result` (or `lookupError`) to the theme's `result` partial. Used both on
 * page load (shareable GET URLs) and through the onLookup AJAX handler.
 *
 * @link https://docs.octobercms.com/3.x/extend/cms-components.html
 */
class WeatherData extends ComponentBase
{
    const HTTP_TIMEOUT = 10;
    const HTTP_CONNECT_TIMEOUT = 5;
    const LOOKUP_DEDUPE_SECONDS = 1800;
    const FORECAST_HOURS = 48;
    const OBSERVATION_MAX_AGE_SECONDS = 7200;

    const ERRORS = [
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

    public function init()
    {
        $this->weather = new WeatherService();
        $this->noaa = new NoaaClient();
    }

    public function componentDetails()
    {
        return [
            'name' => 'Weather Data Component',
            'description' => 'Retrieves weather data based on coordinates or addresses, and calculates the wet-bulb temperature.'
        ];
    }

    public function defineProperties()
    {
        return [];
    }

    public function onRun()
    {
        $this->runLookup(get('address'), get('lat'), get('lon'));
    }

    /**
     * AJAX lookup. Updates the `result` partial and returns data the
     * browser needs to keep the URL, title and map in sync.
     */
    public function onLookup()
    {
        $result = $this->runLookup(post('address'), post('lat'), post('lon'));

        return [
            'ok' => (bool) $result,
            'shareUrl' => $result['shareUrl'] ?? null,
            'pageTitle' => $result ? $this->metaTitle($result) : null,
            'pin' => $result ? ['lat' => $result['pinLat'], 'lng' => $result['pinLon'], 'tier' => $result['now']['tier']] : null,
        ];
    }

    /**
     * Resolve the request into a result or an error and publish it to the page.
     * Returns the result array, or null when there is nothing to show.
     */
    protected function runLookup($address, $lat, $lon): ?array
    {
        $address = is_string($address) ? trim($address) : null;
        $hasCoords = ($lat !== null && $lat !== '') || ($lon !== null && $lon !== '');

        $this->page['query'] = $address;
        $this->page['result'] = null;
        $this->page['lookupError'] = null;

        if (!$address && !$hasCoords) {
            return null;
        }

        $query = [];
        if ($address) {
            $geocode = $this->geocode($address);
            if (isset($geocode['error'])) {
                return $this->fail($geocode['error']);
            }
            [$lat, $lon] = [$geocode['lat'], $geocode['lon']];
            $query = ['address' => $address];
        } else {
            if (!$this->isValidCoordinate($lat, $lon)) {
                return $this->fail('bad_coords');
            }
            // Three decimals (~100m) is plenty for a forecast grid and keeps share URLs tidy.
            $query = ['lat' => round((float) $lat, 3), 'lon' => round((float) $lon, 3)];
        }

        $result = $this->lookup((float) $lat, (float) $lon);
        if (isset($result['error'])) {
            return $this->fail($result['error']);
        }

        $result['shareUrl'] = url('/') . '?' . http_build_query($query);
        $this->page['result'] = $result;
        $this->page['query'] = $address ?: $result['place'];

        $this->page->meta_title = $this->metaTitle($result);
        $this->page->meta_description = $this->metaDescription($result);

        $this->recordLookup($result);

        return $result;
    }

    protected function fail(string $code): ?array
    {
        $this->page['lookupError'] = ['code' => $code, 'message' => self::ERRORS[$code] ?? self::ERRORS['weather_unavailable']];
        return null;
    }

    protected function isValidCoordinate($lat, $lon): bool
    {
        return is_numeric($lat) && is_numeric($lon)
            && (float) $lat >= -90 && (float) $lat <= 90
            && (float) $lon >= -180 && (float) $lon <= 180;
    }

    /**
     * Fetch NOAA data for a point and assemble everything the result view needs.
     * Returns the result array or ['error' => code].
     */
    public function lookup(float $lat, float $lon): array
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

    protected function metaTitle(array $result): string
    {
        $answer = $this->weather->snowmakingTier($result['now']['wetBulbC'])['snow'] ? 'YES' : 'NO';
        return "{$answer}: can they make snow in {$result['place']}?";
    }

    protected function metaDescription(array $result): string
    {
        $f = round($this->weather->toFahrenheit($result['now']['wetBulbC']));
        $c = round($result['now']['wetBulbC'], 1);
        return "{$result['now']['label']} in {$result['place']}: {$f}°F ({$c}°C) wet-bulb. {$result['windows']['text']}";
    }

    /**
     * Count a lookup against the city/state and keep its map pin current.
     * A city is counted once per visitor session per LOOKUP_DEDUPE_SECONDS.
     */
    protected function recordLookup(array $result)
    {
        if (!$result['city'] || !$result['state']) {
            return;
        }

        $sessionKey = 'cantheymakesnow.recent_lookups';
        $locationKey = "{$result['city']}|{$result['state']}";
        $recent = array_filter(
            (array) Session::get($sessionKey, []),
            fn ($at) => $at > time() - self::LOOKUP_DEDUPE_SECONDS
        );

        $pin = ['lat' => $result['pinLat'], 'lon' => $result['pinLon']];
        $attributes = ['city' => $result['city'], 'state' => $result['state']];
        try {
            $location = Location::firstOrCreate($attributes, $pin);
        } catch (QueryException $e) {
            // Lost a race with a concurrent first lookup of the same city.
            $location = Location::where($attributes)->first();
        }

        if (!$location) {
            return;
        }

        $updates = [];
        if ($result['hasCityCoords'] || is_null($location->lat) || is_null($location->lon)) {
            $updates = $pin;
        }

        $query = Location::whereKey($location->getKey());
        if (isset($recent[$locationKey])) {
            if ($updates) {
                $query->update($updates);
            }
        } else {
            $query->increment('lookups', 1, $updates + ['last_looked_up_at' => Carbon::now()]);
            $recent[$locationKey] = time();
        }

        Session::put($sessionKey, $recent);
    }

    /**
     * Geocode a free-text address via Google Maps.
     * Returns ['lat' => float, 'lon' => float] or ['error' => code].
     */
    protected function geocode(string $address): array
    {
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
            if ($country && !$this->weather->isNoaaCountry($country)) {
                return ['error' => 'outside_us'];
            }
            return [
                'lat' => (float) $first['geometry']['location']['lat'],
                'lon' => (float) $first['geometry']['location']['lng'],
            ];
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
