<?php namespace AlbrightLabs\CanTheyMakeSnow\Components;

use AlbrightLabs\CanTheyMakeSnow\Models\Location;

use Cms\Classes\ComponentBase;

/**
 * Locations Component
 *
 * @link https://docs.octobercms.com/3.x/extend/cms-components.html
 */
class Locations extends ComponentBase
{
    public $locations;

    public function componentDetails()
    {
        return [
            'name' => 'Locations Component',
            'description' => ''
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
    }

    public function getLocations()
    {
        return Location::all();
    }
}
