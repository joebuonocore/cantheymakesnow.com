<?php

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use AlbrightLabs\CanTheyMakeSnow\Classes\AlertRules;

require_once __DIR__ . '/../../classes/AlertRules.php';

class AlertRulesTest extends TestCase
{
    private AlertRules $rules;
    private Carbon $now;

    protected function setUp(): void
    {
        $this->rules = new AlertRules();
        $this->now = Carbon::parse('2026-12-10T18:00:00Z'); // 1 PM in New York
    }

    private function window(int $fromHours, int $toHours, float $coldest = -5.0): array
    {
        return [
            'start' => $this->now->copy()->addHours($fromHours),
            'end' => $this->now->copy()->addHours($toHours),
            'hours' => $toHours - $fromHours,
            'coldestWetBulbC' => $coldest,
        ];
    }

    private function storm(int $fromHours, int $toHours, float $inches): array
    {
        return [
            'start' => $this->now->copy()->addHours($fromHours),
            'end' => $this->now->copy()->addHours($toHours),
            'totalIn' => $inches,
            'peakIn' => $inches / 2,
        ];
    }

    public function test_quiet_hours_use_the_resort_time_zone()
    {
        $this->assertFalse($this->rules->isQuietHours($this->now, 'America/New_York'));
        $this->assertTrue($this->rules->isQuietHours(Carbon::parse('2026-12-11T04:00:00Z'), 'America/New_York')); // 11 PM
        $this->assertTrue($this->rules->isQuietHours(Carbon::parse('2026-12-11T10:59:00Z'), 'America/New_York')); // 5:59 AM
        $this->assertFalse($this->rules->isQuietHours(Carbon::parse('2026-12-11T11:00:00Z'), 'America/New_York')); // 6 AM
    }

    public function test_first_upcoming_window_is_alerted()
    {
        $window = $this->rules->snowmakingAlert([$this->window(8, 18)], 'marginal', null, null, null, $this->now);
        $this->assertNotNull($window);
        $this->assertSame(10, $window['hours']);
    }

    public function test_window_already_alerted_is_not_repeated_even_if_times_shift()
    {
        $last = $this->window(8, 18);
        $shifted = $this->window(9, 20);
        $this->assertNull($this->rules->snowmakingAlert([$shifted], 'marginal', $last['start'], $last['end'], $this->now->copy()->subHours(30), $this->now));
    }

    public function test_new_window_after_the_alerted_one_is_alerted_once_spacing_allows()
    {
        $last = $this->window(-20, -10);
        $next = $this->window(8, 18);
        $this->assertNull($this->rules->snowmakingAlert([$next], 'marginal', $last['start'], $last['end'], $this->now->copy()->subHours(10), $this->now));
        $this->assertNotNull($this->rules->snowmakingAlert([$next], 'marginal', $last['start'], $last['end'], $this->now->copy()->subHours(21), $this->now));
    }

    public function test_great_only_skips_marginal_windows()
    {
        $windows = [$this->window(2, 6, -3.0), $this->window(20, 30, -6.0)];
        $window = $this->rules->snowmakingAlert($windows, 'great', null, null, null, $this->now);
        $this->assertEquals($this->now->copy()->addHours(20), $window['start']);
        $this->assertNull($this->rules->snowmakingAlert([$this->window(2, 6, -3.0)], 'great', null, null, null, $this->now));
    }

    public function test_finished_windows_are_ignored_and_running_ones_count()
    {
        $this->assertNull($this->rules->snowmakingAlert([$this->window(-10, -2)], 'marginal', null, null, null, $this->now));
        $this->assertNotNull($this->rules->snowmakingAlert([$this->window(-3, 5)], 'marginal', null, null, null, $this->now));
    }

    public function test_powder_needs_the_threshold_within_48_hours()
    {
        $this->assertNull($this->rules->powderAlert([$this->storm(6, 24, 5.2)], 6, null, null, null, null, $this->now));
        $hit = $this->rules->powderAlert([$this->storm(6, 24, 5.6)], 6, null, null, null, null, $this->now);
        $this->assertFalse($hit['update']); // rounds to 6″, matching what the email shows
        $this->assertNull($this->rules->powderAlert([$this->storm(50, 60, 20)], 6, null, null, null, null, $this->now));
    }

    public function test_powder_picks_the_biggest_qualifying_storm()
    {
        $hit = $this->rules->powderAlert([$this->storm(2, 8, 4), $this->storm(20, 40, 11)], 3, null, null, null, null, $this->now);
        $this->assertSame(11.0, (float) $hit['storm']['totalIn']);
    }

    public function test_same_storm_is_not_repeated_unless_it_grows_a_lot()
    {
        $last = $this->storm(6, 24, 7);
        $lastSent = $this->now->copy()->subHours(21);
        $same = $this->storm(7, 26, 9);
        $this->assertNull($this->rules->powderAlert([$same], 6, $last['start'], $last['end'], 7.0, $lastSent, $this->now));

        $bigger = $this->storm(7, 30, 12);
        $hit = $this->rules->powderAlert([$bigger], 6, $last['start'], $last['end'], 7.0, $lastSent, $this->now);
        $this->assertTrue($hit['update']);
        $this->assertSame(7.0, $hit['previousIn']);
    }

    public function test_powder_respects_spacing()
    {
        $this->assertNull($this->rules->powderAlert([$this->storm(6, 24, 10)], 6, null, null, null, $this->now->copy()->subHours(5), $this->now));
    }
}
