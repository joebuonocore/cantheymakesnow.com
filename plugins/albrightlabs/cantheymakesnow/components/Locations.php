<?php namespace AlbrightLabs\CanTheyMakeSnow\Components;

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
    }

    /**
     * Resorts and looked-up cities as plain map points with their last
     * known snowmaking tier and when it was checked (ISO 8601, or null).
     */
    public function getMapPoints(): array
    {
        $points = [];

        foreach ($this->resorts as $resort) {
            $points[] = [
                'type' => 'resort',
                'slug' => $resort->slug,
                'label' => "{$resort->name}, {$resort->state}",
                'lat' => (float) $resort->lat,
                'lng' => (float) $resort->lon,
                'tier' => $resort->last_tier,
                'checkedAt' => $resort->last_checked_at ? $resort->last_checked_at->toIso8601String() : null,
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
            ->get(['slug', 'name', 'state', 'lat', 'lon', 'last_wet_bulb', 'last_tier', 'last_checked_at']);
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
