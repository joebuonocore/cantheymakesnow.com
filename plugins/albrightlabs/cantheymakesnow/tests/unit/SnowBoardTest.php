<?php

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use AlbrightLabs\CanTheyMakeSnow\Classes\SnowBoard;

require_once __DIR__ . '/../../classes/WeatherService.php';
require_once __DIR__ . '/../../classes/SnowBoard.php';

class SnowBoardTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        $this->now = Carbon::parse('2026-12-10T18:00:00Z'); // 1 PM in New York
    }

    private function row(string $name, ?float $in24, ?float $in48, ?float $in72): array
    {
        return ['name' => $name, 'state' => 'VT', 'url' => "/resorts/{$name}", 'tier' => null, 'totals' => [24 => $in24, 48 => $in48, 72 => $in72], 'when' => null];
    }

    public function testRanksByTheChosenWindowThenName()
    {
        $rows = [
            $this->row('Bolton', 1.0, 4.0, 5.0),
            $this->row('Stowe', 3.0, 6.0, 6.0),
            $this->row('Jay', 0.0, 4.0, 9.0),
            $this->row('Alta', 0.5, 4.0, 4.0),
        ];

        $this->assertSame(['Stowe', 'Alta', 'Bolton', 'Jay'], array_column(SnowBoard::rank($rows, 48), 'name'));
        $this->assertSame(['Jay', 'Stowe', 'Bolton', 'Alta'], array_column(SnowBoard::rank($rows, 72), 'name'));
        $this->assertSame(['Stowe', 'Bolton', 'Alta', 'Jay'], array_column(SnowBoard::rank($rows, 24), 'name'));
    }

    public function testDropsResortsWithOnlyATraceInEveryWindow()
    {
        $rows = [
            $this->row('Dry', 0.0, 0.0, 0.05),
            $this->row('Unknown', null, null, null),
            $this->row('Later', 0.0, 0.0, 2.0),
        ];

        // "Later" stays (the 72-hour view shows it); the page hides it at 48 hours.
        $this->assertSame(['Later'], array_column(SnowBoard::rank($rows, 48), 'name'));
    }

    public function testRowCastsStoredDecimals()
    {
        $row = SnowBoard::row([
            'name' => 'Stowe', 'state' => 'VT', 'url' => '/resorts/stowe-mountain-resort', 'tier' => 'great',
            'totals' => [24 => '1.5', 48 => '6.2', 72 => null],
        ], $this->now);

        $this->assertSame([24 => 1.5, 48 => 6.2, 72 => null], $row['totals']);
        $this->assertSame('great', $row['tier']);
        $this->assertNull($row['when']);
    }

    public function testWhenAStormIsStillToCome()
    {
        // 9 PM to 6 AM New York time.
        $start = Carbon::parse('2026-12-11T02:00:00Z');
        $end = Carbon::parse('2026-12-11T11:00:00Z');

        $this->assertSame('Tonight 9 PM – 6 AM', SnowBoard::when($start, $end, 'America/New_York', $this->now));
    }

    public function testWhenAStormStartsAndEndsTheSameDay()
    {
        // Saturday midnight to noon, New York time.
        $start = Carbon::parse('2026-12-12T05:00:00Z');
        $end = Carbon::parse('2026-12-12T17:00:00Z');

        $this->assertSame('Sat 12 AM – 12 PM', SnowBoard::when($start, $end, 'America/New_York', $this->now));
    }

    public function testWhenAStormHasStarted()
    {
        $start = Carbon::parse('2026-12-10T15:00:00Z');
        $end = Carbon::parse('2026-12-11T23:00:00Z'); // 6 PM tomorrow in New York

        $this->assertSame('Now until tomorrow night 6 PM', SnowBoard::when($start, $end, 'America/New_York', $this->now));
    }

    public function testNoWhenForAFinishedStormOrMissingTimeZone()
    {
        $this->assertNull(SnowBoard::when(Carbon::parse('2026-12-10T06:00:00Z'), Carbon::parse('2026-12-10T12:00:00Z'), 'America/New_York', $this->now));
        $this->assertNull(SnowBoard::when(Carbon::parse('2026-12-11T02:00:00Z'), Carbon::parse('2026-12-11T11:00:00Z'), null, $this->now));
    }
}
