<?php namespace AlbrightLabs\CanTheyMakeSnow\Classes;

use Carbon\Carbon;
use AlbrightLabs\CanTheyMakeSnow\Models\Resort;

/**
 * The snow forecast page: resorts ranked by new snow expected at the base
 * over the next 24, 48 or 72 hours, from what the last lookup, alerts run
 * or ctms:refresh-resorts stored on each resort.
 */
class SnowBoard
{
    const WINDOWS = [24, 48, 72];
    const DEFAULT_HOURS = 48;

    /**
     * Resorts not checked within this many hours are left out rather than
     * ranked on an old forecast.
     */
    const FRESH_HOURS = 6;

    /**
     * Less than this is a trace, not snow worth listing.
     */
    const MIN_IN = 0.1;

    /**
     * Returns ['rows' => ranked rows (see row()), 'hours' => default window,
     * 'checked' => resorts with a fresh forecast, 'updatedAt' => ?Carbon (the
     * oldest of those forecasts) and 'updatedLabel' ("25 minutes ago")].
     */
    public static function build(?Carbon $now = null): array
    {
        $now = $now ?: Carbon::now('UTC');
        $resorts = Resort::isEnabled()
            ->where('last_checked_at', '>=', $now->copy()->subHours(self::FRESH_HOURS))
            ->get();

        $rows = $resorts->map(fn (Resort $resort) => self::row([
            'name' => $resort->name,
            'state' => $resort->state,
            'url' => $resort->url,
            'tier' => $resort->last_tier,
            'totals' => [24 => $resort->last_snow_24h_in, 48 => $resort->last_snow_48h_in, 72 => $resort->last_snow_72h_in],
            'stormStart' => $resort->last_storm_start,
            'stormEnd' => $resort->last_storm_end,
            'timeZone' => $resort->time_zone,
        ], $now))->all();

        $updatedAt = $resorts->min('last_checked_at');
        return [
            'rows' => self::rank($rows, self::DEFAULT_HOURS),
            'hours' => self::DEFAULT_HOURS,
            'checked' => $resorts->count(),
            'updatedAt' => $updatedAt,
            'updatedLabel' => $updatedAt ? $updatedAt->diffForHumans() : null,
        ];
    }

    /**
     * One resort as a row: totals as floats (null where NOAA's forecast
     * doesn't reach) and when its biggest storm falls, in local time.
     */
    public static function row(array $resort, Carbon $now): array
    {
        $totals = [];
        foreach (self::WINDOWS as $hours) {
            $value = $resort['totals'][$hours] ?? null;
            $totals[$hours] = $value === null ? null : (float) $value;
        }

        return [
            'name' => $resort['name'],
            'state' => $resort['state'],
            'url' => $resort['url'],
            'tier' => $resort['tier'] ?? null,
            'totals' => $totals,
            'when' => self::when($resort['stormStart'] ?? null, $resort['stormEnd'] ?? null, $resort['timeZone'] ?? null, $now),
        ];
    }

    /**
     * Rows with snow in any window, most snow in $hours first, then by name.
     * Rows with only a trace in $hours stay in the list (another window may
     * show them) and the page hides them.
     */
    public static function rank(array $rows, int $hours): array
    {
        $rows = array_values(array_filter($rows, fn ($row) => max(array_map('floatval', $row['totals'])) >= self::MIN_IN));
        usort($rows, fn ($a, $b) => [$b['totals'][$hours] ?? 0, $a['name']] <=> [$a['totals'][$hours] ?? 0, $b['name']]);
        return $rows;
    }

    /**
     * "Tonight 9 PM – 6 AM", "Wed 12 AM – 12 PM", "Now until tomorrow 3 PM",
     * or null when there's no storm still to come.
     */
    public static function when(?Carbon $start, ?Carbon $end, ?string $timeZone, Carbon $now): ?string
    {
        if (!$start || !$end || !$timeZone || $end->timestamp <= $now->timestamp) {
            return null;
        }
        $weather = new WeatherService();
        $local = $now->copy()->setTimezone($timeZone);
        $localStart = $start->copy()->setTimezone($timeZone);
        $localEnd = $end->copy()->setTimezone($timeZone);
        if ($start->timestamp <= $now->timestamp) {
            return 'Now until ' . $weather->relativeTime($localEnd, $local, true);
        }
        // Ending the same day it starts: the day is already in the start label.
        $endLabel = $localEnd->isSameDay($localStart) ? $localEnd->format('g A') : $weather->relativeTime($localEnd, $local, true);
        return ucfirst($weather->relativeTime($localStart, $local)) . ' – ' . $endLabel;
    }
}
