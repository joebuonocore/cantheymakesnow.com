<?php

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use AlbrightLabs\CanTheyMakeSnow\Classes\WeatherService;
use AlbrightLabs\CanTheyMakeSnow\Models\ResortHour;

// The model only needs October's base Model class here, not the app.
if (!class_exists('Model')) {
    class_alias(\October\Rain\Database\Model::class, 'Model');
}
require_once __DIR__ . '/../../classes/WeatherService.php';
require_once __DIR__ . '/../../models/ResortHour.php';

class ResortHourTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        $this->now = Carbon::parse('2026-12-10T18:20:00Z');
    }

    private function hourly(int $fromHour, array $wetBulbs): array
    {
        $points = [];
        foreach ($wetBulbs as $i => $wb) {
            $points[] = ['time' => $this->now->copy()->startOfHour()->addHours($fromHour + $i), 'wetBulbC' => $wb];
        }
        return $points;
    }

    public function testLogsTheCurrentHourAndTheNextThree()
    {
        $t = $this->now->copy()->startOfHour()->timestamp;
        $rows = ResortHour::rowsFrom(7, [
            'zones' => [
                'base' => ['hourly' => $this->hourly(0, [-1.234, -2.0, -3.0, -4.0, -5.0, -6.0])],
                'summit' => ['hourly' => $this->hourly(0, [-6.0, -7.0, -8.0, -9.0, -10.0, -11.0])],
            ],
            'snow' => ['hourly' => [$t => 0.25, $t + 3600 => 0.5]],
        ], $this->now);

        $this->assertCount(4, $rows);
        $this->assertSame(['resort_id' => 7, 'hour' => '2026-12-10 18:00:00', 'base_wet_bulb' => -1.23, 'summit_wet_bulb' => -6.0, 'snow_in' => 0.25], $rows[0]);
        $this->assertSame('2026-12-10 21:00:00', $rows[3]['hour']);
        $this->assertSame(0.0, $rows[2]['snow_in']); // inside the snow forecast, no snow that hour
    }

    public function testNoSummitOrSnowDataLeavesThoseColumnsEmpty()
    {
        $rows = ResortHour::rowsFrom(7, [
            'zones' => ['base' => ['hourly' => $this->hourly(0, [1.0, 1.0, 1.0, 1.0])]],
            'snow' => null,
        ], $this->now);

        $this->assertCount(4, $rows);
        $this->assertNull($rows[0]['summit_wet_bulb']);
        $this->assertNull($rows[0]['snow_in']);
    }

    public function testSkipsHoursMissingFromTheForecast()
    {
        // The series starts an hour late (NOAA hadn't covered the current hour yet).
        $rows = ResortHour::rowsFrom(7, ['zones' => ['base' => ['hourly' => $this->hourly(1, [0.5, 0.5])]]], $this->now);

        $this->assertSame(['2026-12-10 19:00:00', '2026-12-10 20:00:00'], array_column($rows, 'hour'));
    }

    public function testSnowfallHourlySpreadsIntervalsEvenly()
    {
        $hourly = (new WeatherService())->snowfallHourly([
            ['validTime' => '2026-12-10T18:00:00+00:00/PT6H', 'value' => 60.0], // 60 mm over 6 hours
        ], 'wmoUnit:mm');

        $this->assertCount(6, $hourly);
        $this->assertSame(10.0, $hourly[Carbon::parse('2026-12-10T18:00:00Z')->timestamp]);
        $this->assertSame(10.0, $hourly[Carbon::parse('2026-12-10T23:00:00Z')->timestamp]);
    }
}
