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

    public function test_find_closest_time_prefers_window_containing_now()
    {
        $now = Carbon::parse('2026-01-01T12:00:00+00:00');
        $values = [
            // Ends exactly at 12:00 — no longer valid.
            ['validTime' => '2026-01-01T06:00:00+00:00/PT6H', 'value' => 1],
            // 12:00–13:00 contains now.
            ['validTime' => '2026-01-01T12:00:00+00:00/PT1H', 'value' => 2],
        ];

        $this->assertEquals(2, $this->service->findClosestTime($values, $now)['value']);
    }

    public function test_find_closest_time_handles_day_and_minute_durations()
    {
        $now = Carbon::parse('2026-01-02T03:00:00+00:00');
        $values = [
            ['validTime' => '2026-01-01T00:00:00+00:00/PT30M', 'value' => 1],
            // Runs for a day and six hours, so it covers now.
            ['validTime' => '2026-01-01T00:00:00+00:00/P1DT6H', 'value' => 2],
        ];

        $this->assertEquals(2, $this->service->findClosestTime($values, $now)['value']);
    }

    public function test_hourly_series_expands_multi_hour_intervals_and_converts_units()
    {
        $from = Carbon::parse('2026-01-01T12:20:00+00:00');
        $temps = [
            ['validTime' => '2026-01-01T11:00:00+00:00/PT3H', 'value' => 14.0], // °F, covers 11–14
            ['validTime' => '2026-01-01T14:00:00+00:00/PT1H', 'value' => 50.0],
        ];
        $rhs = [
            ['validTime' => '2026-01-01T00:00:00+00:00/P1D', 'value' => 50],
        ];

        $series = $this->service->hourlySeries($temps, $rhs, 'wmoUnit:degF', $from, 4);

        // 12:00, 13:00, 14:00 have data; 15:00 has no temperature and is skipped.
        $this->assertCount(3, $series);
        $this->assertSame('2026-01-01T12:00:00+00:00', $series[0]['time']->toIso8601String());
        $this->assertEqualsWithDelta(-10.0, $series[0]['tempC'], 0.001);
        $this->assertTrue($series[0]['snow']);
        $this->assertEqualsWithDelta(10.0, $series[2]['tempC'], 0.001);
        $this->assertFalse($series[2]['snow']);
    }

    private function seriesFromFlags(string $start, array $snowFlags): array
    {
        $series = [];
        foreach ($snowFlags as $i => $snow) {
            $series[] = [
                'time' => Carbon::parse($start)->addHours($i),
                'wetBulbC' => $snow ? -3.0 - $i : 2.0,
                'snow' => $snow,
            ];
        }
        return $series;
    }

    public function test_snowmaking_windows_groups_consecutive_hours()
    {
        $series = $this->seriesFromFlags('2026-01-01T00:00:00+00:00', [false, true, true, false, true]);

        $windows = $this->service->snowmakingWindows($series);

        $this->assertCount(2, $windows);
        $this->assertSame('2026-01-01T01:00:00+00:00', $windows[0]['start']->toIso8601String());
        $this->assertSame('2026-01-01T03:00:00+00:00', $windows[0]['end']->toIso8601String());
        $this->assertSame(2, $windows[0]['hours']);
        $this->assertEqualsWithDelta(-5.0, $windows[0]['coldestWetBulbC'], 0.001);
        $this->assertSame(1, $windows[1]['hours']);
    }

    public function test_snowmaking_windows_splits_on_gaps_in_series()
    {
        $series = $this->seriesFromFlags('2026-01-01T00:00:00+00:00', [true, true]);
        $series[1]['time'] = Carbon::parse('2026-01-01T05:00:00+00:00');

        $this->assertCount(2, $this->service->snowmakingWindows($series));
    }

    public function test_snowmaking_windows_empty_when_never_cold_enough()
    {
        $series = $this->seriesFromFlags('2026-01-01T00:00:00+00:00', [false, false]);

        $this->assertSame([], $this->service->snowmakingWindows($series));
    }

    public function test_describe_windows_none()
    {
        $now = Carbon::parse('2026-01-01T12:00:00+00:00');
        $this->assertSame('none', $this->service->describeWindows([], $now, 'America/New_York')['state']);
    }

    public function test_describe_windows_overnight()
    {
        // 2026-01-01 12:00 UTC is 7 AM in New York. Window runs 9 PM – 8 AM local.
        $now = Carbon::parse('2026-01-01T12:00:00+00:00');
        $windows = [[
            'start' => Carbon::parse('2026-01-02T02:00:00+00:00'),
            'end' => Carbon::parse('2026-01-02T13:00:00+00:00'),
            'hours' => 11,
            'coldestWetBulbC' => -7.0,
        ]];

        $result = $this->service->describeWindows($windows, $now, 'America/New_York');

        $this->assertSame('upcoming', $result['state']);
        $this->assertSame('Next snowmaking window: tonight 9 PM – 8 AM.', $result['text']);
    }

    public function test_describe_windows_already_running()
    {
        $now = Carbon::parse('2026-01-01T12:00:00+00:00');
        $windows = [[
            'start' => Carbon::parse('2026-01-01T08:00:00+00:00'),
            'end' => Carbon::parse('2026-01-01T15:00:00+00:00'),
            'hours' => 7,
            'coldestWetBulbC' => -5.0,
        ]];

        $result = $this->service->describeWindows($windows, $now, 'America/New_York');

        $this->assertSame('now', $result['state']);
        $this->assertSame('Snowmaking conditions now, until today 10 AM.', $result['text']);

        $openEnded = $this->service->describeWindows($windows, $now, 'America/New_York', Carbon::parse('2026-01-01T15:00:00+00:00'));
        $this->assertStringContainsString('through the 48-hour forecast', $openEnded['text']);
    }

    public function test_describe_windows_skips_windows_already_over()
    {
        $now = Carbon::parse('2026-01-01T12:00:00+00:00');
        $windows = [
            ['start' => Carbon::parse('2026-01-01T00:00:00+00:00'), 'end' => Carbon::parse('2026-01-01T06:00:00+00:00'), 'hours' => 6, 'coldestWetBulbC' => -4.0],
            ['start' => Carbon::parse('2026-01-03T20:00:00+00:00'), 'end' => Carbon::parse('2026-01-03T23:00:00+00:00'), 'hours' => 3, 'coldestWetBulbC' => -4.0],
        ];

        $result = $this->service->describeWindows($windows, $now, 'America/New_York');

        $this->assertSame('Next snowmaking window: Sat 3 PM – 6 PM.', $result['text']);
    }

    public function test_parse_observation_uses_reported_humidity()
    {
        $obs = $this->service->parseObservation(['properties' => [
            'timestamp' => '2026-01-01T12:00:00+00:00',
            'temperature' => ['unitCode' => 'wmoUnit:degC', 'value' => -5.0],
            'relativeHumidity' => ['value' => 40.0],
            'dewpoint' => ['value' => null],
        ]]);

        $this->assertEqualsWithDelta(-5.0, $obs['tempC'], 0.001);
        $this->assertEqualsWithDelta(40.0, $obs['rh'], 0.001);
        $this->assertLessThan(-5.0, $obs['wetBulbC']);
    }

    public function test_parse_observation_derives_humidity_from_dewpoint()
    {
        $obs = $this->service->parseObservation(['properties' => [
            'timestamp' => '2026-01-01T12:00:00+00:00',
            'temperature' => ['value' => 10.0],
            'relativeHumidity' => ['value' => null],
            'dewpoint' => ['value' => 10.0],
        ]]);

        $this->assertEqualsWithDelta(100.0, $obs['rh'], 0.01);
    }

    public function test_parse_observation_returns_null_without_usable_data()
    {
        $this->assertNull($this->service->parseObservation(null));
        $this->assertNull($this->service->parseObservation(['properties' => [
            'timestamp' => '2026-01-01T12:00:00+00:00',
            'temperature' => ['value' => null],
        ]]));
        $this->assertNull($this->service->parseObservation(['properties' => [
            'timestamp' => '2026-01-01T12:00:00+00:00',
            'temperature' => ['value' => 3.0],
            'relativeHumidity' => ['value' => null],
            'dewpoint' => ['value' => null],
        ]]));
    }

    public function test_noaa_country_coverage()
    {
        $this->assertTrue($this->service->isNoaaCountry('US'));
        $this->assertTrue($this->service->isNoaaCountry('pr'));
        $this->assertFalse($this->service->isNoaaCountry('CA'));
        $this->assertFalse($this->service->isNoaaCountry(null));
    }

    public function test_to_fahrenheit()
    {
        $this->assertEqualsWithDelta(-4.0, $this->service->toFahrenheit(-20.0), 0.0001);
    }

    public function test_find_closest_time_respects_timezone_offset()
    {
        // 07:00-05:00 is 12:00 UTC.
        $now = Carbon::parse('2026-01-01T12:30:00+00:00');
        $values = [
            ['validTime' => '2026-01-01T07:00:00-05:00/PT1H', 'value' => 1],
            ['validTime' => '2026-01-01T15:00:00+00:00/PT1H', 'value' => 2],
        ];

        $this->assertEquals(1, $this->service->findClosestTime($values, $now)['value']);
    }

    public function test_relative_time_uses_calendar_days()
    {
        $ref = Carbon::parse('2026-12-10 13:00', 'America/New_York');
        $at = fn (string $t) => Carbon::parse($t, 'America/New_York');

        $this->assertSame('today 3 PM', $this->service->relativeTime($at('2026-12-10 15:00'), $ref));
        $this->assertSame('tonight 9 PM', $this->service->relativeTime($at('2026-12-10 21:00'), $ref));
        $this->assertSame('tomorrow 6 AM', $this->service->relativeTime($at('2026-12-11 06:00'), $ref));
        $this->assertSame('tomorrow night 8 PM', $this->service->relativeTime($at('2026-12-11 20:00'), $ref));
        $this->assertSame('Sat 10 AM', $this->service->relativeTime($at('2026-12-12 10:00'), $ref));
        $this->assertSame('8 AM', $this->service->relativeTime($at('2026-12-11 08:00'), $ref, true));
    }
}
