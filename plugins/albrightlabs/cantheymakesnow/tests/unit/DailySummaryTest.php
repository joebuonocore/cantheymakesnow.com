<?php

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use AlbrightLabs\CanTheyMakeSnow\Classes\WeatherService;

require_once __DIR__ . '/../../classes/WeatherService.php';

class DailySummaryTest extends TestCase
{
    private WeatherService $service;
    private Carbon $now;

    protected function setUp(): void
    {
        $this->service = new WeatherService();
        $this->now = Carbon::parse('2026-12-10T21:00:00Z'); // 4 PM in New York
    }

    /** Hourly points from $now with the given wet-bulbs (°C); temperature = wet-bulb + 2. */
    private function series(array $wetBulbs): array
    {
        $out = [];
        foreach ($wetBulbs as $i => $wb) {
            $out[] = ['time' => $this->now->copy()->addHours($i), 'wetBulbC' => $wb, 'tempC' => $wb + 2, 'snow' => $this->service->snowmakingTier($wb)['snow']];
        }
        return $out;
    }

    public function testSplitsByLocalDayAndCountsEitherZone()
    {
        // 4 PM–11 PM today (8 hours), then midnight onwards tomorrow.
        $base = $this->series([1, 1, -3, -3, -5, 1, 1, 1, -3, -3]);
        $summit = $this->series([-3, 1, 1, 1, 1, -8, 1, 1, 1, 1]);
        $t = $this->now->timestamp;
        $days = $this->service->dailySummary(['base' => $base, 'summit' => $summit], [$t => 0.5, $t + 9 * 3600 => 1.25], 'America/New_York', $this->now);

        $this->assertSame(['2026-12-10', '2026-12-11'], array_column($days, 'date'));
        $this->assertSame('Thu', $days[0]['label']);
        // Today: hours 0 (summit), 2, 3 (base), 4 (base, great) and 5 (summit, ideal).
        $this->assertSame(5, $days[0]['smHours']);
        $this->assertSame(2, $days[0]['greatHours']);
        $this->assertSame(-8.0, $days[0]['coldestWb']);
        $this->assertSame(0.5, $days[0]['snowIn']);
        $this->assertSame(8, $days[0]['hoursCovered']);
        $this->assertSame(-3.0, $days[0]['lowC']); // base only: -5 + 2
        $this->assertSame(2, $days[1]['smHours']);
        $this->assertSame(1.3, $days[1]['snowIn']);
    }

    public function testIgnoresSnowBeforeNowAndLimitsDays()
    {
        $base = $this->series(array_fill(0, 24 * 9, 1.0));
        $days = $this->service->dailySummary(['here' => $base], [$this->now->timestamp - 3600 => 3.0], 'America/New_York', $this->now);

        $this->assertCount(7, $days);
        $this->assertSame(0.0, $days[0]['snowIn']);
        $this->assertSame(0, $days[0]['smHours']);
    }
}
