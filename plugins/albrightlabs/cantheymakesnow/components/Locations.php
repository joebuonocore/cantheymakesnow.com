<?php namespace AlbrightLabs\CanTheyMakeSnow\Components;

use AlbrightLabs\CanTheyMakeSnow\Classes\SnowBoard;
use AlbrightLabs\CanTheyMakeSnow\Classes\Units;
use AlbrightLabs\CanTheyMakeSnow\Models\Location;
use AlbrightLabs\CanTheyMakeSnow\Models\Resort;

use Cms\Classes\ComponentBase;

/**
 * Locations Component
 *
 * @link https://docs.octobercms.com/3.x/extend/cms-components.html
 */
class Locations extends ComponentBase
{
    public $locations;
    public $resorts;

    public function componentDetails()
    {
        return [
            'name' => 'Locations Component',
            'description' => 'Looked-up cities and curated resorts for the map and search.'
        ];
    }

    /**
     * @link https://docs.octobercms.com/3.x/element/inspector-types.html
     */
    public function defineProperties()
    {
        return [];
    }

    public function onRun()
    {
        $this->locations = $this->getLocations();
        $this->page['locations'] = $this->locations;

        $this->resorts = $this->getResorts();
        $this->page['resorts'] = $this->resorts;

        $this->page['mapPoints'] = $this->getMapPoints();
        $this->page['popularResorts'] = $this->popularResorts();
    }

    /**
     * Enabled resorts grouped by state name (A–Z), for the resort directory.
     * Returns [['code' => 'VT', 'name' => 'Vermont', 'url' => state page, 'resorts' => Resort[]], ...].
     */
    public function resortsByState(): array
    {
        return Resort::isEnabled()->orderBy('name')->get()
            ->groupBy('state')
            ->map(fn ($resorts, $code) => ['code' => $code, 'name' => Resort::STATE_NAMES[$code] ?? $code, 'url' => Resort::stateUrl($code), 'resorts' => $resorts])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * The most-checked resorts, for links on the home page.
     */
    public function popularResorts(int $limit = 12)
    {
        return Resort::isEnabled()->orderByDesc('lookups')->orderBy('name')->limit($limit)->get();
    }

    /**
     * Resorts ranked by new snow (optionally in one state), for the snow
     * forecast page, the home page and state pages.
     */
    public function snowBoard(?string $state = null): array
    {
        return SnowBoard::build(null, $state);
    }

    /**
     * Resorts ranked by snowmaking hours in the next 48 (optionally in one state).
     */
    public function snowmakingBoard(?string $state = null): array
    {
        return SnowBoard::snowmaking(null, $state);
    }

    /**
     * Every resort in a state with its stored forecast, for the state page table.
     */
    public function stateResorts(string $state): array
    {
        return SnowBoard::stateResorts($state);
    }

    /**
     * Resorts and looked-up cities as plain map points with their last
     * known snowmaking tier and when it was checked (ISO 8601, or null).
     * Resorts also carry their 48-hour new snow, pre-formatted in both
     * unit systems for the map's snow mode.
     */
    public function getMapPoints(): array
    {
        $points = [];

        foreach ($this->resorts as $resort) {
            $points[] = [
                'type' => 'resort',
                'slug' => $resort->slug,
                'state' => $resort->state,
                'label' => "{$resort->name}, {$resort->state}",
                'lat' => (float) $resort->lat,
                'lng' => (float) $resort->lon,
                'tier' => $resort->last_tier,
                'checkedAt' => $resort->last_checked_at ? $resort->last_checked_at->toIso8601String() : null,
                'snow48' => $resort->last_snow_48h_in === null ? null : [
                    'in' => (float) $resort->last_snow_48h_in,
                    'f' => Units::snow((float) $resort->last_snow_48h_in, 'f'),
                    'c' => Units::snow((float) $resort->last_snow_48h_in, 'c'),
                ],
            ];
        }

        foreach ($this->locations as $location) {
            $points[] = [
                'type' => 'city',
                'label' => "{$location->city}, {$location->state}",
                'lat' => (float) $location->lat,
                'lng' => (float) $location->lon,
                'tier' => $location->last_tier,
                'checkedAt' => $location->last_looked_up_at ? $location->last_looked_up_at->toIso8601String() : null,
            ];
        }

        return $points;
    }

    public function getResorts()
    {
        return Resort::isEnabled()
            ->orderBy('name')
            ->get(['slug', 'name', 'state', 'lat', 'lon', 'last_wet_bulb', 'last_tier', 'last_checked_at', 'last_snow_48h_in']);
    }

    public function getLocations()
    {
        return Location::whereNotNull('lat')
            ->whereNotNull('lon')
            ->orderByDesc('lookups')
            ->limit(500)
            ->get(['city', 'state', 'lat', 'lon', 'lookups', 'last_wet_bulb', 'last_tier', 'last_looked_up_at']);
    }
}
