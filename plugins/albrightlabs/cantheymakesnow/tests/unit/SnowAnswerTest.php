<?php

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use AlbrightLabs\CanTheyMakeSnow\Classes\WeatherService;

require_once __DIR__ . '/../../classes/WeatherService.php';

class SnowAnswerTest extends TestCase
{
    private WeatherService $service;

    protected function setUp(): void
    {
        $this->service = new WeatherService();
    }

    private function storm(float $in, int $startHour): array
    {
        $start = Carbon::parse('2026-12-10T18:00:00Z')->addHours($startHour);
        return ['start' => $start, 'end' => $start->copy()->addHours(6), 'totalIn' => $in, 'peakIn' => $in];
    }

    public function testYesWithTheBiggestStorm()
    {
        $answer = $this->service->snowAnswer([
            'totals' => [24 => 0.5, 48 => 2.0, 72 => 3.2],
            'storms' => [$this->storm(0.5, 6), $this->storm(2.7, 36)],
        ], []);

        $this->assertSame('yes', $answer['answer']);
        $this->assertSame(3.2, $answer['totalIn']);
        $this->assertSame(2.7, $answer['storm']['totalIn']);
    }

    public function testMaybeWhenOnlyTheOutlookMentionsSnow()
    {
        $outlook = [
            ['name' => 'Today', 'short' => 'Sunny', 'snow' => null],
            ['name' => 'Fri', 'short' => 'Rain Likely', 'snow' => null],
            ['name' => 'Sat', 'short' => 'Chance Snow Showers', 'snow' => null],
        ];
        $answer = $this->service->snowAnswer(['totals' => [24 => 0.0, 48 => 0.0, 72 => 0.04], 'storms' => []], $outlook);

        $this->assertSame('maybe', $answer['answer']);
        $this->assertSame('Saturday', $answer['day']);
    }

    public function testMaybeFromAnAccumulationAndTonight()
    {
        $answer = $this->service->snowAnswer(null, [['name' => 'Tonight', 'short' => 'Mostly Cloudy', 'snow' => ['lo' => 0, 'hi' => 1]]]);
        $this->assertSame('maybe', $answer['answer']);
        $this->assertSame('tonight', $answer['day']);
    }

    public function testHolidaysKeepTheirCapitals()
    {
        $answer = $this->service->snowAnswer(null, [['name' => 'Veterans Day', 'short' => 'Slight Chance Snow Showers', 'snow' => null]]);
        $this->assertSame('Veterans Day', $answer['day']);

        $answer = $this->service->snowAnswer(null, [['name' => 'This Afternoon', 'short' => 'Chance Snow', 'snow' => null]]);
        $this->assertSame('this afternoon', $answer['day']);
    }

    public function testNoSnowAnywhere()
    {
        $answer = $this->service->snowAnswer(['totals' => [24 => 0.0, 48 => 0.0, 72 => 0.0], 'storms' => []], [
            ['name' => 'Today', 'short' => 'Sunny', 'snow' => null],
            ['name' => 'Sun', 'short' => 'Partly Cloudy', 'snow' => null],
        ]);
        $this->assertSame(['answer' => 'no', 'totalIn' => null, 'storm' => null, 'day' => null], $answer);
    }
}
