<?php

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use AlbrightLabs\CanTheyMakeSnow\Classes\WeatherService;

require_once __DIR__ . '/../../classes/WeatherService.php';

class SnowfallTest extends TestCase
{
    private WeatherService $service;
    private Carbon $from;

    protected function setUp(): void
    {
        $this->service = new WeatherService();
        $this->from = Carbon::parse('2026-01-10T12:00:00Z');
    }

    private function mm(string $start, string $duration, float $mm): array
    {
        return ['validTime' => "{$start}+00:00/{$duration}", 'value' => $mm];
    }

    public function test_buckets_sum_six_hour_values_and_convert_to_inches()
    {
        $values = [
            $this->mm('2026-01-10T12:00:00', 'PT6H', 25.4),
            $this->mm('2026-01-10T18:00:00', 'PT6H', 50.8),
        ];
        $buckets = $this->service->snowfallBuckets($values, 'wmoUnit:mm', $this->from);

        $this->assertCount(2, $buckets);
        $this->assertEqualsWithDelta(1.0, $buckets[0]['in'], 0.001);
        $this->assertEqualsWithDelta(2.0, $buckets[1]['in'], 0.001);
        $this->assertSame('2026-01-10T18:00:00+00:00', $buckets[1]['start']->toIso8601String());
    }

    public function test_buckets_apportion_longer_intervals_evenly()
    {
        // 12 hours of snow totalling 24 mm splits 12/12 across two 6-hour buckets.
        $buckets = $this->service->snowfallBuckets([$this->mm('2026-01-10T12:00:00', 'PT12H', 24)], 'wmoUnit:mm', $this->from);
        $this->assertEqualsWithDelta(12.0, $buckets[0]['mm'], 0.001);
        $this->assertEqualsWithDelta(12.0, $buckets[1]['mm'], 0.001);
    }

    public function test_buckets_start_mid_interval_count_only_the_remaining_hours()
    {
        $from = Carbon::parse('2026-01-10T15:00:00Z');
        $buckets = $this->service->snowfallBuckets([
            $this->mm('2026-01-10T12:00:00', 'PT6H', 6),
            $this->mm('2026-01-10T18:00:00', 'PT6H', 6),
        ], 'wmoUnit:mm', $from);
        // 15:00–21:00 covers 3 h of each interval: 3 + 3 mm.
        $this->assertEqualsWithDelta(6.0, $buckets[0]['mm'], 0.001);
    }

    public function test_buckets_stop_where_data_stops()
    {
        $buckets = $this->service->snowfallBuckets([$this->mm('2026-01-10T12:00:00', 'PT6H', 0)], 'wmoUnit:mm', $this->from);
        $this->assertCount(1, $buckets);
        $this->assertSame([], $this->service->snowfallBuckets([], 'wmoUnit:mm', $this->from));
    }

    public function test_totals_are_null_beyond_the_forecast()
    {
        $values = [];
        for ($h = 0; $h < 48; $h += 6) {
            $values[] = $this->mm(Carbon::parse('2026-01-10T12:00:00Z')->addHours($h)->format('Y-m-d\TH:i:s'), 'PT6H', 25.4);
        }
        $totals = $this->service->snowTotals($this->service->snowfallBuckets($values, 'wmoUnit:mm', $this->from));

        $this->assertEqualsWithDelta(4.0, $totals[24], 0.001);
        $this->assertEqualsWithDelta(8.0, $totals[48], 0.001);
        $this->assertNull($totals[72]);
    }

    public function test_storms_group_consecutive_snowy_buckets()
    {
        $buckets = $this->service->snowfallBuckets([
            $this->mm('2026-01-10T12:00:00', 'PT6H', 0),
            $this->mm('2026-01-10T18:00:00', 'PT6H', 76.2),
            $this->mm('2026-01-11T00:00:00', 'PT6H', 101.6),
            $this->mm('2026-01-11T06:00:00', 'PT6H', 0),
            $this->mm('2026-01-11T12:00:00', 'PT6H', 25.4),
        ], 'wmoUnit:mm', $this->from);

        $storms = $this->service->storms($buckets);
        $this->assertCount(2, $storms);
        $this->assertEqualsWithDelta(7.0, $storms[0]['totalIn'], 0.001);
        $this->assertEqualsWithDelta(4.0, $storms[0]['peakIn'], 0.001);
        $this->assertSame('2026-01-11T06:00:00+00:00', $storms[0]['end']->toIso8601String());

        $biggest = $this->service->biggestStorm($storms, $this->from);
        $this->assertEqualsWithDelta(7.0, $biggest['totalIn'], 0.001);
    }

    public function test_biggest_storm_ignores_storms_starting_after_the_window()
    {
        $storms = [[
            'start' => $this->from->copy()->addHours(50),
            'end' => $this->from->copy()->addHours(56),
            'totalIn' => 10.0,
            'peakIn' => 10.0,
        ]];
        $this->assertNull($this->service->biggestStorm($storms, $this->from));
    }

    public function test_describe_weather_phrases()
    {
        $this->assertSame('Light snow likely', $this->service->describeWeather([
            ['coverage' => 'likely', 'weather' => 'snow', 'intensity' => 'light'],
        ]));
        $this->assertSame('Chance of rain and snow', $this->service->describeWeather([
            ['coverage' => 'chance', 'weather' => 'rain', 'intensity' => null],
            ['coverage' => 'chance', 'weather' => 'snow', 'intensity' => null],
        ]));
        $this->assertSame('Scattered snow showers', $this->service->describeWeather([
            ['coverage' => 'scattered', 'weather' => 'snow_showers', 'intensity' => null],
        ]));
        $this->assertNull($this->service->describeWeather([['coverage' => null, 'weather' => null, 'intensity' => null]]));
    }

    public function test_weather_icon_prefers_precipitation_then_sky()
    {
        $this->assertSame('mix', $this->service->weatherIcon([['weather' => 'rain'], ['weather' => 'snow']], 90));
        $this->assertSame('snow', $this->service->weatherIcon([['weather' => 'snow_showers']], 90));
        $this->assertSame('sun', $this->service->weatherIcon([], 5));
        $this->assertSame('partly', $this->service->weatherIcon([], 50));
        $this->assertSame('cloud', $this->service->weatherIcon([], 95));
    }

    public function test_conditions_at_reads_grid_fields_in_imperial()
    {
        $at = Carbon::parse('2026-01-10T12:30:00Z');
        $hour = fn ($v) => ['values' => [['validTime' => '2026-01-10T12:00:00+00:00/PT1H', 'value' => $v]]];
        $grid = [
            'skyCover' => $hour(100),
            'weather' => $hour([['coverage' => 'definite', 'weather' => 'snow', 'intensity' => 'heavy']]),
            'windSpeed' => $hour(16.0934) + ['uom' => 'wmoUnit:km_h-1'],
            'windGust' => $hour(32.1869) + ['uom' => 'wmoUnit:km_h-1'],
            'windChill' => $hour(-10) + ['uom' => 'wmoUnit:degC'],
            'probabilityOfPrecipitation' => $hour(90),
        ];
        $c = $this->service->conditionsAt($grid, $at);

        $this->assertSame('Heavy snow', $c['summary']);
        $this->assertSame('snow', $c['icon']);
        $this->assertEqualsWithDelta(10.0, $c['windMph'], 0.01);
        $this->assertEqualsWithDelta(20.0, $c['gustMph'], 0.01);
        $this->assertEqualsWithDelta(-10.0, $c['feelsLikeC'], 0.001);
        $this->assertEquals(90, $c['precipChance']);
    }

    public function test_snow_accumulation_phrases()
    {
        $parse = fn ($s) => $this->service->parseSnowAccumulation($s);
        $this->assertSame(['lo' => 4.0, 'hi' => 8.0], $parse('Cloudy. New snow accumulation of 4 to 8 inches possible.'));
        $this->assertSame(['lo' => 0.0, 'hi' => 0.5], $parse('New snow accumulation of less than half an inch possible.'));
        $this->assertSame(['lo' => 0.0, 'hi' => 1.0], $parse('New snow accumulation of less than one inch possible.'));
        $this->assertSame(['lo' => 2.0, 'hi' => 2.0], $parse('New snow accumulation of around 2 inches possible.'));
        $this->assertNull($parse('Little or no snow accumulation expected.'));
        $this->assertNull($parse('Sunny, with a high near 40.'));
    }

    public function test_outlook_pairs_day_and_night_periods()
    {
        $period = fn ($name, $day, $temp, $short, $detail = '') => [
            'name' => $name, 'isDaytime' => $day, 'temperature' => $temp, 'temperatureUnit' => 'F',
            'shortForecast' => $short, 'detailedForecast' => $detail,
            'icon' => 'https://api.weather.gov/icons/land/day/sct?size=medium',
        ];
        $days = $this->service->parseOutlook(['properties' => ['periods' => [
            $period('Tonight', false, 23, 'Snow Likely', 'New snow accumulation of 2 to 4 inches possible.'),
            $period('Wednesday', true, 32, 'Snow', 'New snow accumulation of 3 to 5 inches possible.'),
            $period('Wednesday Night', false, 14, 'Snow Showers', 'New snow accumulation of 1 to 2 inches possible.'),
            $period('Thursday', true, 41, 'Rain And Snow Likely'),
        ]]]);

        $this->assertCount(3, $days);
        $this->assertSame('Tonight', $days[0]['name']);
        $this->assertNull($days[0]['highC']);
        $this->assertSame(['lo' => 2.0, 'hi' => 4.0], $days[0]['snow']);

        $this->assertSame('Wed', $days[1]['name']);
        $this->assertEqualsWithDelta(0.0, $days[1]['highC'], 0.001);
        $this->assertEqualsWithDelta(-10.0, $days[1]['lowC'], 0.001);
        $this->assertSame(['lo' => 4.0, 'hi' => 7.0], $days[1]['snow']);
        $this->assertSame('snow', $days[1]['icon']);

        $this->assertSame('mix', $days[2]['icon']);
        $this->assertNull($days[2]['lowC']);
    }

    public function test_observation_includes_text_and_wind_when_present()
    {
        $obs = $this->service->parseObservation(['properties' => [
            'timestamp' => '2026-01-10T12:00:00+00:00',
            'temperature' => ['value' => -5, 'unitCode' => 'wmoUnit:degC'],
            'relativeHumidity' => ['value' => 80],
            'textDescription' => 'Light Snow',
            'windSpeed' => ['value' => 16.0934],
            'windGust' => ['value' => null],
        ]]);
        $this->assertSame('Light Snow', $obs['text']);
        $this->assertEqualsWithDelta(10.0, $obs['windMph'], 0.01);
        $this->assertNull($obs['gustMph']);
    }
}
