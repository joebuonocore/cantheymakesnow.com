<?php namespace AlbrightLabs\CanTheyMakeSnow;

use Backend;
use System\Classes\PluginBase;

/**
 * Plugin Information File
 *
 * @link https://docs.octobercms.com/3.x/extend/system/plugins.html
 */
class Plugin extends PluginBase
{
    /**
     * pluginDetails about this plugin.
     */
    public function pluginDetails()
    {
        return [
            'name' => 'CanTheyMakeSnow',
            'description' => 'A base plugin for cantheymakesnow.com that tracks lookup locations.',
            'author' => 'Albright Labs LLC',
            'icon' => 'icon-snowflake'
        ];
    }

    /**
     * register method, called when the plugin is first registered.
     */
    public function register()
    {
        //
    }

    /**
     * boot method, called right before the request route.
     */
    public function boot()
    {
        //
    }

    /**
     * registerComponents used by the frontend.
     */
    public function registerComponents()
    {
        return [
            'AlbrightLabs\CanTheyMakeSnow\Components\WeatherData' => 'WeatherData',
            'AlbrightLabs\CanTheyMakeSnow\Components\Locations' => 'Locations',
        ];
    }

    /**
     * registerPermissions used by the backend.
     */
    public function registerPermissions()
    {
        return []; // Remove this line to activate

        return [
            'albrightlabs.cantheymakesnow.some_permission' => [
                'tab' => 'CanTheyMakeSnow',
                'label' => 'Some permission'
            ],
        ];
    }

    /**
     * registerNavigation used by the backend.
     */
    public function registerNavigation()
    {
        return [
            'locations' => [
                'label' => 'Locations',
                'url' => Backend::url('albrightlabs/cantheymakesnow/locations'),
                'icon' => 'icon-snowflake-o',
                'permissions' => ['*'],
                'order' => 500,
            ],
        ];
    }
}
