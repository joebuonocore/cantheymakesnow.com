<?php namespace AlbrightLabs\CanTheyMakeSnow\Classes;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use AlbrightLabs\CanTheyMakeSnow\Models\Resort;

/**
 * The season so far, from the hourly log (ResortHour): weekly snowmaking
 * hours and new snow for a resort's page, and the early-season snowmaking
 * watch across all resorts. Each logged hour holds the shortest-range
 * forecast for it, so this is close to what happened, but still forecast.
 */
class SeasonHistory
{
    const TABLE = 'albrightlabs_cantheymakesnow_resort_hours';
    const CACHE_SECONDS = 3600;

    /**
     * The hourly log started on this date; the pages say history begins here.
     */
    const LOG_STARTED = '2026-11-10';

    /**
     * The season starts on 1 October (of this year from July on, else last year).
     */
    public static function seasonStart(Carbon $now): Carbon
    {
        $year = $now->month >= 7 ? $now->year : $now->year - 1;
        return Carbon::create($year, 10, 1, 0, 0, 0, 'UTC');
    }

    /**
     * Logged hours grouped into local weeks (Monday to Sunday), oldest first.
     * $rows: ['hour' => Carbon (UTC), 'base' => ?float, 'summit' => ?float, 'snow' => ?float].
     * Each week: start ('Y-m-d'), label ('Nov 10'), smHours (either zone below
     * the snowmaking line), greatHours, snowIn and hoursLogged (coverage).
     */
    public static function weekly(array $rows, string $timeZone): array
    {
        $weeks = [];
        foreach ($rows as $row) {
            $monday = $row['hour']->copy()->setTimezone($timeZone)->startOfWeek(Carbon::MONDAY);
            $key = $monday->format('Y-m-d');
            $weeks[$key] ??= ['start' => $key, 'label' => $monday->format('M j'), 'smHours' => 0, 'greatHours' => 0, 'snowIn' => 0.0, 'hoursLogged' => 0];
            $coldest = self::coldest($row['base'], $row['summit']);
            $weeks[$key]['hoursLogged']++;
            if ($coldest !== null && $coldest < WeatherService::SNOWMAKING_THRESHOLD_C) {
                $weeks[$key]['smHours']++;
            }
            if ($coldest !== null && $coldest < -4.0) {
                $weeks[$key]['greatHours']++;
            }
            $weeks[$key]['snowIn'] += (float) ($row['snow'] ?? 0);
        }
        ksort($weeks);
        return array_values(array_map(fn ($w) => ['snowIn' => round($w['snowIn'], 1)] + $w, $weeks));
    }

    /**
     * The resort's season so far: ['weeks' => weekly(), 'totals' =>
     * [smHours, greatHours, snowIn, hoursLogged], 'since' => 'Y-m-d'].
     */
    public static function forResort(Resort $resort, ?Carbon $now = null): array
    {
        $now = $now ?: Carbon::now('UTC');
        return Cache::remember("ctms:season:{$resort->id}", self::CACHE_SECONDS, function () use ($resort, $now) {
            $rows = DB::table(self::TABLE)
                ->where('resort_id', $resort->id)
                ->whereBetween('hour', [self::seasonStart($now), $now])
                ->orderBy('hour')
                ->get(['hour', 'base_wet_bulb', 'summit_wet_bulb', 'snow_in'])
                ->map(fn ($r) => [
                    'hour' => Carbon::parse($r->hour, 'UTC'),
                    'base' => $r->base_wet_bulb === null ? null : (float) $r->base_wet_bulb,
                    'summit' => $r->summit_wet_bulb === null ? null : (float) $r->summit_wet_bulb,
                    'snow' => $r->snow_in === null ? null : (float) $r->snow_in,
                ])->all();
            $weeks = self::weekly($rows, $resort->time_zone ?: 'America/New_York');
            $max = fn ($key) => max([1] + array_column($weeks, $key));
            return [
                'weeks' => array_map(fn ($w) => $w + [
                    'smPct' => round($w['smHours'] / $max('smHours') * 100),
                    'snowPct' => round($w['snowIn'] / max(1.0, $max('snowIn')) * 100),
                ], $weeks),
                'totals' => [
                    'smHours' => array_sum(array_column($weeks, 'smHours')),
                    'greatHours' => array_sum(array_column($weeks, 'greatHours')),
                    'snowIn' => round(array_sum(array_column($weeks, 'snowIn')), 1),
                    'hoursLogged' => array_sum(array_column($weeks, 'hoursLogged')),
                ],
                'since' => self::LOG_STARTED,
            ];
        });
    }

    /**
     * Early-season snowmaking watch: every enabled resort with its
     * snowmaking hours so far this season (from the log) and forecast for
     * the next 7 days (from its stored outlook), most combined first.
     */
    public static function watch(?Carbon $now = null): array
    {
        $now = $now ?: Carbon::now('UTC');
        return Cache::remember('ctms:season:watch', self::CACHE_SECONDS, function () use ($now) {
            $logged = DB::table(self::TABLE)
                ->selectRaw('resort_id,
                    SUM(CASE WHEN LEAST(base_wet_bulb, COALESCE(summit_wet_bulb, base_wet_bulb)) < ? THEN 1 ELSE 0 END) AS sm_hours,
                    SUM(CASE WHEN LEAST(base_wet_bulb, COALESCE(summit_wet_bulb, base_wet_bulb)) < ? THEN 1 ELSE 0 END) AS great_hours,
                    COALESCE(SUM(snow_in), 0) AS snow_in', [WeatherService::SNOWMAKING_THRESHOLD_C, -4.0])
                ->whereBetween('hour', [self::seasonStart($now), $now])
                ->groupBy('resort_id')
                ->get()->keyBy('resort_id');
            $resorts = Resort::isEnabled()->get()->map(fn (Resort $r) => [
                'name' => $r->name,
                'state' => $r->state,
                'url' => $r->url,
                'soFar' => (int) ($logged[$r->id]->sm_hours ?? 0),
                'greatSoFar' => (int) ($logged[$r->id]->great_hours ?? 0),
                'snowSoFar' => round((float) ($logged[$r->id]->snow_in ?? 0), 1),
                'next7' => array_sum(array_column($r->last_daily ?: [], 'smHours')),
            ])->all();
            return self::rankWatch($resorts);
        });
    }

    /**
     * Most snowmaking hours so far plus forecast first, then so far, then by name.
     */
    public static function rankWatch(array $resorts): array
    {
        foreach ($resorts as &$r) {
            $r['total'] = $r['soFar'] + $r['next7'];
        }
        usort($resorts, fn ($a, $b) => [$b['total'], $b['soFar'], $a['name']] <=> [$a['total'], $a['soFar'], $b['name']]);
        return $resorts;
    }

    protected static function coldest(?float $base, ?float $summit): ?float
    {
        if ($base === null) {
            return $summit;
        }
        return $summit === null ? $base : min($base, $summit);
    }
}
