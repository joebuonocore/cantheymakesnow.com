<?php namespace AlbrightLabs\CanTheyMakeSnow;

use Backend;
use AlbrightLabs\CanTheyMakeSnow\Classes\ShareImage;
use AlbrightLabs\CanTheyMakeSnow\Classes\Units;
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
     * registerMarkupTags: `temp`, `snow`, `snowRange` and `speed` render a
     * value in both unit systems; the theme shows one via the
     * html[data-units] attribute. `tempSvg` does the same with <tspan>s for
     * use inside SVG <text>. Output is numeric only.
     */
    public function registerMarkupTags()
    {
        $both = fn (callable $format) => '<span class="t"><span class="u-f">' . e($format('f')) . '</span>'
            . '<span class="u-c">' . e($format('c')) . '</span></span>';

        return [
            'functions' => [
                // Site-wide card for pages without a lookup result (null if it can't be drawn).
                'defaultShareImage' => fn () => ShareImage::available() ? url('share.png') : null,
            ],
            'filters' => [
                'temp' => fn ($celsius, $decimals = 0) => $celsius === null ? ''
                    : $both(fn ($u) => Units::temp($celsius, $u, $decimals)),
                'tempSvg' => fn ($celsius, $decimals = 0) => $celsius === null ? ''
                    : '<tspan class="u-f">' . Units::temp($celsius, 'f', $decimals) . '</tspan>'
                        . '<tspan class="u-c">' . Units::temp($celsius, 'c', $decimals) . '</tspan>',
                'snow' => fn ($inches) => $inches === null ? ''
                    : $both(fn ($u) => Units::snow($inches, $u)),
                'snowText' => fn ($inches, $system = 'f') => $inches === null ? '' : Units::snow($inches, $system),
                'snowRange' => fn ($range) => empty($range) ? ''
                    : $both(fn ($u) => Units::snowRange($range['lo'], $range['hi'], $u)),
                'speed' => fn ($mph) => $mph === null ? ''
                    : $both(fn ($u) => Units::speed($mph, $u)),
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
                'permissions' => ['albrightlabs.cantheymakesnow'],
                'order' => 500,
                'sideMenu' => [
                    'locations' => [
                        'label' => 'Lookup locations',
                        'icon' => 'icon-map-marker',
                        'url' => Backend::url('albrightlabs/cantheymakesnow/locations'),
                        'permissions' => ['albrightlabs.cantheymakesnow'],
                    ],
                    'resorts' => [
                        'label' => 'Resorts',
                        'icon' => 'icon-area-chart',
                        'url' => Backend::url('albrightlabs/cantheymakesnow/resorts'),
                        'permissions' => ['albrightlabs.cantheymakesnow'],
                    ],
                ],
            ],
        ];
    }

    /**
     * registerPermissions used by the backend.
     */
    public function registerPermissions()
    {
        return [
            'albrightlabs.cantheymakesnow' => [
                'tab' => 'CanTheyMakeSnow',
                'label' => 'Manage lookup locations and resorts',
            ],
        ];
    }
}
