<?php

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use AlbrightLabs\CanTheyMakeSnow\Classes\WeatherService;

require_once __DIR__ . '/../../classes/WeatherService.php';

class ElevationTest extends TestCase
{
    private WeatherService $service;

    protected function setUp(): void
    {
        $this->service = new WeatherService();
    }

    private function point(float $tempC, float $rh): array
    {
        $wb = $this->service->calculateWetBulb($tempC, $rh);
        $tier = $this->service->snowmakingTier($wb);
        return ['time' => Carbon::parse('2026-12-10T00:00:00Z'), 'tempC' => $tempC, 'rh' => $rh, 'wetBulbC' => $wb, 'snow' => $tier['snow'], 'tier' => $tier['tier']];
    }

    public function test_dewpoint_round_trips_with_relative_humidity()
    {
        foreach ([[-10, 80], [0, 50], [5, 95], [20, 30]] as [$t, $rh]) {
            $dew = $this->service->dewpointFromRh($t, $rh);
            $this->assertEqualsWithDelta($rh, $this->service->relativeHumidityFromDewpoint($t, $dew), 0.01);
        }
    }

    public function test_going_up_cools_the_air_and_the_wet_bulb()
    {
        // 1,000 m up: air ~6.5 °C colder.
        [$up] = $this->service->adjustForElevation([$this->point(1.0, 60)], 1000);
        $this->assertEqualsWithDelta(-5.5, $up['tempC'], 0.001);
        $this->assertLessThan($this->point(1.0, 60)['wetBulbC'] - 3, $up['wetBulbC']);
        $this->assertTrue($up['snow']);
        $this->assertTrue($up['estimated']);
        $this->assertGreaterThan(60, $up['rh']); // dewpoint falls more slowly, so RH rises
    }

    public function test_rh_never_exceeds_saturation()
    {
        [$up] = $this->service->adjustForElevation([$this->point(0.0, 99)], 1500);
        $this->assertLessThanOrEqual(100.0, $up['rh']);
    }

    public function test_combine_zones_says_yes_if_any_zone_can_make_snow()
    {
        $zones = [
            'base' => ['now' => ['wetBulbC' => 0.5]],
            'summit' => ['now' => ['wetBulbC' => -3.2]],
        ];
        $answer = $this->service->combineZones($zones);
        $this->assertTrue($answer['snow']);
        $this->assertSame(['summit'], $answer['where']);
        $this->assertSame('summit', $answer['zone']);
        $this->assertSame('marginal', $answer['tier']);
    }

    public function test_combine_zones_handles_inversions_and_single_places()
    {
        // Colder at the base (cold air pooling): base is the coldest zone.
        $answer = $this->service->combineZones([
            'base' => ['now' => ['wetBulbC' => -6.0]],
            'summit' => ['now' => ['wetBulbC' => -2.5]],
        ]);
        $this->assertSame(['base', 'summit'], $answer['where']);
        $this->assertSame('base', $answer['zone']);
        $this->assertSame('great', $answer['tier']);

        $single = $this->service->combineZones(['here' => ['now' => ['wetBulbC' => 2.0]]]);
        $this->assertFalse($single['snow']);
        $this->assertSame([], $single['where']);
        $this->assertSame('no_chance', $single['tier']);
    }
}
