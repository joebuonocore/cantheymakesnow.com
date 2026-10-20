<?php

use AlbrightLabs\CanTheyMakeSnow\Classes\ShareImage;
use AlbrightLabs\CanTheyMakeSnow\Classes\SnowReport;
use Illuminate\Support\Facades\Cache;

/**
 * Social share card for a lookup: /share.png?resort=… | ?address=… | ?lat=…&lon=…
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
        $report = (new SnowReport())->resolve($input['address'] ?? null, $input['lat'] ?? null, $input['lon'] ?? null, $input['resort'] ?? null);
        if (!$report || isset($report['error'])) {
            abort(404);
        }
        $png = (new ShareImage())->render($report['result']);
        if (!$png) {
            abort(404);
        }
        Cache::put($cacheKey, base64_encode($png), 1800);
    } else {
        $png = base64_decode($png);
    }

    return response($png, 200, [
        'Content-Type' => 'image/png',
        'Cache-Control' => 'public, max-age=1800',
    ]);
});
