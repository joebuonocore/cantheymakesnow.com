<?php

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use AlbrightLabs\CanTheyMakeSnow\Classes\WeatherService;

require_once __DIR__ . '/../../classes/WeatherService.php';

class SnowmakingStatsTest extends TestCase
{
    private WeatherService $service;
    private Carbon $now;

    protected function setUp(): void
    {
        $this->service = new WeatherService();
        $this->now = Carbon::parse('2026-12-10T18:00:00Z');
    }

    private function zone(array $wetBulbs): array
    {
        $hourly = [];
        foreach ($wetBulbs as $i => $wb) {
            $hourly[] = ['time' => $this->now->copy()->addHours($i), 'wetBulbC' => $wb, 'snow' => $this->service->snowmakingTier($wb)['snow']];
        }
        return ['hourly' => $hourly];
    }

    public function testCountsHoursWhenEitherZoneCanMakeSnow()
    {
        $stats = $this->service->snowmakingStats([
            'base' => $this->zone([1.0, 1.0, -3.0, -5.0, 0.5]),
            'summit' => $this->zone([-2.5, 0.5, -1.0, -8.0, 0.5]),
        ]);

        // Hours 0 (summit), 2 (base) and 3 (both) can make snow; hour 3 is great or better.
        $this->assertSame(3, $stats['hours']);
        $this->assertSame(1, $stats['greatHours']);
        $this->assertSame(-8.0, $stats['coldestWetBulbC']);
        $this->assertSame($this->now->toIso8601String(), $stats['nextStart']->toIso8601String());
    }

    public function testNoSnowmakingHours()
    {
        $stats = $this->service->snowmakingStats(['here' => $this->zone([2.0, 1.5, -1.0])]);

        $this->assertSame(0, $stats['hours']);
        $this->assertSame(0, $stats['greatHours']);
        $this->assertSame(-1.0, $stats['coldestWetBulbC']);
        $this->assertNull($stats['nextStart']);
    }

    public function testNextStartIsTheFirstSnowmakingHour()
    {
        $stats = $this->service->snowmakingStats(['here' => $this->zone([1.0, 1.0, -2.5, -2.5])]);

        $this->assertSame($this->now->copy()->addHours(2)->toIso8601String(), $stats['nextStart']->toIso8601String());
        $this->assertSame(2, $stats['hours']);
    }

    public function testEmptyZones()
    {
        $this->assertSame(['hours' => 0, 'greatHours' => 0, 'coldestWetBulbC' => null, 'nextStart' => null], $this->service->snowmakingStats([]));
    }
}
