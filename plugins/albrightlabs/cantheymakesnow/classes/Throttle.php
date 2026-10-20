<?php namespace AlbrightLabs\CanTheyMakeSnow\Classes;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-visitor rate limits for work that costs money (Google geocoding) or
 * hits NOAA on a cache miss (arbitrary coordinates). Limits are per minute.
 *
 * Behind a proxy or CDN, set CLIENT_IP_HEADER (e.g. CF-Connecting-IP) so
 * visitors aren't all counted as the proxy's address.
 */
class Throttle
{
    const LIMITS = [
        'lookup' => 60,
        'geocode' => 20,
    ];

    /**
     * Count one attempt. Returns false when the visitor is over the limit.
     */
    public static function attempt(string $name): bool
    {
        return RateLimiter::attempt('ctms:' . $name . ':' . self::clientIp(), self::LIMITS[$name], fn () => true, 60);
    }

    public static function clientIp(): string
    {
        $header = env('CLIENT_IP_HEADER');
        if ($header && ($value = request()->header($header))) {
            return trim(explode(',', $value)[0]);
        }
        return (string) request()->ip();
    }
}
