<?php

use AlbrightLabs\CanTheyMakeSnow\Classes\Embed;
use AlbrightLabs\CanTheyMakeSnow\Classes\IndexNow;
use AlbrightLabs\CanTheyMakeSnow\Classes\ShareImage;
use AlbrightLabs\CanTheyMakeSnow\Classes\Sitemap;
use AlbrightLabs\CanTheyMakeSnow\Classes\SnowReport;
use AlbrightLabs\CanTheyMakeSnow\Models\Resort;
use AlbrightLabs\CanTheyMakeSnow\Models\Subscriber;
use AlbrightLabs\CanTheyMakeSnow\Models\Subscription;
use Illuminate\Support\Facades\Cache;

/**
 * Social share card for a lookup: /share.png?resort=… | ?address=… | ?lat=…&lon=…
 * (add &mode=snow for the "Will it snow?" card). With no query it draws the
 * site-wide card used by the home page.
 * Cached per query for 30 minutes (NOAA's grid is refreshed about hourly).
 */
Route::get('share.png', function () {
    if (!ShareImage::available()) {
        abort(404);
    }

    $input = array_filter(request()->only(['address', 'lat', 'lon', 'resort']), 'is_string');
    // mode=snow draws the "Will it snow?" card for that page's results.
    $snowMode = request('mode') === 'snow' && $input;
    $cacheKey = 'share-png:' . md5(json_encode($input) . ($snowMode ? ':snow' : ''));

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
            $png = $snowMode ? (new ShareImage())->renderSnow($report['result']) : (new ShareImage())->render($report['result']);
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

/**
 * Alert confirmation link (signed, from the confirm email). Confirms the
 * subscription and its subscriber, then shows the manage page.
 */
Route::get('alerts/confirm/{subscription}', function ($id) {
    if (!request()->hasValidSignature(false)) {
        return redirect('alerts?expired=1');
    }
    $subscription = Subscription::with(['subscriber', 'resort'])->find($id);
    if (!$subscription) {
        return redirect('alerts?expired=1');
    }

    $subscription->confirmed_at = $subscription->confirmed_at ?: now();
    $subscription->save();
    $subscriber = $subscription->subscriber;
    $subscriber->confirmed_at = $subscriber->confirmed_at ?: now();
    $subscriber->unsubscribed_at = null;
    $subscriber->save();

    $confirmed = $subscription->resort ? $subscription->resort->slug : 'area-' . $subscription->id;
    return redirect('alerts/manage/' . $subscriber->token . '?' . http_build_query(['confirmed' => $confirmed]));
})->name('ctms.alerts.confirm');

/**
 * RFC 8058 one-click unsubscribe (the List-Unsubscribe header in alert
 * emails). Mail clients POST here; a GET shows the confirm page instead.
 */
Route::match(['get', 'post'], 'alerts/unsubscribe-now/{token}', function ($token) {
    $subscriber = Subscriber::findByToken($token);
    if (request()->isMethod('get')) {
        return redirect('alerts/unsubscribe/' . $token);
    }
    if ($subscriber && !$subscriber->unsubscribed_at) {
        $subscriber->unsubscribed_at = now();
        $subscriber->save();
    }
    return response('Unsubscribed.', 200, ['Content-Type' => 'text/plain']);
});

// robots.txt and favicon.ico are static files in the web root: nginx serves
// those two names itself and would 404 them before they reach the app.

Route::get('sitemap.xml', function () {
    return response(Sitemap::xml(), 200, ['Content-Type' => 'application/xml']);
});

/**
 * IndexNow key file: search engines fetch it to confirm our submissions.
 */
Route::get(IndexNow::KEY . '.txt', function () {
    return response(IndexNow::KEY, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
});

/**
 * The embeddable widget: embed.js upgrades <a class="ctms-widget"> links on
 * other sites into a forecast card, using /embed/{slug}.json. Both are open
 * to any origin and read only what's stored on the resort.
 */
Route::get('embed.js', function () {
    $path = themes_path('site/assets/js/embed.js');
    return response(file_get_contents($path), 200, [
        'Content-Type' => 'application/javascript; charset=utf-8',
        'Cache-Control' => 'public, max-age=86400',
        'Access-Control-Allow-Origin' => '*',
    ]);
});

Route::get('embed/{slug}.json', function ($slug) {
    $headers = ['Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'public, max-age=900'];
    $resort = Resort::isEnabled()->where('slug', $slug)->first();
    if (!$resort) {
        return response()->json(['error' => 'not_found'], 404, $headers);
    }
    return response()->json(Embed::data([
        'name' => $resort->name,
        'state' => $resort->state,
        'url' => $resort->url,
        'tier' => $resort->last_tier,
        'wetBulbC' => $resort->last_wet_bulb,
        'snow48In' => $resort->last_snow_48h_in,
        'smHours' => $resort->last_sm_hours_48,
        'smNextStart' => $resort->last_sm_next_start,
        'timeZone' => $resort->time_zone,
        'checkedAt' => $resort->last_checked_at,
    ], now()), 200, $headers);
})->where('slug', '[a-z0-9-]+');
