<?php namespace AlbrightLabs\CanTheyMakeSnow\Components;

use Carbon\Carbon;
use Cms\Classes\ComponentBase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Session;
use AlbrightLabs\CanTheyMakeSnow\Classes\ShareImage;
use AlbrightLabs\CanTheyMakeSnow\Classes\SnowReport;
use AlbrightLabs\CanTheyMakeSnow\Models\Location;
use AlbrightLabs\CanTheyMakeSnow\Models\Resort;

/**
 * WeatherData Component
 *
 * Runs a lookup (address, coordinates or resort) through SnowReport and
 * exposes a single `result` (or `lookupError`) to the theme's `result`
 * partial. Used both on page load (shareable GET URLs) and through the
 * onLookup AJAX handler, and records lookups for the map.
 *
 * @link https://docs.octobercms.com/3.x/extend/cms-components.html
 */
class WeatherData extends ComponentBase
{
    const LOOKUP_DEDUPE_SECONDS = 1800;

    protected SnowReport $report;

    public function init()
    {
        $this->report = new SnowReport();
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
        $this->runLookup(get('address'), get('lat'), get('lon'), get('resort'));
    }

    /**
     * AJAX lookup. Updates the `result` partial and returns data the
     * browser needs to keep the URL, title and map in sync.
     */
    public function onLookup()
    {
        $result = $this->runLookup(post('address'), post('lat'), post('lon'), post('resort'));

        return [
            'ok' => (bool) $result,
            'shareUrl' => $result['shareUrl'] ?? null,
            'pageTitle' => $result ? $this->report->metaTitle($result) : null,
            'pin' => $result ? ['lat' => $result['pinLat'], 'lng' => $result['pinLon'], 'tier' => $result['answer']['tier']] : null,
        ];
    }

    /**
     * Resolve the request into a result or an error and publish it to the page.
     * Returns the result array, or null when there is nothing to show.
     */
    protected function runLookup($address, $lat, $lon, $resortSlug = null): ?array
    {
        $this->page['query'] = is_string($address) ? trim($address) : null;
        $this->page['result'] = null;
        $this->page['lookupError'] = null;

        $report = $this->report->resolve($address, $lat, $lon, $resortSlug);
        if (!$report) {
            return null;
        }
        if (isset($report['error'])) {
            $this->page['lookupError'] = ['code' => $report['error'], 'message' => SnowReport::errorMessage($report['error'])];
            return null;
        }

        $result = $report['result'];
        $this->page['result'] = $result;
        $this->page['query'] = $report['address'] ?: $result['place'];
        $this->page->meta_title = $this->report->metaTitle($result);
        $this->page->meta_description = $this->report->metaDescription($result);
        $this->page['shareImage'] = ShareImage::available() ? url('share.png') . '?' . http_build_query($result['query']) : null;

        $report['resort'] ? $this->recordResortLookup($report['resort'], $result) : $this->recordLookup($result);

        return $result;
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
            $query->update($updates + ['last_looked_up_at' => Carbon::now()] + $this->conditions($result));
        } else {
            $query->increment('lookups', 1, $updates + ['last_looked_up_at' => Carbon::now()] + $this->conditions($result));
            $recent[$locationKey] = time();
        }

        Session::put($sessionKey, $recent);
    }

    /**
     * Count a resort lookup (once per session per LOOKUP_DEDUPE_SECONDS)
     * and remember its latest conditions for the map.
     */
    protected function recordResortLookup(Resort $resort, array $result)
    {
        $sessionKey = 'cantheymakesnow.recent_resorts';
        $recent = array_filter(
            (array) Session::get($sessionKey, []),
            fn ($at) => $at > time() - self::LOOKUP_DEDUPE_SECONDS
        );

        $conditions = $this->conditions($result) + ['last_checked_at' => Carbon::now()];
        $query = Resort::whereKey($resort->getKey());
        if (isset($recent[$resort->slug])) {
            $query->update($conditions);
        } else {
            $query->increment('lookups', 1, $conditions);
            $recent[$resort->slug] = time();
        }

        Session::put($sessionKey, $recent);
    }

    protected function conditions(array $result): array
    {
        return [
            // For resorts, the colder of base and summit, so map pins show
            // snowmaking when it's possible anywhere on the mountain.
            'last_wet_bulb' => round($result['answer']['wetBulbC'], 2),
            'last_tier' => $result['answer']['tier'],
        ];
    }

}
