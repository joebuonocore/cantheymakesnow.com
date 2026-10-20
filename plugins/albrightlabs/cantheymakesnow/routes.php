<?php

use AlbrightLabs\CanTheyMakeSnow\Classes\ShareImage;
use AlbrightLabs\CanTheyMakeSnow\Classes\SnowReport;
use AlbrightLabs\CanTheyMakeSnow\Models\Resort;
use Illuminate\Support\Facades\Cache;

/**
 * Social share card for a lookup: /share.png?resort=… | ?address=… | ?lat=…&lon=…
 * With no query it draws the site-wide card used by the home page.
 * Cached per query for 30 minutes (NOAA's grid is refreshed about hourly).
 */
Route::get('share.png', function () {
    if (!ShareImage::available()) {
        abort(404);
    }

    $input = array_filter(request()->only(['address', 'lat', 'lon', 'resort']), 'is_string');
    $cacheKey = 'share-png:' . md5(json_encode($input));

    $png = Cache::get($cacheKey);
    if (!$png) {
        if (!$input) {
            $png = (new ShareImage())->renderDefault();
        } else {
            $report = (new SnowReport())->resolve($input['address'] ?? null, $input['lat'] ?? null, $input['lon'] ?? null, $input['resort'] ?? null);
            if (($report['error'] ?? null) === 'rate_limited') {
                abort(429);
            }
            if (!$report || isset($report['error'])) {
                abort(404);
            }
            $png = (new ShareImage())->render($report['result']);
        }
        if (!$png) {
            abort(404);
        }
        Cache::put($cacheKey, base64_encode($png), $input ? 1800 : 86400);
    } else {
        $png = base64_decode($png);
    }

    return response($png, 200, [
        'Content-Type' => 'image/png',
        'Cache-Control' => 'public, max-age=1800',
    ]);
});

// robots.txt and favicon.ico are static files in the web root: nginx serves
// those two names itself and would 404 them before they reach the app.

Route::get('sitemap.xml', function () {
    $base = rtrim(url('/'), '/');
    $urls = ["{$base}/"];
    foreach (Resort::isEnabled()->orderBy('slug')->pluck('slug') as $slug) {
        $urls[] = "{$base}/?" . http_build_query(['resort' => $slug]);
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $url) {
        $xml .= '  <url><loc>' . e($url) . '</loc><changefreq>hourly</changefreq></url>' . "\n";
    }
    $xml .= '</urlset>' . "\n";

    return response($xml, 200, ['Content-Type' => 'application/xml']);
});
