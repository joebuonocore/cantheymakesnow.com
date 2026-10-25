<?php namespace AlbrightLabs\CanTheyMakeSnow\Classes;

use AlbrightLabs\CanTheyMakeSnow\Models\Resort;

/**
 * The site's public pages, for sitemap.xml and IndexNow.
 */
class Sitemap
{
    /**
     * Every public page: home, the resort directory, alerts, privacy and
     * each enabled resort.
     */
    public static function urls(): array
    {
        $base = rtrim(url('/'), '/');
        return array_merge(["{$base}/", "{$base}/resorts", "{$base}/alerts", "{$base}/privacy"], self::resortUrls());
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
