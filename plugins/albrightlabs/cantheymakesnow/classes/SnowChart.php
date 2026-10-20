<?php namespace AlbrightLabs\CanTheyMakeSnow\Classes;

/**
 * Geometry for the 72-hour snowfall chart: one column per 6-hour bucket,
 * growing up from zero. Shares bar drawing and night shading with
 * ForecastChart. Pure: no framework dependencies.
 */
class SnowChart extends ForecastChart
{
    const HEIGHT = 120;
    const PLOT_TOP = 16;
    const PLOT_BOTTOM = 96;
    const MIN_SCALE_IN = 2.0;

    /**
     * @param array $buckets WeatherService::snowfallBuckets() output
     */
    public function build(array $buckets, string $timeZone, float $lat, float $lon): array
    {
        $count = count($buckets);
        if (!$count) {
            return ['bars' => []];
        }

        $max = max(self::MIN_SCALE_IN, max(array_column($buckets, 'in')));
        $scale = (self::PLOT_BOTTOM - self::PLOT_TOP) / $max;
        $slot = self::WIDTH / $count;
        $barWidth = min(28, $slot - self::BAR_GAP);

        $bars = [];
        $nights = [];
        $days = [];
        $sunCache = [];
        $previousDay = null;

        foreach ($buckets as $i => $bucket) {
            $start = $bucket['start']->copy()->setTimezone($timeZone);
            $end = $bucket['end']->copy()->setTimezone($timeZone);
            $x = $i * $slot + ($slot - $barWidth) / 2;
            $height = $bucket['in'] > 0.05 ? max(self::MIN_BAR, $bucket['in'] * $scale) : 0;

            $bars[] = [
                'x' => round($i * $slot, 2),
                'slot' => round($slot, 2),
                'path' => $height ? $this->columnPath($x, $barWidth, self::PLOT_BOTTOM, self::PLOT_BOTTOM - $height) : null,
                'in' => $bucket['in'],
                'label' => $start->format('D g A') . ' – ' . $end->format('g A'),
            ];

            $mid = $bucket['start']->copy()->addHours(3);
            if ($this->isNight($mid, $mid->copy()->setTimezone($timeZone), $lat, $lon, $sunCache)) {
                $last = count($nights) - 1;
                if ($last >= 0 && $nights[$last]['endIndex'] === $i - 1) {
                    $nights[$last]['endIndex'] = $i;
                    $nights[$last]['width'] = round(($i + 1) * $slot - $nights[$last]['x'], 2);
                } else {
                    $nights[] = ['x' => round($i * $slot, 2), 'width' => round($slot, 2), 'endIndex' => $i];
                }
            }

            $day = $start->format('D');
            if ($day !== $previousDay) {
                $days[] = ['x' => round($i * $slot + 2, 2), 'label' => $i === 0 ? 'Now' : $day];
                $previousDay = $day;
            }
        }

        $peak = array_keys(array_column($buckets, 'in'), max(array_column($buckets, 'in')))[0];

        return [
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'plotTop' => self::PLOT_TOP,
            'plotBottom' => self::PLOT_BOTTOM,
            'maxIn' => $max,
            'peak' => [
                'x' => round($peak * $slot + $slot / 2, 2),
                'y' => round(self::PLOT_BOTTOM - $buckets[$peak]['in'] * $scale - 4, 2),
                'in' => $buckets[$peak]['in'],
            ],
            'bars' => $bars,
            'nights' => $nights,
            'days' => $days,
        ];
    }
}
