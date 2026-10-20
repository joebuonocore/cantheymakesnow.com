<?php namespace AlbrightLabs\CanTheyMakeSnow\Classes;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin, cached client for api.weather.gov.
 *
 * Every method returns ['ok' => bool, 'error' => null|'not_found'|'unavailable', 'data' => ?array].
 * NOAA answers 404 for points outside its coverage, which callers surface
 * as "outside the US" rather than as an outage. Failures are never cached.
 */
class NoaaClient
{
    const BASE_URL = 'https://api.weather.gov';
    const USER_AGENT = 'cantheymakesnow.com (support@albrightlabs.com)';
    const HTTP_TIMEOUT = 10;
    const HTTP_CONNECT_TIMEOUT = 5;

    const CACHE_TTL_POINTS = 86400;
    const CACHE_TTL_GRIDPOINTS = 1800;
    const CACHE_TTL_STATIONS = 86400;
    const CACHE_TTL_OBSERVATION = 600;

    public function points(float $lat, float $lon): array
    {
        return $this->cached("noaa:points:{$lat},{$lon}", self::CACHE_TTL_POINTS, "/points/{$lat},{$lon}");
    }

    public function gridpoints(string $gridId, int $gridX, int $gridY): array
    {
        return $this->cached("noaa:grid:{$gridId}:{$gridX},{$gridY}", self::CACHE_TTL_GRIDPOINTS, "/gridpoints/{$gridId}/{$gridX},{$gridY}");
    }

    /**
     * 7-day forecast in 12-hour day/night periods.
     */
    public function forecast(string $gridId, int $gridX, int $gridY): array
    {
        return $this->cached("noaa:forecast:{$gridId}:{$gridX},{$gridY}", self::CACHE_TTL_GRIDPOINTS, "/gridpoints/{$gridId}/{$gridX},{$gridY}/forecast");
    }

    /**
     * Latest observation from the nearest station to a grid cell.
     * Adds a 'station' key (id, name, elevation in metres) to the data.
     */
    public function latestObservation(string $gridId, int $gridX, int $gridY): array
    {
        $stations = $this->cached(
            "noaa:stations:{$gridId}:{$gridX},{$gridY}",
            self::CACHE_TTL_STATIONS,
            "/gridpoints/{$gridId}/{$gridX},{$gridY}/stations?limit=1"
        );
        $station = $stations['data']['features'][0]['properties'] ?? null;
        if (!$stations['ok'] || !isset($station['stationIdentifier'])) {
            return ['ok' => false, 'error' => $stations['error'] ?? 'not_found', 'data' => null];
        }

        $id = $station['stationIdentifier'];
        $observation = $this->cached("noaa:obs:{$id}", self::CACHE_TTL_OBSERVATION, "/stations/{$id}/observations/latest");
        if ($observation['ok']) {
            $observation['data']['station'] = [
                'id' => $id,
                'name' => $station['name'] ?? $id,
                'elevationM' => $station['elevation']['value'] ?? null,
            ];
        }
        return $observation;
    }

    protected function cached(string $key, int $ttl, string $path): array
    {
        if ($data = Cache::get($key)) {
            return ['ok' => true, 'error' => null, 'data' => $data];
        }

        $result = $this->fetch(self::BASE_URL . $path);
        if ($result['ok']) {
            Cache::put($key, $result['data'], $ttl);
        }
        return $result;
    }

    protected function fetch(string $url): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'application/geo+json'])
                ->connectTimeout(self::HTTP_CONNECT_TIMEOUT)
                ->timeout(self::HTTP_TIMEOUT)
                ->get($url);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'unavailable', 'data' => null];
        }

        if ($response->status() === 404) {
            return ['ok' => false, 'error' => 'not_found', 'data' => null];
        }
        if (!$response->successful() || !is_array($response->json())) {
            return ['ok' => false, 'error' => 'unavailable', 'data' => null];
        }

        return ['ok' => true, 'error' => null, 'data' => $response->json()];
    }
}
