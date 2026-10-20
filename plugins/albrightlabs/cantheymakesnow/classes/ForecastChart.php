<?php namespace AlbrightLabs\CanTheyMakeSnow\Classes;

use Carbon\Carbon;

/**
 * Geometry for the 48-hour wet-bulb column chart, rendered as inline SVG by
 * the theme. Columns grow from the snowmaking line (WeatherService::
 * SNOWMAKING_THRESHOLD_C): down for hours cold enough to make snow, up for
 * hours too warm. Pure: no framework dependencies.
 */
class ForecastChart
{
    const WIDTH = 480;
    const HEIGHT = 150;
    const PLOT_TOP = 18;
    const PLOT_BOTTOM = 126;
    const BAR_GAP = 2;
    const BAR_RADIUS = 2;
    const MIN_BAR = 2;

    /**
     * @param array $series WeatherService::hourlySeries() output
     */
    public function build(array $series, string $timeZone, float $lat, float $lon): array
    {
        $count = count($series);
        if (!$count) {
            return ['bars' => []];
        }

        $threshold = WeatherService::SNOWMAKING_THRESHOLD_C;
        $values = array_column($series, 'wetBulbC');
        $min = min(min($values), $threshold) - 1;
        $max = max(max($values), $threshold) + 1;
        $scale = (self::PLOT_BOTTOM - self::PLOT_TOP) / ($max - $min);
        $y = fn (float $v) => self::PLOT_TOP + ($max - $v) * $scale;

        $slot = self::WIDTH / $count;
        $barWidth = min(24, $slot - self::BAR_GAP);
        $baseline = $y($threshold);

        $bars = [];
        $nights = [];
        $days = [];
        $coldest = 0;
        $sunCache = [];

        foreach ($series as $i => $point) {
            $local = $point['time']->copy()->setTimezone($timeZone);
            $x = $i * $slot + self::BAR_GAP / 2;
            $end = $y($point['wetBulbC']);
            if (abs($end - $baseline) < self::MIN_BAR) {
                $end = $point['snow'] ? $baseline + self::MIN_BAR : $baseline - self::MIN_BAR;
            }

            $bars[] = [
                'x' => round($i * $slot, 2),
                'slot' => round($slot, 2),
                'path' => $this->columnPath($x, $barWidth, $baseline, $end),
                'tier' => $point['tier'],
                'wetBulbC' => $point['wetBulbC'],
                'label' => $local->format('D g A'),
            ];

            if ($point['wetBulbC'] < $series[$coldest]['wetBulbC']) {
                $coldest = $i;
            }

            if ($this->isNight($point['time'], $local, $lat, $lon, $sunCache)) {
                $last = count($nights) - 1;
                if ($last >= 0 && $nights[$last]['endIndex'] === $i - 1) {
                    $nights[$last]['endIndex'] = $i;
                    $nights[$last]['width'] = round(($i + 1) * $slot - $nights[$last]['x'], 2);
                } else {
                    $nights[] = ['x' => round($i * $slot, 2), 'width' => round($slot, 2), 'endIndex' => $i];
                }
            }

            // Skip a day label that would collide with "Now" at the left edge.
            if ($local->hour === 0 && $i * $slot >= 32) {
                $days[] = ['x' => round($i * $slot, 2), 'label' => $local->format('D')];
            }
        }

        return [
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'plotTop' => self::PLOT_TOP,
            'plotBottom' => self::PLOT_BOTTOM,
            'baseline' => round($baseline, 2),
            'bars' => $bars,
            'nights' => $nights,
            'days' => $days,
            'coldest' => [
                'x' => round($coldest * $slot + $slot / 2, 2),
                'y' => round($y($series[$coldest]['wetBulbC']), 2),
                'wetBulbC' => $series[$coldest]['wetBulbC'],
                'snow' => $series[$coldest]['snow'],
                'index' => $coldest,
            ],
        ];
    }

    /**
     * Column from the baseline to $end with a rounded data end and a square base.
     */
    protected function columnPath(float $x, float $w, float $baseline, float $end): string
    {
        $r = min(self::BAR_RADIUS, $w / 2, abs($end - $baseline));
        $dir = $end < $baseline ? 1 : -1; // +1: data end is above the baseline
        $f = fn ($n) => round($n, 2);

        return sprintf(
            'M%s %sL%s %sQ%s %s %s %sL%s %sQ%s %s %s %sL%s %sZ',
            $f($x), $f($baseline),
            $f($x), $f($end + $dir * $r),
            $f($x), $f($end), $f($x + $r), $f($end),
            $f($x + $w - $r), $f($end),
            $f($x + $w), $f($end), $f($x + $w), $f($end + $dir * $r),
            $f($x + $w), $f($baseline)
        );
    }

    protected function isNight(Carbon $utc, Carbon $local, float $lat, float $lon, array &$cache): bool
    {
        $key = $local->format('Y-m-d');
        if (!isset($cache[$key])) {
            $noon = $local->copy()->setTime(12, 0)->timestamp;
            $cache[$key] = date_sun_info($noon, $lat, $lon);
        }
        $sun = $cache[$key];

        // Polar day/night: date_sun_info returns true/false instead of timestamps.
        if (!is_int($sun['sunrise']) || !is_int($sun['sunset'])) {
            return $sun['sunrise'] === false;
        }

        $mid = $utc->timestamp + 1800;
        return $mid < $sun['sunrise'] || $mid >= $sun['sunset'];
    }
}
