<?php namespace AlbrightLabs\CanTheyMakeSnow\Models;

use Carbon\Carbon;
use Model;

/**
 * ResortHour Model: the season's history, one row per resort per hour.
 *
 * Each time a resort's forecast is fetched (a lookup, the alerts run or
 * ctms:refresh-resorts), the current hour and the next few are written,
 * and later fetches overwrite them. So once an hour has passed, its row
 * holds the shortest-range forecast for it: close to what happened, but
 * still NOAA's forecast, not an observation.
 */
class ResortHour extends Model
{
    public $table = 'albrightlabs_cantheymakesnow_resort_hours';

    /**
     * Hours after the current one to write. Every resort is fetched at
     * least every couple of hours, so this overlaps the next fetch.
     */
    const LOG_AHEAD_HOURS = 3;

    public $dates = ['hour'];

    public $belongsTo = [
        'resort' => Resort::class,
    ];

    /**
     * Write (or overwrite) this result's hours for a resort.
     */
    public static function logFrom(int $resortId, array $result, ?Carbon $now = null): void
    {
        $rows = self::rowsFrom($resortId, $result, $now ?: Carbon::now('UTC'));
        if ($rows) {
            static::upsert($rows, ['resort_id', 'hour'], ['base_wet_bulb', 'summit_wet_bulb', 'snow_in']);
        }
    }

    /**
     * The rows logFrom() writes: the current hour and the next
     * LOG_AHEAD_HOURS, with base and summit wet-bulb (°C) and new snow
     * (inches). Hours missing from the forecast are skipped.
     */
    public static function rowsFrom(int $resortId, array $result, Carbon $now): array
    {
        $wetBulbs = function (?array $zone) {
            $byHour = [];
            foreach ($zone['hourly'] ?? [] as $point) {
                $byHour[$point['time']->timestamp] = $point['wetBulbC'];
            }
            return $byHour;
        };
        $base = $wetBulbs($result['zones']['base'] ?? ['hourly' => $result['hourly'] ?? []]);
        $summit = $wetBulbs($result['zones']['summit'] ?? null);
        $snow = $result['snow']['hourly'] ?? null;
        $round = fn ($value, int $places) => $value === null ? null : round((float) $value, $places);

        $rows = [];
        $first = $now->copy()->utc()->startOfHour()->timestamp;
        for ($i = 0; $i <= self::LOG_AHEAD_HOURS; $i++) {
            $t = $first + $i * 3600;
            if (!isset($base[$t])) {
                continue;
            }
            $rows[] = [
                'resort_id' => $resortId,
                'hour' => Carbon::createFromTimestampUTC($t)->toDateTimeString(),
                'base_wet_bulb' => $round($base[$t], 2),
                'summit_wet_bulb' => $round($summit[$t] ?? null, 2),
                'snow_in' => $snow === null ? null : $round($snow[$t] ?? 0.0, 2),
            ];
        }
        return $rows;
    }
}
