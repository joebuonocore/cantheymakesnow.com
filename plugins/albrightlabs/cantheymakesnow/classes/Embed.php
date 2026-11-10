<?php namespace AlbrightLabs\CanTheyMakeSnow\Classes;

use Carbon\Carbon;

/**
 * Data for the embeddable widget (/embed/{slug}.json, drawn by embed.js),
 * from what's stored on the resort: no NOAA calls. Text comes ready to show
 * in both unit systems so the widget doesn't repeat the formatting rules.
 */
class Embed
{
    /**
     * $resort: name, state, url, tier, wetBulbC, snow48In, smHours,
     * smNextStart (Carbon), timeZone and checkedAt (Carbon).
     */
    public static function data(array $resort, Carbon $now): array
    {
        $checkedAt = $resort['checkedAt'] ?? null;
        $fresh = $checkedAt && $now->timestamp - $checkedAt->timestamp <= SnowBoard::FRESH_HOURS * 3600;
        $wetBulb = $resort['wetBulbC'] ?? null;
        $snow = $resort['snow48In'] ?? null;
        $both = fn (callable $format) => ['f' => $format('f'), 'c' => $format('c')];
        $hours = $resort['smHours'] ?? null;

        return [
            'name' => $resort['name'],
            'state' => $resort['state'],
            'url' => $resort['url'],
            'fresh' => (bool) $fresh,
            'checkedAt' => $checkedAt ? $checkedAt->copy()->utc()->toIso8601String() : null,
            'answer' => in_array($resort['tier'] ?? null, ['ideal', 'great', 'marginal'], true) ? 'yes' : 'no',
            'tier' => $resort['tier'] ?? null,
            'wetBulb' => $wetBulb === null ? null : $both(fn ($u) => Units::temp((float) $wetBulb, $u)),
            'snow48' => $snow === null ? null : $both(fn ($u) => Units::snow((float) $snow, $u)),
            'snowmakingHours48' => $hours === null ? null : (int) $hours,
            'snowmakingStarts' => $hours ? SnowBoard::startLabel($resort['smNextStart'] ?? null, $resort['timeZone'] ?? null, $now) : null,
        ];
    }
}
