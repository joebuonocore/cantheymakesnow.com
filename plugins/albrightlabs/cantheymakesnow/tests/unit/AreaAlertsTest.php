<?php

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use AlbrightLabs\CanTheyMakeSnow\Classes\AlertRules;
use AlbrightLabs\CanTheyMakeSnow\Classes\AreaAlerts;

require_once __DIR__ . '/../../classes/AlertRules.php';
require_once __DIR__ . '/../../classes/AreaAlerts.php';

class AreaAlertsTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        $this->now = Carbon::parse('2026-12-10T18:00:00Z');
    }

    private function resort(string $name, float $lat, float $lon, ?float $in, int $startsInHours = 6, int $checkedHoursAgo = 1): array
    {
        return [
            'name' => $name, 'state' => 'VT', 'url' => "/resorts/{$name}", 'lat' => $lat, 'lon' => $lon,
            'stormIn' => $in,
            'stormStart' => $in === null ? null : $this->now->copy()->addHours($startsInHours),
            'stormEnd' => $in === null ? null : $this->now->copy()->addHours($startsInHours + 12),
            'timeZone' => 'America/New_York',
            'checkedAt' => $this->now->copy()->subHours($checkedHoursAgo),
        ];
    }

    public function testDistanceBetweenAlbanyAndKillington()
    {
        // About 80 miles as the crow flies.
        $miles = AreaAlerts::distanceMi(42.65, -73.75, 43.6262, -72.7967);
        $this->assertEqualsWithDelta(83.0, $miles, 3.0);
    }

    public function testKeepsFreshStormsInsideTheRadiusBiggestFirst()
    {
        $storms = AreaAlerts::storms(42.65, -73.75, 200, [
            $this->resort('Killington', 43.63, -72.80, 5.0),
            $this->resort('Jay Peak', 44.94, -72.50, 9.0),
            $this->resort('Alta', 40.59, -111.64, 20.0),          // too far
            $this->resort('Stale', 43.0, -73.0, 12.0, 6, 5),      // checked 5 hours ago
            $this->resort('Dry', 43.0, -73.0, null),
        ], $this->now);

        $this->assertSame(['Jay Peak', 'Killington'], array_map(fn ($s) => $s['resort']['name'], $storms));
        $this->assertSame(9.0, $storms[0]['totalIn']);
    }

    public function testQualifyingAppliesTheThresholdAndLookahead()
    {
        $storms = AreaAlerts::storms(42.65, -73.75, 300, [
            $this->resort('Big', 43.0, -73.0, 7.6),
            $this->resort('Small', 43.1, -73.0, 5.4),     // rounds to 5, under 6
            $this->resort('Later', 43.2, -73.0, 10.0, 60), // starts beyond 48 hours
        ], $this->now);

        $this->assertSame(['Big'], array_map(fn ($s) => $s['resort']['name'], AreaAlerts::qualifying($storms, 6, $this->now)));
    }

    public function testWorksWithPowderAlertRules()
    {
        $storms = AreaAlerts::storms(42.65, -73.75, 200, [$this->resort('Jay Peak', 44.94, -72.50, 9.0)], $this->now);
        $rules = new AlertRules();

        $hit = $rules->powderAlert($storms, 6, null, null, null, null, $this->now);
        $this->assertSame('Jay Peak', $hit['storm']['resort']['name']);

        // The same storm again (overlapping times, not much bigger) isn't re-sent.
        $again = $rules->powderAlert($storms, 6, $hit['storm']['start'], $hit['storm']['end'], 9.0, $this->now->copy()->subDay(), $this->now);
        $this->assertNull($again);
    }
}
