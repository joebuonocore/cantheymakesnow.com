<?php

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use AlbrightLabs\CanTheyMakeSnow\Classes\WeatherService;

require_once __DIR__ . '/../../classes/WeatherService.php';

class WeatherServiceTest extends TestCase
{
    private WeatherService $service;

    protected function setUp(): void
    {
        $this->service = new WeatherService();
    }

    public function test_wet_bulb_at_0c_and_100_percent_humidity_is_near_0()
    {
        $wb = $this->service->calculateWetBulb(0.0, 1.0);
        $this->assertEqualsWithDelta(0.0, $wb, 0.5);
    }

    public function test_wet_bulb_at_20c_and_50_percent_humidity_matches_known_reference()
    {
        // Stull (2011) reference value for T=20°C, RH=50% is ~13.7°C.
        $wb = $this->service->calculateWetBulb(20.0, 0.50);
        $this->assertEqualsWithDelta(13.7, $wb, 0.5);
    }

    public function test_wet_bulb_below_zero_when_cold_and_dry()
    {
        // -5°C dry bulb, 20% RH — snowmaking territory.
        $wb = $this->service->calculateWetBulb(-5.0, 0.20);
        $this->assertLessThan(0.0, $wb);
    }

    public function test_wet_bulb_above_zero_when_warm()
    {
        $wb = $this->service->calculateWetBulb(10.0, 0.80);
        $this->assertGreaterThan(0.0, $wb);
    }

    public function test_custom_round_nudges_positive_trailing_zero_up()
    {
        $this->assertEquals(40.11, $this->service->customRound(40.10));
    }

    public function test_custom_round_nudges_negative_trailing_zero_down()
    {
        $this->assertEquals(-74.01, $this->service->customRound(-74.00));
    }

    public function test_custom_round_leaves_non_trailing_zero_alone()
    {
        $this->assertEquals(40.73, $this->service->customRound(40.7312));
        $this->assertEquals(-74.15, $this->service->customRound(-74.1489));
    }

    public function test_to_celsius_converts_fahrenheit()
    {
        $this->assertEqualsWithDelta(0.0, $this->service->toCelsius(32.0, 'wmoUnit:degF'), 0.0001);
        $this->assertEqualsWithDelta(100.0, $this->service->toCelsius(212.0, 'wmoUnit:degF'), 0.0001);
    }

    public function test_to_celsius_passes_celsius_through()
    {
        $this->assertEquals(15.0, $this->service->toCelsius(15.0, 'wmoUnit:degC'));
        $this->assertEquals(15.0, $this->service->toCelsius(15.0, null));
    }

    public function test_find_closest_time_selects_nearest_window()
    {
        $now = Carbon::parse('2026-01-01T12:00:00+00:00');
        $values = [
            ['validTime' => '2026-01-01T00:00:00+00:00/PT3H', 'value' => 100],
            ['validTime' => '2026-01-01T11:00:00+00:00/PT1H', 'value' => 200],
            ['validTime' => '2026-01-01T15:00:00+00:00/PT3H', 'value' => 300],
        ];

        $result = $this->service->findClosestTime($values, $now);

        $this->assertEquals(200, $result['value']);
    }

    public function test_snowmaking_tier_classifies_by_wet_bulb()
    {
        $this->assertSame('ideal', $this->service->snowmakingTier(-10.0)['tier']);
        $this->assertTrue($this->service->snowmakingTier(-10.0)['snow']);

        $this->assertSame('great', $this->service->snowmakingTier(-5.0)['tier']);
        $this->assertTrue($this->service->snowmakingTier(-5.0)['snow']);

        $this->assertSame('marginal', $this->service->snowmakingTier(-3.0)['tier']);
        $this->assertTrue($this->service->snowmakingTier(-3.0)['snow']);

        // Strictly colder than -2 is snow; exactly -2 is not.
        $this->assertFalse($this->service->snowmakingTier(-2.0)['snow']);
        $this->assertSame('too_warm', $this->service->snowmakingTier(-2.0)['tier']);

        $this->assertSame('too_warm', $this->service->snowmakingTier(-1.0)['tier']);
        $this->assertFalse($this->service->snowmakingTier(-1.0)['snow']);

        $this->assertSame('no_chance', $this->service->snowmakingTier(5.0)['tier']);
        $this->assertFalse($this->service->snowmakingTier(5.0)['snow']);

        $this->assertSame('unknown', $this->service->snowmakingTier(null)['tier']);
        $this->assertFalse($this->service->snowmakingTier(null)['snow']);
    }

    public function test_find_closest_time_handles_missing_duration()
    {
        $now = Carbon::parse('2026-01-01T12:00:00+00:00');
        $values = [
            ['validTime' => '2026-01-01T12:00:00+00:00', 'value' => 42],
            ['validTime' => '2026-01-01T18:00:00+00:00/PT1H', 'value' => 99],
        ];

        $result = $this->service->findClosestTime($values, $now);

        $this->assertEquals(42, $result['value']);
    }
}
