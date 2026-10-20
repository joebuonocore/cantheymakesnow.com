<?php namespace AlbrightLabs\CanTheyMakeSnow\Classes;

use Carbon\Carbon;

/**
 * When to email a subscriber. Pure: no framework dependencies.
 *
 * - One alert per snowmaking window and per storm: a window or storm that
 *   overlaps the last one alerted is the same event, even if the forecast
 *   nudged its times.
 * - At most one email per resort per alert type every MIN_SPACING_HOURS.
 * - A storm already alerted gets one follow-up only if its forecast grows
 *   a lot (GROWTH_FACTOR and GROWTH_MIN_IN).
 * - Nothing is sent during quiet hours in the resort's time zone; the next
 *   hourly run picks it up.
 */
class AlertRules
{
    const MIN_SPACING_HOURS = 20;
    const QUIET_START_HOUR = 22;
    const QUIET_END_HOUR = 6;
    const POWDER_LOOKAHEAD_HOURS = 48;
    const GROWTH_FACTOR = 1.5;
    const GROWTH_MIN_IN = 4.0;

    public function isQuietHours(Carbon $now, string $timeZone): bool
    {
        $hour = $now->copy()->setTimezone($timeZone)->hour;
        return $hour >= self::QUIET_START_HOUR || $hour < self::QUIET_END_HOUR;
    }

    /**
     * The snowmaking window to alert about, or null.
     *
     * @param array $windows WeatherService::snowmakingWindows() output
     * @param string $minTier 'marginal' (any window) or 'great' (window reaches great or ideal)
     */
    public function snowmakingAlert(array $windows, string $minTier, ?Carbon $lastStart, ?Carbon $lastEnd, ?Carbon $lastSentAt, Carbon $now): ?array
    {
        if (!$this->spaced($lastSentAt, $now)) {
            return null;
        }

        foreach ($windows as $window) {
            if ($window['end']->timestamp <= $now->timestamp) {
                continue;
            }
            if ($minTier === 'great' && $window['coldestWetBulbC'] >= -4.0) {
                continue;
            }
            if ($this->overlaps($window['start'], $window['end'], $lastStart, $lastEnd)) {
                continue;
            }
            return $window;
        }
        return null;
    }

    /**
     * The storm to alert about, or null. Returns ['storm' => array,
     * 'update' => bool, 'previousIn' => ?float]; 'update' marks a follow-up
     * for a storm already alerted whose forecast has grown.
     *
     * @param array $storms WeatherService::storms() output
     */
    public function powderAlert(array $storms, int $thresholdIn, ?Carbon $lastStart, ?Carbon $lastEnd, ?float $lastIn, ?Carbon $lastSentAt, Carbon $now): ?array
    {
        if (!$this->spaced($lastSentAt, $now)) {
            return null;
        }

        $best = null;
        foreach ($storms as $storm) {
            $startsSoon = $storm['start']->timestamp - $now->timestamp < self::POWDER_LOOKAHEAD_HOURS * 3600;
            $notOver = $storm['end']->timestamp > $now->timestamp;
            if ($startsSoon && $notOver && round($storm['totalIn']) >= $thresholdIn && (!$best || $storm['totalIn'] > $best['totalIn'])) {
                $best = $storm;
            }
        }
        if (!$best) {
            return null;
        }

        if (!$this->overlaps($best['start'], $best['end'], $lastStart, $lastEnd)) {
            return ['storm' => $best, 'update' => false, 'previousIn' => null];
        }

        $grown = $lastIn !== null
            && $best['totalIn'] >= $lastIn * self::GROWTH_FACTOR
            && $best['totalIn'] - $lastIn >= self::GROWTH_MIN_IN;
        return $grown ? ['storm' => $best, 'update' => true, 'previousIn' => $lastIn] : null;
    }

    protected function spaced(?Carbon $lastSentAt, Carbon $now): bool
    {
        return !$lastSentAt || $now->timestamp - $lastSentAt->timestamp >= self::MIN_SPACING_HOURS * 3600;
    }

    protected function overlaps(Carbon $start, Carbon $end, ?Carbon $lastStart, ?Carbon $lastEnd): bool
    {
        if (!$lastStart || !$lastEnd) {
            return false;
        }
        return $start->timestamp < $lastEnd->timestamp && $end->timestamp > $lastStart->timestamp;
    }
}
