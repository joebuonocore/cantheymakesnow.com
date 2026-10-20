<?php namespace AlbrightLabs\CanTheyMakeSnow\Components;

use Flash;
use Carbon\Carbon;
use Cms\Classes\ComponentBase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use AlbrightLabs\CanTheyMakeSnow\Classes\WeatherService;
use AlbrightLabs\CanTheyMakeSnow\Models\Location;

/**
 * WeatherData Component
 *
 * @link https://docs.octobercms.com/3.x/extend/cms-components.html
 */
class WeatherData extends ComponentBase
{
    const HTTP_TIMEOUT = 10;
    const HTTP_CONNECT_TIMEOUT = 5;
    const CACHE_TTL_POINTS = 86400;
    const CACHE_TTL_GRIDPOINTS = 1800;
    const LOOKUP_DEDUPE_SECONDS = 1800;

    public $lat;
    public $lon;

    protected $cityLat;
    protected $cityLon;
    public $snow;
    public $snowTier;
    public $snowTierLabel;

    public $address;

    public $city;
    public $state;

    public $data;

    protected WeatherService $weather;

    public function init()
    {
        $this->weather = new WeatherService();
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
        if (get('address')) {
            if (!$this->setLatLon(get('address'))) {
                return redirect()->to(url('/'));
            }
        }

        if (get('lat') !== null || get('lon') !== null) {
            if (!$this->isValidCoordinate(get('lat'), get('lon'))) {
                Flash::error("Those coordinates don't look right. Try entering a city and state instead.");
                return redirect()->to(url('/'));
            }
            $this->lat = (float) get('lat');
            $this->lon = (float) get('lon');
        }

        if (!is_null($this->lat) && !is_null($this->lon)) {
            $this->data = $this->getData();
            $this->page['data'] = $this->data;
        }

        $this->page['lat'] = $this->lat;
        $this->page['lon'] = $this->lon;
        $this->page['snow'] = $this->snow;
        $this->page['snowTier'] = $this->snowTier;
        $this->page['snowTierLabel'] = $this->snowTierLabel;
        $this->page['city'] = $this->city;
        $this->page['state'] = $this->state;
        $this->page['address'] = $this->address;

        if ($this->state && $this->city) {
            $this->recordLookup();
        }
    }

    protected function isValidCoordinate($lat, $lon): bool
    {
        return is_numeric($lat) && is_numeric($lon)
            && (float) $lat >= -90 && (float) $lat <= 90
            && (float) $lon >= -180 && (float) $lon <= 180;
    }

    /**
     * Count a lookup against the city/state and keep its map pin current.
     * Pins use NOAA's coordinates for the city itself, never the visitor's
     * own (possibly GPS-precise) coordinates.
     */
    protected function recordLookup()
    {
        $sessionKey = 'cantheymakesnow.recent_lookups';
        $locationKey = "{$this->city}|{$this->state}";
        $recent = array_filter(
            (array) Session::get($sessionKey, []),
            fn ($at) => $at > time() - self::LOOKUP_DEDUPE_SECONDS
        );

        $hasCityCoords = $this->cityLat !== null && $this->cityLon !== null;
        $pinLat = $hasCityCoords ? $this->cityLat : round((float) $this->lat, 1);
        $pinLon = $hasCityCoords ? $this->cityLon : round((float) $this->lon, 1);

        $attributes = ['city' => $this->city, 'state' => $this->state];
        try {
            $location = Location::firstOrCreate($attributes, ['lat' => $pinLat, 'lon' => $pinLon]);
        } catch (QueryException $e) {
            // Lost a race with a concurrent first lookup of the same city.
            $location = Location::where($attributes)->first();
        }

        if (!$location) {
            return;
        }

        $updates = [];
        if ($hasCityCoords || is_null($location->lat) || is_null($location->lon)) {
            $updates = ['lat' => $pinLat, 'lon' => $pinLon];
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

    public function onSubmit()
    {
        $address = urlencode(post('address'));
        return redirect()->to(url('/')."?address={$address}");
    }

    /**
     * Geocode a free-text address via Google Maps.
     * Returns true on success, false on failure (with a flash error set).
     */
    public function setLatLon($address)
    {
        $apiKey = env('GOOGLE_MAPS_SERVER_KEY') ?: env('GOOGLE_MAPS_API_KEY');

        if (!$apiKey) {
            Flash::error('Location lookup is not configured. Please try again later.');
            return false;
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
            Flash::error("The Google Maps API is unreachable right now. Please try again later.");
            return false;
        }

        if (!$response->successful()) {
            Log::warning('Geocode HTTP error', ['address' => $address, 'status' => $response->status()]);
            Flash::error("Location lookup is temporarily unavailable. Please try again later.");
            return false;
        }

        $jsonData = $response->json();
        $gStatus = $jsonData['status'] ?? null;

        if ($gStatus === 'OK' && !empty($jsonData['results'])) {
            $this->lat = $jsonData['results'][0]['geometry']['location']['lat'];
            $this->lon = $jsonData['results'][0]['geometry']['location']['lng'];
            return true;
        }

        Log::warning('Geocode non-OK', [
            'address' => $address,
            'google_status' => $gStatus,
            'google_error' => $jsonData['error_message'] ?? null,
        ]);

        switch ($gStatus) {
            case 'ZERO_RESULTS':
                Flash::error("We couldn't find that location. Try a different city and state.");
                break;
            case 'OVER_QUERY_LIMIT':
                Flash::error("Too many lookups right now. Please try again in a moment.");
                break;
            case 'REQUEST_DENIED':
            case 'INVALID_REQUEST':
            case 'UNKNOWN_ERROR':
            default:
                Flash::error("Location lookup is temporarily unavailable. Please try again later.");
                break;
        }
        return false;
    }

    public function getData()
    {
        if (!$this->lat || !$this->lon) {
            return null;
        }

        $latitude = $this->weather->customRound((float) $this->lat);
        $longitude = $this->weather->customRound((float) $this->lon);

        $pointsKey = "noaa:points:{$latitude},{$longitude}";
        $pointsResponse = Cache::get($pointsKey);
        if (!$pointsResponse) {
            $pointsResponse = $this->fetchNoaa("https://api.weather.gov/points/{$latitude},{$longitude}");
            if ($pointsResponse) {
                Cache::put($pointsKey, $pointsResponse, self::CACHE_TTL_POINTS);
            }
        }
        if (!$pointsResponse) {
            return null;
        }

        $this->city = $pointsResponse['properties']['relativeLocation']['properties']['city'] ?? null;
        $this->state = $pointsResponse['properties']['relativeLocation']['properties']['state'] ?? null;

        if ($this->city && $this->state) {
            $this->address = "{$this->city}, {$this->state}";
        }

        // GeoJSON order: [lon, lat]
        $cityCoords = $pointsResponse['properties']['relativeLocation']['geometry']['coordinates'] ?? null;
        if (is_array($cityCoords) && isset($cityCoords[0], $cityCoords[1])) {
            $this->cityLon = round((float) $cityCoords[0], 6);
            $this->cityLat = round((float) $cityCoords[1], 6);
        }

        $gridId = $pointsResponse['properties']['gridId'] ?? null;
        $gridX = $pointsResponse['properties']['gridX'] ?? null;
        $gridY = $pointsResponse['properties']['gridY'] ?? null;

        if (!$gridId || $gridX === null || $gridY === null) {
            return null;
        }

        $gridKey = "noaa:grid:{$gridId}:{$gridX},{$gridY}";
        $gridpoints = Cache::get($gridKey);
        if (!$gridpoints) {
            $gridpoints = $this->fetchNoaa("https://api.weather.gov/gridpoints/{$gridId}/{$gridX},{$gridY}");
            if ($gridpoints) {
                Cache::put($gridKey, $gridpoints, self::CACHE_TTL_GRIDPOINTS);
            }
        }
        if (!$gridpoints) {
            return null;
        }

        $temperatures = $gridpoints['properties']['temperature']['values'] ?? [];
        $humidities = $gridpoints['properties']['relativeHumidity']['values'] ?? [];
        $temperatureUom = $gridpoints['properties']['temperature']['uom'] ?? 'wmoUnit:degC';

        $currentTime = Carbon::now('UTC');
        $closestTemperature = $this->weather->findClosestTime($temperatures, $currentTime);
        $closestHumidity = $this->weather->findClosestTime($humidities, $currentTime);

        $wetBulb = null;
        if ($closestTemperature['value'] !== null && $closestHumidity['value'] !== null) {
            $dryBulbTemp = $this->weather->toCelsius((float) $closestTemperature['value'], $temperatureUom);
            $closestTemperature['value'] = $dryBulbTemp;
            $wetBulb = $this->weather->calculateWetBulb($dryBulbTemp, (float) $closestHumidity['value']);
        }

        $tier = $this->weather->snowmakingTier($wetBulb);
        $this->snow = $tier['snow'];
        $this->snowTier = $tier['tier'];
        $this->snowTierLabel = $tier['label'];

        return [
            'lat' => $this->lat,
            'lon' => $this->lon,
            'closestTemperature' => $closestTemperature,
            'closestHumidity' => $closestHumidity,
            'wetBulb' => $wetBulb,
        ];
    }

    protected function fetchNoaa($url)
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'cantheymakesnow.com (support@albrightlabs.com)'])
                ->connectTimeout(self::HTTP_CONNECT_TIMEOUT)
                ->timeout(self::HTTP_TIMEOUT)
                ->get($url);
        } catch (\Throwable $e) {
            return null;
        }

        if (!in_array($response->status(), [200, 301], true)) {
            return null;
        }

        return $response->json();
    }

}
