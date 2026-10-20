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
            if (!$interval = $this->parseInterval($item['validTime'] ?? '')) {
                continue;
            }
            [$start, $end] = $interval;

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
     * Parse an ISO 8601 interval ("start/duration") into UTC [start, end].
     * A missing duration yields a zero-length interval; garbage yields null.
     */
    protected function parseInterval(string $validTime): ?array
    {
        [$startString, $durationString] = array_pad(explode('/', $validTime, 2), 2, null);

        try {
            $start = Carbon::parse($startString)->utc();
            $end = $durationString ? $start->copy()->add(new \DateInterval($durationString)) : $start->copy();
        } catch (\Exception $e) {
            return null;
        }

        return [$start, $end];
    }

    /**
     * Expand NOAA interval values into a map of hour-start timestamp => value.
     */
    protected function expandHourly(array $values): array
    {
        $hours = [];
        foreach ($values as $item) {
            if (!isset($item['value']) || !$interval = $this->parseInterval($item['validTime'] ?? '')) {
                continue;
            }
            [$start, $end] = $interval;
            $t = $start->copy()->startOfHour()->timestamp;
            do {
                $hours[$t] = (float) $item['value'];
                $t += 3600;
            } while ($t < $end->timestamp);
        }
        return $hours;
    }

    /**
     * Build an hourly forecast starting at the hour containing $from.
     * Each point: ['time' => Carbon (UTC), 'tempC', 'rh', 'wetBulbC', 'snow', 'tier'].
     * Hours missing either temperature or humidity are skipped.
     */
    public function hourlySeries(array $temperatures, array $humidities, ?string $temperatureUom, Carbon $from, int $hours = 48): array
    {
        $temps = $this->expandHourly($temperatures);
        $rhs = $this->expandHourly($humidities);
        $first = $from->copy()->utc()->startOfHour()->timestamp;

        $series = [];
        for ($i = 0; $i < $hours; $i++) {
            $t = $first + $i * 3600;
            if (!isset($temps[$t], $rhs[$t])) {
                continue;
            }
            $tempC = $this->toCelsius($temps[$t], $temperatureUom);
            $wetBulb = $this->calculateWetBulb($tempC, $rhs[$t]);
            $tier = $this->snowmakingTier($wetBulb);
            $series[] = [
                'time' => Carbon::createFromTimestampUTC($t),
                'tempC' => $tempC,
                'rh' => $rhs[$t],
                'wetBulbC' => $wetBulb,
                'snow' => $tier['snow'],
                'tier' => $tier['tier'],
            ];
        }
        return $series;
    }

    /**
     * Group consecutive snowmaking hours of an hourlySeries() into windows.
     * Each window: ['start' => Carbon, 'end' => Carbon, 'hours' => int, 'coldestWetBulbC' => float].
     */
    public function snowmakingWindows(array $series): array
    {
        $windows = [];
        $current = null;

        foreach ($series as $point) {
            $contiguous = $current && $point['time']->timestamp === $current['end']->timestamp;
            if (!$point['snow'] || ($current && !$contiguous)) {
                if ($current) {
                    $windows[] = $current;
                    $current = null;
                }
                if (!$point['snow']) {
                    continue;
                }
            }
            if (!$current) {
                $current = [
                    'start' => $point['time']->copy(),
                    'end' => $point['time']->copy(),
                    'hours' => 0,
                    'coldestWetBulbC' => $point['wetBulbC'],
                ];
            }
            $current['end'] = $point['time']->copy()->addHour();
            $current['hours']++;
            $current['coldestWetBulbC'] = min($current['coldestWetBulbC'], $point['wetBulbC']);
        }

        if ($current) {
            $windows[] = $current;
        }
        return $windows;
    }

    /**
     * Describe the next snowmaking window in plain words, in the location's
     * time zone. Returns ['state' => 'now'|'upcoming'|'none', 'text' => string].
     * $seriesEnd is when the forecast stops, so an open-ended window can say so.
     */
    public function describeWindows(array $windows, Carbon $now, string $timeZone, ?Carbon $seriesEnd = null): array
    {
        $next = null;
        foreach ($windows as $window) {
            if ($window['end']->timestamp > $now->timestamp) {
                $next = $window;
                break;
            }
        }

        if (!$next) {
            return ['state' => 'none', 'text' => 'No snowmaking window in the next 48 hours.'];
        }

        $localNow = $now->copy()->setTimezone($timeZone);
        $start = $next['start']->copy()->setTimezone($timeZone);
        $end = $next['end']->copy()->setTimezone($timeZone);
        $openEnded = $seriesEnd && $next['end']->timestamp >= $seriesEnd->timestamp;

        if ($next['start']->timestamp <= $now->timestamp) {
            $text = $openEnded
                ? 'Snowmaking conditions now, lasting through the 48-hour forecast.'
                : 'Snowmaking conditions now, until ' . $this->relativeTime($end, $localNow) . '.';
            return ['state' => 'now', 'text' => $text];
        }

        $text = 'Next snowmaking window: ' . $this->relativeTime($start, $localNow)
            . ($openEnded ? ' onward' : ' – ' . $this->relativeTime($end, $start, true));
        return ['state' => 'upcoming', 'text' => $text . '.'];
    }

    /**
     * "9 PM", "tonight 9 PM", "tomorrow 6 AM", "Wed 3 PM" relative to $reference.
     * With $compact, the day is omitted when it is the same day or the next morning.
     */
    protected function relativeTime(Carbon $time, Carbon $reference, bool $compact = false): string
    {
        $clock = $time->format('g A');
        $days = $reference->copy()->startOfDay()->diffInDays($time->copy()->startOfDay(), false);

        if ($compact && ($days === 0 || ($days === 1 && $time->hour < 12))) {
            return $clock;
        }
        if ($days === 0) {
            return ($time->hour >= 17 ? 'tonight ' : 'today ') . $clock;
        }
        if ($days === 1) {
            return ($time->hour >= 17 ? 'tomorrow night ' : 'tomorrow ') . $clock;
        }
        return $time->format('D ') . $clock;
    }

    /**
     * Parse a NOAA /stations/{id}/observations/latest response.
     * Humidity is often null; derive it from dewpoint when possible.
     * Returns ['time' => Carbon, 'tempC', 'rh', 'wetBulbC'] or null.
     */
    public function parseObservation(?array $response): ?array
    {
        $p = $response['properties'] ?? null;
        if (!$p || !isset($p['timestamp'], $p['temperature']['value'])) {
            return null;
        }

        $tempC = $this->toCelsius((float) $p['temperature']['value'], $p['temperature']['unitCode'] ?? null);
        $rh = $p['relativeHumidity']['value'] ?? null;

        if ($rh === null && isset($p['dewpoint']['value'])) {
            $dewC = $this->toCelsius((float) $p['dewpoint']['value'], $p['dewpoint']['unitCode'] ?? null);
            $rh = $this->relativeHumidityFromDewpoint($tempC, $dewC);
        }
        if ($rh === null) {
            return null;
        }

        return [
            'time' => Carbon::parse($p['timestamp'])->utc(),
            'tempC' => $tempC,
            'rh' => (float) $rh,
            'wetBulbC' => $this->calculateWetBulb($tempC, (float) $rh),
        ];
    }

    /**
     * Magnus approximation (Alduchov & Eskridge coefficients).
     */
    public function relativeHumidityFromDewpoint(float $tempC, float $dewpointC): float
    {
        $rh = 100 * exp((17.625 * $dewpointC) / (243.04 + $dewpointC))
            / exp((17.625 * $tempC) / (243.04 + $tempC));
        return max(0.0, min(100.0, $rh));
    }

    /**
     * NOAA forecasts cover the US and its territories only.
     */
    public function isNoaaCountry(?string $countryCode): bool
    {
        return in_array(strtoupper((string) $countryCode), ['US', 'PR', 'GU', 'VI', 'AS', 'MP'], true);
    }

    public function toFahrenheit(float $celsius): float
    {
        return $celsius * 9 / 5 + 32;
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
