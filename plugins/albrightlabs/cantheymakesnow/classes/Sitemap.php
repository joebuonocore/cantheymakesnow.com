<?php namespace AlbrightLabs\CanTheyMakeSnow\Classes;

use AlbrightLabs\CanTheyMakeSnow\Models\Resort;

/**
 * The site's public pages, for sitemap.xml and IndexNow.
 */
class Sitemap
{
    /**
     * Every public page: home, the forecast boards, Will it snow?, the
     * resort directory, alerts, the widget, privacy, each state with resorts
     * and each enabled resort.
     */
    public static function urls(): array
    {
        $base = rtrim(url('/'), '/');
        return array_merge(
            ["{$base}/", "{$base}/snow-forecast", "{$base}/snowmaking-forecast", "{$base}/will-it-snow", "{$base}/resorts", "{$base}/alerts", "{$base}/embed", "{$base}/privacy"],
            self::stateUrls(),
            self::resortUrls()
        );
    }

    /**
     * The pages that change with the forecast, for the daily IndexNow ping:
     * home, the forecast boards, state pages and resort pages.
     */
    public static function forecastUrls(): array
    {
        $base = rtrim(url('/'), '/');
        return array_merge(["{$base}/", "{$base}/snow-forecast", "{$base}/snowmaking-forecast"], self::stateUrls(), self::resortUrls());
    }

    /**
     * State pages, for states with at least one enabled resort.
     */
    public static function stateUrls(): array
    {
        return Resort::isEnabled()->distinct()->orderBy('state')->pluck('state')
            ->map(fn ($code) => Resort::stateUrl($code))
            ->all();
    }

    /**
     * The enabled resort pages, by slug.
     */
    public static function resortUrls(): array
    {
        return Resort::isEnabled()->orderBy('slug')->pluck('slug')
            ->map(fn ($slug) => Resort::urlFor($slug))
            ->all();
    }

    public static function xml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (self::urls() as $url) {
            $xml .= '  <url><loc>' . e($url) . '</loc><changefreq>hourly</changefreq></url>' . "\n";
        }
        return $xml . '</urlset>' . "\n";
    }
}
