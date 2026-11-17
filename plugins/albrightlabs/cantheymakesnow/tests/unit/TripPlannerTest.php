<?php

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use AlbrightLabs\CanTheyMakeSnow\Classes\TripPlanner;

require_once __DIR__ . '/../../classes/AlertRules.php';
require_once __DIR__ . '/../../classes/AreaAlerts.php';
require_once __DIR__ . '/../../classes/TripPlanner.php';

class TripPlannerTest extends TestCase
{
    private function resort(string $name, string $state, float $lat, float $lon, array $snow, array $sm, bool $fresh = true): array
    {
        $daily = [];
        foreach ($snow as $i => $in) {
            $daily[] = ['date' => '2026-12-1' . $i, 'label' => 'D' . $i, 'snowIn' => $in, 'smHours' => $sm[$i]];
        }
        return ['name' => $name, 'state' => $state, 'url' => "/resorts/{$name}", 'lat' => $lat, 'lon' => $lon, 'fresh' => $fresh, 'daily' => $daily];
    }

    public function testRanksResortsInRangeOverTheChosenDays()
    {
        $resorts = [
            $this->resort('Killington', 'VT', 43.62, -72.80, [0, 2, 6], [10, 0, 20]),
            $this->resort('Stowe', 'VT', 44.53, -72.78, [5, 0, 0], [24, 24, 24]),
            $this->resort('Alta', 'UT', 40.59, -111.64, [20, 20, 20], [24, 24, 24]),     // far away
            $this->resort('Stale', 'VT', 43.9, -72.9, [30, 30, 30], [24, 24, 24], false), // not checked recently
        ];
        $results = TripPlanner::rank($resorts, ['lat' => 42.65, 'lon' => -73.76, 'radius' => 200], ['2026-12-11', '2026-12-12']);

        $this->assertSame(['Killington', 'Stowe'], array_column($results, 'name'));
        $this->assertSame(8.0, $results[0]['snowIn']);
        $this->assertSame(20, $results[0]['smHours']);
        $this->assertSame('2026-12-12', $results[0]['best']['date']);
        $this->assertCount(2, $results[0]['days']);
        $this->assertGreaterThan(50, $results[0]['distanceMi']);
    }

    public function testStateAreaAndNoBestDayWhenNothingHappens()
    {
        $resorts = [
            $this->resort('Alta', 'UT', 40.59, -111.64, [0, 0, 0], [0, 0, 0]),
            $this->resort('Killington', 'VT', 43.62, -72.80, [9, 9, 9], [9, 9, 9]),
        ];
        $results = TripPlanner::rank($resorts, ['state' => 'UT'], ['2026-12-10']);

        $this->assertSame(['Alta'], array_column($results, 'name'));
        $this->assertNull($results[0]['best']);
        $this->assertNull($results[0]['distanceMi']);
    }

    public function testDaysStartTodayInEasternTime()
    {
        $days = TripPlanner::days(Carbon::parse('2026-12-11T03:00:00Z')); // 10 PM on the 10th in New York
        $this->assertCount(7, $days);
        $this->assertSame(['date' => '2026-12-10', 'label' => 'Today'], $days[0]);
        $this->assertSame('Tomorrow', $days[1]['label']);
        $this->assertSame('Sat 12', $days[2]['label']);
    }
}
