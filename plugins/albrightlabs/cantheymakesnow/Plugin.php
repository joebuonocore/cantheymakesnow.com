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
     * registerMarkupTags: `temp` renders a Celsius value in both units; the
     * theme shows one via the html[data-units] attribute. `tempSvg` does the
     * same with <tspan>s for use inside SVG <text>. Output is numeric only.
     */
    public function registerMarkupTags()
    {
        $format = function ($celsius, $decimals = 0, $unit = 'c') {
            $value = $unit === 'f' ? $celsius * 9 / 5 + 32 : $celsius;
            $text = number_format(round($value, $decimals), $decimals);
            if ((float) $text == 0) {
                $text = ltrim($text, '-');
            }
            return str_replace('-', "\u{2212}", $text) . ($unit === 'f' ? '°F' : '°C');
        };

        return [
            'filters' => [
                'temp' => function ($celsius, $decimals = 0) use ($format) {
                    if ($celsius === null) {
                        return '';
                    }
                    return '<span class="t"><span class="u-f">' . $format($celsius, $decimals, 'f') . '</span>'
                        . '<span class="u-c">' . $format($celsius, $decimals, 'c') . '</span></span>';
                },
                'tempSvg' => function ($celsius, $decimals = 0) use ($format) {
                    if ($celsius === null) {
                        return '';
                    }
                    return '<tspan class="u-f">' . $format($celsius, $decimals, 'f') . '</tspan>'
                        . '<tspan class="u-c">' . $format($celsius, $decimals, 'c') . '</tspan>';
                },
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
