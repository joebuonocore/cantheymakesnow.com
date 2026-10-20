<?php namespace AlbrightLabs\CanTheyMakeSnow\Classes;

/**
 * Text formatting for one unit system: 'f' (°F, inches, mph) or 'c'
 * (°C, cm, km/h). Used by the theme's markup filters, which render both
 * systems and let CSS show one, and by emails, which use the
 * subscriber's choice. Negative numbers use a true minus sign.
 */
class Units
{
    public static function temp(float $celsius, string $system, int $decimals = 0): string
    {
        $value = $system === 'f' ? $celsius * 9 / 5 + 32 : $celsius;
        return self::number($value, $decimals) . ($system === 'f' ? '°F' : '°C');
    }

    /**
     * Snow depth: tenths below one inch (or 2 cm), whole numbers above.
     */
    public static function snow(float $inches, string $system): string
    {
        if ($system === 'f') {
            return self::number($inches, $inches > 0 && $inches < 1 ? 1 : 0) . '″';
        }
        $cm = $inches * 2.54;
        return self::number($cm, $cm > 0 && $cm < 2 ? 1 : 0) . ' cm';
    }

    /**
     * "4–8″" / "10–20 cm"; "<1″" when the low end is zero.
     */
    public static function snowRange(float $lo, float $hi, string $system): string
    {
        if ($lo <= 0) {
            return '<' . self::snow($hi, $system);
        }
        if ($lo == $hi) {
            return self::snow($hi, $system);
        }
        $suffix = $system === 'f' ? '″' : ' cm';
        $factor = $system === 'f' ? 1 : 2.54;
        return self::number($lo * $factor, 0) . '–' . self::number($hi * $factor, 0) . $suffix;
    }

    public static function speed(float $mph, string $system): string
    {
        return $system === 'f'
            ? self::number($mph, 0) . ' mph'
            : self::number($mph * 1.609344, 0) . ' km/h';
    }

    protected static function number(float $value, int $decimals): string
    {
        $text = number_format(round($value, $decimals), $decimals);
        if ((float) $text == 0) {
            $text = ltrim($text, '-');
        }
        return str_replace('-', "\u{2212}", $text);
    }
}
