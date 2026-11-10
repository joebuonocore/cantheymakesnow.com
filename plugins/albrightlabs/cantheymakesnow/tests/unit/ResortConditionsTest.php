<?php

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use AlbrightLabs\CanTheyMakeSnow\Models\Resort;

// The model only needs October's base Model class here, not the app.
if (!class_exists('Model')) {
    class_alias(\October\Rain\Database\Model::class, 'Model');
}
require_once __DIR__ . '/../../classes/WeatherService.php';
require_once __DIR__ . '/../../models/Resort.php';

class ResortConditionsTest extends TestCase
{
    public function testStoresTierSnowTotalsStormAndTimeZone()
    {
        $columns = Resort::conditionsFrom([
            'answer' => ['wetBulbC' => -4.567, 'tier' => 'great'],
            'timeZone' => 'America/Denver',
            'snow' => [
                'totals' => [24 => 1.04, 48 => 6.26, 72 => null],
                'powder' => [
                    'start' => Carbon::parse('2026-12-10T19:00:00', 'America/Denver'),
                    'end' => Carbon::parse('2026-12-11T07:00:00', 'America/Denver'),
                    'totalIn' => 5.55,
                ],
            ],
        ]);

        $this->assertSame(-4.57, $columns['last_wet_bulb']);
        $this->assertSame('great', $columns['last_tier']);
        $this->assertSame(1.0, $columns['last_snow_24h_in']);
        $this->assertSame(6.3, $columns['last_snow_48h_in']);
        $this->assertNull($columns['last_snow_72h_in']);
        $this->assertSame(5.6, $columns['last_storm_in']);
        $this->assertSame('2026-12-11T02:00:00+00:00', $columns['last_storm_start']->toIso8601String());
        $this->assertSame('2026-12-11T14:00:00+00:00', $columns['last_storm_end']->toIso8601String());
        $this->assertSame('America/Denver', $columns['time_zone']);
    }

    public function testNoStormLeavesStormColumnsEmpty()
    {
        $columns = Resort::conditionsFrom([
            'answer' => ['wetBulbC' => 2.0, 'tier' => 'too_warm'],
            'timeZone' => 'America/New_York',
            'snow' => ['totals' => [24 => 0.0, 48 => 0.04, 72 => 0.1], 'powder' => null],
        ]);

        $this->assertSame(0.0, $columns['last_snow_48h_in']);
        $this->assertSame(0.1, $columns['last_snow_72h_in']);
        $this->assertNull($columns['last_storm_in']);
        $this->assertNull($columns['last_storm_start']);
        $this->assertNull($columns['last_storm_end']);
    }

    public function testNoSnowDataStoresNullsButKeepsTheTimeZone()
    {
        $columns = Resort::conditionsFrom([
            'answer' => ['wetBulbC' => 1.0, 'tier' => 'too_warm'],
            'timeZone' => 'America/New_York',
            'snow' => null,
        ]);

        foreach (['last_snow_24h_in', 'last_snow_48h_in', 'last_snow_72h_in', 'last_storm_in', 'last_storm_start', 'last_storm_end'] as $column) {
            $this->assertNull($columns[$column], $column);
        }
        $this->assertSame('America/New_York', $columns['time_zone']);
    }
}
