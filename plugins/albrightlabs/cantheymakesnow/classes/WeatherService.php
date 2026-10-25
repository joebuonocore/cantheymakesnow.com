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

    const MM_PER_INCH = 25.4;

    const TEMP_LAPSE_C_PER_KM = 6.5;
    const DEWPOINT_LAPSE_C_PER_KM = 1.8;

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
    public function relativeTime(Carbon $time, Carbon $reference, bool $compact = false): string
    {
        $clock = $time->format('g A');
        // Carbon 3 returns a float here; compare whole calendar days.
        $days = (int) round($reference->copy()->startOfDay()->diffInDays($time->copy()->startOfDay(), false));

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
     * Spread NOAA snowfall intervals (any duration) evenly over their hours,
     * then sum into fixed buckets starting at the hour containing $from.
     * Each bucket: ['start' => Carbon, 'end' => Carbon, 'mm' => float, 'in' => float].
     * Buckets stop where the forecast data stops.
     */
    public function snowfallBuckets(array $values, ?string $uom, Carbon $from, int $hours = 72, int $bucketHours = 6): array
    {
        $scale = $uom && preg_match('/:m$/', $uom) ? 1000 : 1; // metres → mm
        $hourly = [];
        foreach ($values as $item) {
            if (!isset($item['value']) || !$interval = $this->parseInterval($item['validTime'] ?? '')) {
                continue;
            }
            [$start, $end] = $interval;
            $count = max(1, (int) round(($end->timestamp - $start->timestamp) / 3600));
            $perHour = (float) $item['value'] * $scale / $count;
            $t = $start->copy()->startOfHour()->timestamp;
            for ($i = 0; $i < $count; $i++, $t += 3600) {
                $hourly[$t] = $perHour;
            }
        }

        $first = $from->copy()->utc()->startOfHour()->timestamp;
        $buckets = [];
        for ($b = 0; $b < intdiv($hours, $bucketHours); $b++) {
            $start = $first + $b * $bucketHours * 3600;
            $mm = 0.0;
            $covered = false;
            for ($h = 0; $h < $bucketHours; $h++) {
                if (isset($hourly[$start + $h * 3600])) {
                    $mm += $hourly[$start + $h * 3600];
                    $covered = true;
                }
            }
            if (!$covered) {
                break;
            }
            $buckets[] = [
                'start' => Carbon::createFromTimestampUTC($start),
                'end' => Carbon::createFromTimestampUTC($start + $bucketHours * 3600),
                'mm' => $mm,
                'in' => $mm / self::MM_PER_INCH,
            ];
        }
        return $buckets;
    }

    /**
     * Forecast snowfall totals in inches for the next 24, 48 and 72 hours.
     * A total is null when the forecast doesn't reach that far.
     */
    public function snowTotals(array $buckets): array
    {
        $totals = [];
        foreach ([24, 48, 72] as $hours) {
            $sum = 0.0;
            $covered = 0;
            foreach ($buckets as $bucket) {
                if ($bucket['end']->timestamp - $buckets[0]['start']->timestamp > $hours * 3600) {
                    break;
                }
                $sum += $bucket['in'];
                $covered = $bucket['end']->timestamp - $buckets[0]['start']->timestamp;
            }
            $totals[$hours] = $covered >= $hours * 3600 ? $sum : null;
        }
        return $totals;
    }

    /**
     * Group consecutive snowy buckets (≥ 0.1″) into storms.
     * Each storm: ['start' => Carbon, 'end' => Carbon, 'totalIn' => float, 'peakIn' => float].
     */
    public function storms(array $buckets, float $minIn = 0.1): array
    {
        $storms = [];
        $current = null;
        foreach ($buckets as $bucket) {
            if ($bucket['in'] < $minIn) {
                if ($current) {
                    $storms[] = $current;
                    $current = null;
                }
                continue;
            }
            if (!$current) {
                $current = ['start' => $bucket['start']->copy(), 'end' => $bucket['end']->copy(), 'totalIn' => 0.0, 'peakIn' => 0.0];
            }
            $current['end'] = $bucket['end']->copy();
            $current['totalIn'] += $bucket['in'];
            $current['peakIn'] = max($current['peakIn'], $bucket['in']);
        }
        if ($current) {
            $storms[] = $current;
        }
        return $storms;
    }

    /**
     * The biggest storm starting within $withinHours of $now, or null.
     */
    public function biggestStorm(array $storms, Carbon $now, int $withinHours = 48): ?array
    {
        $best = null;
        foreach ($storms as $storm) {
            if ($storm['start']->timestamp - $now->timestamp >= $withinHours * 3600) {
                continue;
            }
            if (!$best || $storm['totalIn'] > $best['totalIn']) {
                $best = $storm;
            }
        }
        return $best;
    }

    /**
     * Weather at a given hour from a NOAA gridpoints `properties` array.
     * Returns sky/weather description, an icon key, wind and gust (mph),
     * feels-like (°C) and precipitation chance; missing fields are null.
     */
    public function conditionsAt(array $grid, Carbon $at): array
    {
        $value = function (string $field) use ($grid, $at) {
            $values = $grid[$field]['values'] ?? [];
            return $values ? $this->findClosestTime($values, $at)['value'] : null;
        };
        $kmh = fn ($v) => $v === null ? null : $this->kmhToMph((float) $v);

        $sky = $value('skyCover');
        $weather = $value('weather');
        $description = $this->describeWeather(is_array($weather) ? $weather : []);
        $feelsLikeField = $value('windChill') !== null ? 'windChill' : 'apparentTemperature';
        $feelsLike = $value($feelsLikeField);

        return [
            'summary' => $description ?? $this->skyLabel($sky),
            'icon' => $this->weatherIcon(is_array($weather) ? $weather : [], $sky),
            'skyCover' => $sky,
            'windMph' => $kmh($value('windSpeed')),
            'gustMph' => $kmh($value('windGust')),
            'feelsLikeC' => $feelsLike === null ? null : $this->toCelsius((float) $feelsLike, $grid[$feelsLikeField]['uom'] ?? null),
            'precipChance' => $value('probabilityOfPrecipitation'),
        ];
    }

    public function skyLabel($cover): ?string
    {
        if ($cover === null) {
            return null;
        }
        return match (true) {
            $cover <= 10 => 'Clear',
            $cover <= 30 => 'Mostly clear',
            $cover <= 60 => 'Partly cloudy',
            $cover <= 85 => 'Mostly cloudy',
            default => 'Cloudy',
        };
    }

    /**
     * Plain-words NOAA `weather` value: "Light snow likely", "Chance of rain and snow".
     * Returns null when no weather is expected.
     */
    public function describeWeather(array $items): ?string
    {
        $items = array_values(array_filter($items, fn ($i) => !empty($i['weather'])));
        if (!$items) {
            return null;
        }

        $coverage = $items[0]['coverage'] ?? null;
        $types = [];
        foreach ($items as $item) {
            if (($item['coverage'] ?? null) === $coverage) {
                $types[] = str_replace('_', ' ', $item['weather']);
            }
        }
        $what = implode(' and ', array_unique($types));

        $intensity = $items[0]['intensity'] ?? null;
        if ($intensity && !in_array($intensity, ['moderate'], true)) {
            $what = str_replace('_', ' ', $intensity) . ' ' . $what;
        }

        $text = match ($coverage) {
            'slight_chance' => 'slight chance of ' . $what,
            'chance' => 'chance of ' . $what,
            'likely' => $what . ' likely',
            null, 'definite' => $what,
            default => str_replace('_', ' ', $coverage) . ' ' . $what,
        };
        return ucfirst($text);
    }

    /**
     * Icon key for the theme: sun, partly, cloud, snow, rain, mix, storm, fog.
     */
    public function weatherIcon(array $items, $skyCover): string
    {
        $types = array_filter(array_column($items, 'weather'));
        $has = fn (array $needles) => (bool) array_filter($types, fn ($t) => (bool) array_filter($needles, fn ($n) => str_contains($t, $n)));

        if ($has(['thunder'])) {
            return 'storm';
        }
        if ($has(['freezing', 'sleet', 'ice']) || ($has(['snow']) && $has(['rain', 'drizzle']))) {
            return 'mix';
        }
        if ($has(['snow'])) {
            return 'snow';
        }
        if ($has(['rain', 'drizzle'])) {
            return 'rain';
        }
        if ($has(['fog', 'haze', 'smoke'])) {
            return 'fog';
        }
        return match (true) {
            $skyCover === null, $skyCover <= 30 => 'sun',
            $skyCover <= 60 => 'partly',
            default => 'cloud',
        };
    }

    /**
     * Turn a NOAA /forecast response into day cards: a daytime period plus the
     * night after it (or a lone "Tonight"). Each day: ['name', 'highC', 'lowC',
     * 'icon', 'short', 'snow' => ['lo','hi'] in inches or null].
     */
    public function parseOutlook(?array $response): array
    {
        $days = [];
        foreach ($response['properties']['periods'] ?? [] as $period) {
            $tempC = isset($period['temperature'])
                ? $this->toCelsius((float) $period['temperature'], ($period['temperatureUnit'] ?? 'F') === 'F' ? 'degF' : 'degC')
                : null;
            $snow = $this->parseSnowAccumulation($period['detailedForecast'] ?? '');

            $last = count($days) - 1;
            if (empty($period['isDaytime']) && $last >= 0 && $days[$last]['lowC'] === null && $days[$last]['highC'] !== null) {
                $days[$last]['lowC'] = $tempC;
                $days[$last]['snow'] = $this->addSnow($days[$last]['snow'], $snow);
                continue;
            }

            $days[] = [
                'name' => $this->shortDayName($period['name'] ?? ''),
                'highC' => !empty($period['isDaytime']) ? $tempC : null,
                'lowC' => empty($period['isDaytime']) ? $tempC : null,
                'icon' => $this->iconFromText($period['shortForecast'] ?? '') ?? $this->iconFromUrl($period['icon'] ?? ''),
                'short' => $period['shortForecast'] ?? '',
                'snow' => $snow,
            ];
        }
        return $days;
    }

    /**
     * "New snow accumulation of 4 to 8 inches possible" → ['lo' => 4, 'hi' => 8].
     * Returns null when no accumulation is mentioned or none is expected.
     */
    public function parseSnowAccumulation(string $text): ?array
    {
        if (!preg_match('/new snow accumulation of ([^.]*?) (?:possible|expected)/i', $text, $m)) {
            return null;
        }
        $phrase = strtolower($m[1]);
        $words = ['half an' => 0.5, 'an' => 1, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10];
        $number = fn (string $n) => is_numeric($n) ? (float) $n : (isset($words[$n]) ? (float) $words[$n] : null);

        if (preg_match('/^less than (half an|an|one|\d+) inch/', $phrase, $n)) {
            return ['lo' => 0.0, 'hi' => $number($n[1])];
        }
        if (preg_match('/^(\d+|[a-z]+) to (\d+|[a-z]+) inch/', $phrase, $n) && $number($n[1]) !== null && $number($n[2]) !== null) {
            return ['lo' => $number($n[1]), 'hi' => $number($n[2])];
        }
        if (preg_match('/^(?:around |up to |near )?(\d+|[a-z]+) inch/', $phrase, $n) && $number($n[1]) !== null) {
            return ['lo' => $number($n[1]), 'hi' => $number($n[1])];
        }
        return null;
    }

    protected function addSnow(?array $a, ?array $b): ?array
    {
        if (!$a || !$b) {
            return $a ?: $b;
        }
        return ['lo' => $a['lo'] + $b['lo'], 'hi' => $a['hi'] + $b['hi']];
    }

    protected function shortDayName(string $name): string
    {
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
            if (str_starts_with($name, $day)) {
                return substr($day, 0, 3);
            }
        }
        return $name; // "Today", "Tonight", "This Afternoon", holidays
    }

    /**
     * Icon key from a short forecast ("Rain And Snow Likely" → mix). The
     * NOAA icon URL shows a plain snow icon for mixed precipitation, so the
     * text wins when it names precipitation. Null when it names none.
     */
    protected function iconFromText(string $short): ?string
    {
        $text = strtolower($short);
        $has = fn (string ...$words) => (bool) array_filter($words, fn ($w) => str_contains($text, $w));

        return match (true) {
            $has('thunder') => 'storm',
            $has('snow', 'flurries') && $has('rain', 'sleet', 'freezing', 'drizzle'), $has('sleet', 'freezing') => 'mix',
            $has('snow', 'flurries', 'blizzard') => 'snow',
            $has('rain', 'showers', 'drizzle') => 'rain',
            $has('fog', 'haze', 'smoke') => 'fog',
            default => null,
        };
    }

    /**
     * NOAA icon URLs look like /icons/land/day/snow,70/sct?size=medium.
     */
    protected function iconFromUrl(string $url): string
    {
        if (!preg_match('#/icons/land/(?:day|night)/([a-z_]+)#', $url, $m)) {
            return 'cloud';
        }
        $code = $m[1];
        return match (true) {
            str_starts_with($code, 'tsra') => 'storm',
            in_array($code, ['ra_sn', 'rain_snow', 'fzra', 'rain_fzra', 'snow_fzra', 'sleet', 'rain_sleet', 'snow_sleet'], true) => 'mix',
            in_array($code, ['sn', 'snow', 'blizzard'], true) => 'snow',
            str_starts_with($code, 'rain') || $code === 'ra' => 'rain',
            in_array($code, ['fog', 'haze', 'smoke', 'dust'], true) => 'fog',
            in_array($code, ['skc', 'few', 'hot', 'cold', 'wind_skc', 'wind_few'], true) => 'sun',
            in_array($code, ['sct', 'wind_sct'], true) => 'partly',
            default => 'cloud',
        };
    }

    public function kmhToMph(float $kmh): float
    {
        return $kmh * 0.621371;
    }

    /**
     * Parse a NOAA /stations/{id}/observations/latest response.
     * Humidity is often null; derive it from dewpoint when possible.
     * Returns ['time' => Carbon, 'tempC', 'rh', 'wetBulbC', 'text', 'windMph', 'gustMph'] or null.
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

        $speed = fn (string $field) => isset($p[$field]['value']) ? $this->kmhToMph((float) $p[$field]['value']) : null;

        return [
            'time' => Carbon::parse($p['timestamp'])->utc(),
            'tempC' => $tempC,
            'rh' => (float) $rh,
            'wetBulbC' => $this->calculateWetBulb($tempC, (float) $rh),
            'text' => ($p['textDescription'] ?? '') ?: null,
            'windMph' => $speed('windSpeed'),
            'gustMph' => $speed('windGust'),
        ];
    }

    /**
     * Inverse of relativeHumidityFromDewpoint(): dewpoint (°C) from air temperature and RH (%).
     */
    public function dewpointFromRh(float $tempC, float $rh): float
    {
        $gamma = log(max(0.01, min(100.0, $rh)) / 100) + (17.625 * $tempC) / (243.04 + $tempC);
        return 243.04 * $gamma / (17.625 - $gamma);
    }

    /**
     * Estimate an hourlySeries() at a different elevation with standard lapse
     * rates: air cools ~6.5 °C/km and dewpoint ~1.8 °C/km going up. Only a
     * fallback when NOAA has no separate forecast for that elevation: it
     * can't know about cold-air inversions. Each point gains 'estimated'.
     */
    public function adjustForElevation(array $series, float $deltaM): array
    {
        $km = $deltaM / 1000;
        return array_map(function (array $point) use ($km) {
            $tempC = $point['tempC'] - self::TEMP_LAPSE_C_PER_KM * $km;
            $dewC = min($tempC, $this->dewpointFromRh($point['tempC'], $point['rh']) - self::DEWPOINT_LAPSE_C_PER_KM * $km);
            $rh = $this->relativeHumidityFromDewpoint($tempC, $dewC);
            $wetBulb = $this->calculateWetBulb($tempC, $rh);
            $tier = $this->snowmakingTier($wetBulb);
            return ['tempC' => $tempC, 'rh' => $rh, 'wetBulbC' => $wetBulb, 'snow' => $tier['snow'], 'tier' => $tier['tier'], 'estimated' => true] + $point;
        }, $series);
    }

    /**
     * The overall answer across zones (e.g. base and summit): YES when any
     * zone can make snow; tier and wet-bulb of the coldest zone; and which
     * zones can. Each zone needs ['now' => ['wetBulbC' => float]].
     */
    public function combineZones(array $zones): array
    {
        $coldest = null;
        $where = [];
        foreach ($zones as $key => $zone) {
            $wetBulb = $zone['now']['wetBulbC'];
            if ($this->snowmakingTier($wetBulb)['snow']) {
                $where[] = $key;
            }
            if ($coldest === null || $wetBulb < $zones[$coldest]['now']['wetBulbC']) {
                $coldest = $key;
            }
        }
        $tier = $this->snowmakingTier($zones[$coldest]['now']['wetBulbC']);
        return [
            'snow' => (bool) $where,
            'where' => $where,
            'zone' => $coldest,
            'wetBulbC' => $zones[$coldest]['now']['wetBulbC'],
            'tier' => $tier['tier'],
            'label' => $tier['label'],
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
