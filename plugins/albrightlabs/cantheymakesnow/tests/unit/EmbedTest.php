<?php

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use AlbrightLabs\CanTheyMakeSnow\Classes\Embed;

require_once __DIR__ . '/../../classes/WeatherService.php';
require_once __DIR__ . '/../../classes/Units.php';
require_once __DIR__ . '/../../classes/SnowBoard.php';
require_once __DIR__ . '/../../classes/Embed.php';

class EmbedTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        $this->now = Carbon::parse('2026-12-10T18:00:00Z'); // 1 PM in New York
    }

    private function resort(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Jay Peak Resort', 'state' => 'VT', 'url' => 'https://cantheymakesnow.com/resorts/jay-peak-resort',
            'tier' => 'great', 'wetBulbC' => '-4.5', 'snow48In' => '6.3', 'smHours' => '18',
            'smNextStart' => Carbon::parse('2026-12-11T02:00:00Z'), 'timeZone' => 'America/New_York',
            'checkedAt' => $this->now->copy()->subMinutes(40),
        ];
    }

    public function testFreshResortInBothUnits()
    {
        $data = Embed::data($this->resort(), $this->now);

        $this->assertTrue($data['fresh']);
        $this->assertSame('yes', $data['answer']);
        $this->assertSame(['f' => '24°F', 'c' => "\u{2212}5°C"], $data['wetBulb']);
        $this->assertSame(['f' => "6\u{2033}", 'c' => '16 cm'], $data['snow48']);
        $this->assertSame(18, $data['snowmakingHours48']);
        $this->assertSame('tonight 9 PM', $data['snowmakingStarts']);
        $this->assertSame('2026-12-10T17:20:00+00:00', $data['checkedAt']);
    }

    public function testStaleOrMissingData()
    {
        $data = Embed::data($this->resort([
            'tier' => 'too_warm', 'wetBulbC' => null, 'snow48In' => null, 'smHours' => '0',
            'checkedAt' => $this->now->copy()->subHours(8),
        ]), $this->now);

        $this->assertFalse($data['fresh']);
        $this->assertSame('no', $data['answer']);
        $this->assertNull($data['wetBulb']);
        $this->assertNull($data['snow48']);
        $this->assertSame(0, $data['snowmakingHours48']);
        $this->assertNull($data['snowmakingStarts']);
    }
}
