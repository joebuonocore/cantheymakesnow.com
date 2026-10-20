<?php namespace AlbrightLabs\CanTheyMakeSnow\Classes;

use Carbon\Carbon;

/**
 * Pure domain logic for the CanTheyMakeSnow calculator.
 * No framework dependencies — safe to unit test in isolation.
 */
class WeatherService
{
    /**
     * Wet-bulb temperature (°C) at which snowmakers can practically start.
     * The textbook answer is 0°C, but industry practice is around -2°C —
     * at warmer wet-bulbs droplets don't reliably freeze before hitting the ground.
     */
    const SNOWMAKING_THRESHOLD_C = -2.0;

    /**
     * Classify a wet-bulb temperature into a snowmaking quality tier.
     * Returns ['snow' => bool, 'tier' => string, 'label' => string].
     */
    public function snowmakingTier(?float $wetBulbC): array
    {
        if ($wetBulbC === null) {
            return ['snow' => false, 'tier' => 'unknown', 'label' => 'Unknown'];
        }
        if ($wetBulbC < -7.0) {
            return ['snow' => true, 'tier' => 'ideal', 'label' => 'Ideal snowmaking conditions'];
        }
        if ($wetBulbC < -4.0) {
            return ['snow' => true, 'tier' => 'great', 'label' => 'Great snowmaking conditions'];
        }
        if ($wetBulbC < self::SNOWMAKING_THRESHOLD_C) {
            return ['snow' => true, 'tier' => 'marginal', 'label' => 'Marginal — snowmakers can just barely run'];
        }
        if ($wetBulbC < 0.0) {
            return ['snow' => false, 'tier' => 'too_warm', 'label' => 'Too warm — below freezing but droplets won\'t freeze in flight'];
        }
        return ['snow' => false, 'tier' => 'no_chance', 'label' => 'Too warm for snowmaking'];
    }


    /**
     * Stull (2011) wet-bulb approximation.
     * Inputs: dry-bulb temperature in Celsius, relative humidity as a
     * percentage in [0, 100]. Output: wet-bulb temperature in Celsius.
     *
     * Accepts fractional RH (<= 1.0) for convenience and scales it up.
     */
    public function calculateWetBulb(float $dryBulbC, float $relativeHumidityPct): float
    {
        if ($relativeHumidityPct <= 1.0) {
            $relativeHumidityPct *= 100.0;
        }

        return $dryBulbC * atan(0.151977 * sqrt($relativeHumidityPct + 8.313659))
            + atan($dryBulbC + $relativeHumidityPct)
            - atan($relativeHumidityPct - 1.676331)
            + 0.00391838 * pow($relativeHumidityPct, 3 / 2) * atan(0.023101 * $relativeHumidityPct)
            - 4.686035;
    }

    /**
     * Given a NOAA `values` array (each item has an ISO 8601 interval
     * `validTime` like "2024-01-01T00:00:00+00:00/PT3H" or ".../P1DT6H"),
     * return the entry whose window contains $currentTime. If none does,
     * fall back to the window nearest to it.
     */
    public function findClosestTime(array $values, Carbon $currentTime): array
    {
        $closestTime = null;
        $minDifference = PHP_INT_MAX;
        $valueAtClosestTime = null;
        $now = $currentTime->timestamp;

        foreach ($values as $item) {
            [$startString, $durationString] = array_pad(explode('/', $item['validTime'], 2), 2, null);

            try {
                $start = Carbon::parse($startString)->utc();
                $end = $durationString ? $start->copy()->add(new \DateInterval($durationString)) : $start->copy();
            } catch (\Exception $e) {
                continue;
            }

            if ($now >= $start->timestamp && $now < $end->timestamp) {
                return ['time' => $start, 'value' => $item['value']];
            }

            $difference = $now < $start->timestamp
                ? $start->timestamp - $now
                : $now - $end->timestamp;

            if ($difference < $minDifference) {
                $closestTime = $start;
                $minDifference = $difference;
                $valueAtClosestTime = $item['value'];
            }
        }

        return ['time' => $closestTime, 'value' => $valueAtClosestTime];
    }

    /**
     * NOAA's /points endpoint rejects coordinates whose hundredths digit
     * is zero (e.g. 40.10,-74.00 → 301 to a normalized form). We nudge
     * such values by 0.01 in the away-from-zero direction to keep a
     * single canonical precision and avoid a second round-trip.
     */
    public function customRound(float $number): float
    {
        $rounded = round($number, 2);
        $roundedString = number_format($rounded, 2, '.', '');

        if (substr($roundedString, -1) === '0') {
            $adjusted = $rounded > 0 ? $rounded + 0.01 : $rounded - 0.01;
            return round($adjusted, 2);
        }

        return $rounded;
    }

    /**
     * Convert a NOAA-reported temperature to Celsius based on its unit code.
     * NOAA uom values look like "wmoUnit:degC" or "wmoUnit:degF".
     */
    public function toCelsius(float $value, ?string $uom): float
    {
        if ($uom && str_contains(strtolower($uom), 'degf')) {
            return ($value - 32) * 5 / 9;
        }
        return $value;
    }
}
