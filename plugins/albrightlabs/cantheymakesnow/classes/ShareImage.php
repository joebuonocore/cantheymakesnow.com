<?php namespace AlbrightLabs\CanTheyMakeSnow\Classes;

/**
 * Draws a 1200×630 social share card for a SnowReport result with GD.
 *
 * Needs a TrueType font: OG_FONT_PATH, or assets/fonts/share.ttf in this
 * plugin (Inter Bold, SIL Open Font License; see assets/fonts/OFL.txt). Without one (or without GD's FreeType support) available() is
 * false and pages simply omit og:image.
 */
class ShareImage
{
    const WIDTH = 1200;
    const HEIGHT = 630;
    const PAD = 64;

    // Dark-surface steps of the site's validated tier ramp (see custom.css).
    const COLORS = [
        'bg' => [22, 24, 27],
        'text' => [240, 239, 236],
        'muted' => [169, 168, 161],
        'yes' => [109, 167, 236],
        'line' => [195, 194, 183],
        'ideal' => [134, 182, 239],
        'great' => [57, 135, 229],
        'marginal' => [37, 106, 191],
        'too_warm' => [111, 110, 104],
        'no_chance' => [74, 73, 69],
    ];

    public static function fontPath(): ?string
    {
        $path = env('OG_FONT_PATH') ?: plugins_path('albrightlabs/cantheymakesnow/assets/fonts/share.ttf');
        return is_file($path) ? $path : null;
    }

    public static function available(): bool
    {
        return function_exists('imagettftext') && self::fontPath() !== null;
    }

    /**
     * Returns PNG bytes, or null when the image can't be drawn.
     */
    public function render(array $result): ?string
    {
        if (!self::available()) {
            return null;
        }

        [$img, $c, $font] = $this->canvas();
        $answer = $result['answer'];
        $snow = $answer['snow'];
        $zones = $result['zones'] ?? [];
        // The zone that's closest to snowmaking drives the window text and bars.
        $zone = count($zones) > 1 ? $zones[$answer['zone']] : $result;
        $contentWidth = self::WIDTH - self::PAD * 2;

        imagettftext($img, 128, 0, self::PAD - 5, 250, $snow ? $c['yes'] : $c['muted'], $font, $snow ? 'YES' : 'NO');

        $this->fitText($img, 46, self::PAD, 330, $contentWidth, $c['text'], $font, $result['place']);

        $temps = fn (float $wb) => sprintf('%s°F (%s°C)', $this->number($wb * 9 / 5 + 32), $this->number($wb));
        if (count($zones) > 1) {
            $parts = [];
            foreach ($zones as $z) {
                $ok = in_array($z['now']['tier'], ['ideal', 'great', 'marginal'], true);
                $parts[] = $z['label'] . ' ' . ($ok ? 'YES' : 'NO') . ' ' . $temps($z['now']['wetBulbC']);
            }
            $line = implode(' · ', $parts) . ' wet-bulb';
        } else {
            $line = $temps($answer['wetBulbC']) . ' wet-bulb · ' . $answer['label'];
        }
        $this->fitText($img, 26, self::PAD, 384, $contentWidth, $c['line'], $font, $line);
        $this->fitText($img, 26, self::PAD, 432, $contentWidth, $c['text'], $font, $zone['windows']['text']);

        $this->bars($img, $c, $zone['hourly'] ?? [], 470, 590);
        $chartLabel = count($zones) > 1 ? 'Next 48 hours at the ' . strtolower($zone['label']) . ' · NOAA forecast' : 'Next 48 hours · NOAA forecast';
        imagettftext($img, 16, 0, self::PAD, 616, $c['muted'], $font, $chartLabel);

        return $this->png($img);
    }

    /**
     * Site-wide card for links to the home page and other pages without a result.
     */
    public function renderDefault(): ?string
    {
        if (!self::available()) {
            return null;
        }

        [$img, $c, $font] = $this->canvas();
        $contentWidth = self::WIDTH - self::PAD * 2;

        $this->fitText($img, 76, self::PAD, 220, $contentWidth, $c['text'], $font, 'Can they make snow?');
        $this->fitText($img, 30, self::PAD, 290, $contentWidth, $c['line'], $font, 'A live snowmaking check for any US town or ski resort.');
        $this->fitText($img, 26, self::PAD, 340, $contentWidth, $c['muted'], $font, 'Wet-bulb temperature, the next 48 hours and the next snowmaking window.');

        // An illustrative night of cooling, drawn like the result chart.
        $weather = new WeatherService();
        $hourly = [];
        for ($i = 0; $i < 48; $i++) {
            $wetBulb = round(-2.5 + 5.5 * cos(($i + 6) / 24 * 2 * M_PI), 1);
            $tier = $weather->snowmakingTier($wetBulb);
            $hourly[] = ['wetBulbC' => $wetBulb, 'snow' => $tier['snow'], 'tier' => $tier['tier']];
        }
        $this->bars($img, $c, $hourly, 420, 590);
        imagettftext($img, 16, 0, self::PAD, 616, $c['muted'], $font, 'Forecasts from NOAA · free, no sign-up');

        return $this->png($img);
    }

    /**
     * Blank card with the shared header. Returns [image, colors, font path].
     */
    protected function canvas(): array
    {
        $font = self::fontPath();
        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $c = [];
        foreach (self::COLORS as $name => [$r, $g, $b]) {
            $c[$name] = imagecolorallocate($img, $r, $g, $b);
        }
        imagefilledrectangle($img, 0, 0, self::WIDTH, self::HEIGHT, $c['bg']);

        imagettftext($img, 22, 0, self::PAD, 84, $c['muted'], $font, 'CAN THEY MAKE SNOW?');
        $this->textRight($img, 22, 84, $c['muted'], $font, 'cantheymakesnow.com');

        return [$img, $c, $font];
    }

    protected function png($img): string
    {
        ob_start();
        imagepng($img, null, 6);
        imagedestroy($img);
        return ob_get_clean();
    }

    /**
     * 48 hourly columns growing from the snowmaking line, like the on-page chart.
     */
    protected function bars($img, array $c, array $hourly, int $top, int $bottom): void
    {
        if (!$hourly) {
            return;
        }

        $threshold = WeatherService::SNOWMAKING_THRESHOLD_C;
        $values = array_column($hourly, 'wetBulbC');
        $min = min(min($values), $threshold) - 1;
        $max = max(max($values), $threshold) + 1;
        $y = fn ($v) => (int) round($top + ($max - $v) / ($max - $min) * ($bottom - $top));
        $baseline = $y($threshold);
        $slot = (self::WIDTH - self::PAD * 2) / count($hourly);

        foreach ($hourly as $i => $hour) {
            $x1 = (int) round(self::PAD + $i * $slot + 2);
            $x2 = (int) round(self::PAD + ($i + 1) * $slot - 2);
            $end = $y($hour['wetBulbC']);
            if (abs($end - $baseline) < 3) {
                $end = $hour['snow'] ? $baseline + 3 : $baseline - 3;
            }
            imagefilledrectangle($img, $x1, min($baseline, $end), $x2, max($baseline, $end), $c[$hour['tier']] ?? $c['too_warm']);
        }
        imageline($img, self::PAD, $baseline, self::WIDTH - self::PAD, $baseline, $c['line']);
    }

    protected function fitText($img, int $size, int $x, int $y, int $maxWidth, int $color, string $font, string $text): void
    {
        while ($size > 12) {
            $box = imagettfbbox($size, 0, $font, $text);
            if ($box[2] - $box[0] <= $maxWidth) {
                break;
            }
            $size--;
        }
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    }

    protected function textRight($img, int $size, int $y, int $color, string $font, string $text): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        imagettftext($img, $size, 0, self::WIDTH - self::PAD - ($box[2] - $box[0]), $y, $color, $font, $text);
    }

    protected function number(float $value): string
    {
        $text = (string) round($value);
        return $text === '-0' ? '0' : str_replace('-', "\u{2212}", $text);
    }
}
