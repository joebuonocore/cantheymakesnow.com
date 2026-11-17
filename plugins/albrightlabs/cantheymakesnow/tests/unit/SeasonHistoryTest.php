<?php

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use AlbrightLabs\CanTheyMakeSnow\Classes\SeasonHistory;

require_once __DIR__ . '/../../classes/WeatherService.php';
require_once __DIR__ . '/../../classes/SeasonHistory.php';

class SeasonHistoryTest extends TestCase
{
    private function row(string $utc, ?float $base, ?float $summit = null, ?float $snow = 0.0): array
    {
        return ['hour' => Carbon::parse($utc, 'UTC'), 'base' => $base, 'summit' => $summit, 'snow' => $snow];
    }

    public function testGroupsByLocalWeekAndCountsEitherZone()
    {
        $weeks = SeasonHistory::weekly([
            $this->row('2026-11-16T03:00:00Z', 1.0, -3.0, 0.5),  // Sun 10 PM in New York: week of Nov 9
            $this->row('2026-11-16T06:00:00Z', -5.0, null, 0.25), // Mon 1 AM: week of Nov 16, great
            $this->row('2026-11-16T07:00:00Z', 1.0, 0.5),         // too warm
            $this->row('2026-11-16T08:00:00Z', null, -2.5, null), // summit only
        ], 'America/New_York');

        $this->assertSame(['2026-11-09', '2026-11-16'], array_column($weeks, 'start'));
        $this->assertSame('Nov 16', $weeks[1]['label']);
        $this->assertSame([1, 2], array_column($weeks, 'smHours'));
        $this->assertSame([0, 1], array_column($weeks, 'greatHours'));
        $this->assertSame([0.5, 0.3], array_column($weeks, 'snowIn'));
        $this->assertSame([1, 3], array_column($weeks, 'hoursLogged'));
    }

    public function testSeasonStartsOnOctoberFirst()
    {
        $this->assertSame('2026-10-01', SeasonHistory::seasonStart(Carbon::parse('2026-11-17'))->format('Y-m-d'));
        $this->assertSame('2026-10-01', SeasonHistory::seasonStart(Carbon::parse('2027-02-01'))->format('Y-m-d'));
    }

    public function testWatchRanksByTotalThenSoFar()
    {
        $rows = SeasonHistory::rankWatch([
            ['name' => 'B', 'soFar' => 10, 'next7' => 50],
            ['name' => 'A', 'soFar' => 40, 'next7' => 20],
            ['name' => 'C', 'soFar' => 0, 'next7' => 100],
        ]);

        $this->assertSame(['C', 'A', 'B'], array_column($rows, 'name'));
        $this->assertSame([100, 60, 60], array_column($rows, 'total'));
    }
}
