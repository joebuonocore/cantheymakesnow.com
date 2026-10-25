<?php namespace AlbrightLabs\CanTheyMakeSnow;

use Backend;
use Cms\Classes\Content;
use Cms\Classes\Layout;
use Cms\Classes\Page;
use Cms\Classes\Partial;
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
        $this->registerConsoleCommand('ctms.send-alerts', \AlbrightLabs\CanTheyMakeSnow\Console\SendAlerts::class);
        $this->registerConsoleCommand('ctms.indexnow', \AlbrightLabs\CanTheyMakeSnow\Console\SubmitIndexNow::class);
    }

    /**
     * registerSchedule: check alerts hourly and ping IndexNow daily (the
     * host already runs `php artisan schedule:run` every minute).
     */
    public function registerSchedule($schedule)
    {
        $schedule->command('ctms:send-alerts')->hourly()->withoutOverlapping(30);
        $schedule->command('ctms:indexnow')->dailyAt('11:00');
    }

    /**
     * boot method, called right before the request route.
     */
    public function boot()
    {
        // Forge deploys each release into its own folder but shares storage/,
        // and October keys compiled Twig templates by theme path alone. A copy
        // compiled from the previous release just before the switch looks
        // newer than the new release's files, so it kept being served. Keying
        // by release folder too means every release compiles its own.
        $release = substr(md5(base_path()), 0, 8);
        foreach ([Page::class, Layout::class, Partial::class, Content::class] as $class) {
            $class::extend(function ($object) use ($release) {
                $object->bindEvent('cmsObject.getTwigCacheKey', fn ($key) => "{$key}@{$release}");
            });
        }
    }

    /**
     * registerComponents used by the frontend.
     */
    public function registerComponents()
    {
        return [
            'AlbrightLabs\CanTheyMakeSnow\Components\WeatherData' => 'WeatherData',
            'AlbrightLabs\CanTheyMakeSnow\Components\Locations' => 'Locations',
            'AlbrightLabs\CanTheyMakeSnow\Components\Alerts' => 'Alerts',
        ];
    }

    /**
     * registerMailLayouts: branded layout used by every email this plugin sends.
     */
    public function registerMailLayouts()
    {
        return [
            'ctms' => 'albrightlabs.cantheymakesnow::mail.layout-ctms',
        ];
    }

    /**
     * registerMailTemplates: alert emails and the opt-in/manage messages.
     */
    public function registerMailTemplates()
    {
        return [
            'albrightlabs.cantheymakesnow::mail.confirm',
            'albrightlabs.cantheymakesnow::mail.manage',
            'albrightlabs.cantheymakesnow::mail.alert',
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
                'url' => Backend::url('albrightlabs/cantheymakesnow/resorts'),
                'icon' => 'icon-snowflake-o',
                'permissions' => ['albrightlabs.cantheymakesnow'],
                'order' => 500,
                'sideMenu' => [
                    'resorts' => [
                        'label' => 'Resorts',
                        'icon' => 'icon-area-chart',
                        'url' => Backend::url('albrightlabs/cantheymakesnow/resorts'),
                        'permissions' => ['albrightlabs.cantheymakesnow'],
                    ],
                    'locations' => [
                        'label' => 'Locations',
                        'icon' => 'icon-map-marker',
                        'url' => Backend::url('albrightlabs/cantheymakesnow/locations'),
                        'permissions' => ['albrightlabs.cantheymakesnow'],
                    ],
                ],
            ],
            'subscribers' => [
                'label' => 'Subscribers',
                'url' => Backend::url('albrightlabs/cantheymakesnow/subscribers'),
                'icon' => 'icon-users',
                'iconSvg' => 'plugins/albrightlabs/cantheymakesnow/assets/images/subscribers.png',
                'permissions' => ['albrightlabs.cantheymakesnow'],
                'order' => 501,
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
                'label' => 'Manage locations, resorts and subscribers',
            ],
        ];
    }
}
