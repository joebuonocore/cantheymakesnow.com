<?php namespace AlbrightLabs\CanTheyMakeSnow\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use AlbrightLabs\CanTheyMakeSnow\Classes\SnowReport;
use AlbrightLabs\CanTheyMakeSnow\Models\Resort;

/**
 * Hourly: refresh every resort that hasn't been checked recently, so the
 * snow forecast page and the map pins stay current without anyone looking
 * the resort up. Uses NOAA only (no Google calls). A resort whose lookup
 * fails keeps its last known conditions.
 */
class RefreshResorts extends Command
{
    protected $signature = 'ctms:refresh-resorts
        {--all : Refresh every enabled resort, however recently it was checked}
        {--dry-run : List the resorts that would be refreshed}';

    protected $description = 'Refresh stored snowmaking and snowfall forecasts for resorts';

    /**
     * Resorts checked more recently than this (by a lookup, the alerts job
     * or an earlier run) are skipped. Run hourly, that refreshes each resort
     * about every two hours, inside the map's three-hour "stale" mark.
     */
    const FRESH_MINUTES = 90;

    /**
     * Pause between resorts, to keep the burst of NOAA requests polite.
     */
    const PAUSE_MICROSECONDS = 250000;

    public function handle()
    {
        $query = Resort::isEnabled()->orderBy('last_checked_at')->orderBy('slug');
        if (!$this->option('all')) {
            $cutoff = Carbon::now()->subMinutes(self::FRESH_MINUTES);
            // A null time zone means the resort was last checked before snowfall was stored.
            $query->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<', $cutoff)->orWhereNull('time_zone'));
        }
        $resorts = $query->get();

        if ($this->option('dry-run')) {
            foreach ($resorts as $resort) {
                $this->line($resort->slug);
            }
            $this->info("[dry run] {$resorts->count()} resorts would be refreshed");
            return 0;
        }

        $report = new SnowReport();
        $refreshed = 0;
        $errors = 0;
        foreach ($resorts as $i => $resort) {
            if ($i > 0) {
                usleep(self::PAUSE_MICROSECONDS);
            }
            $lookup = $report->resolve(null, null, null, $resort->slug);
            if (!$lookup || isset($lookup['error'])) {
                $errors++;
                $this->warn("{$resort->slug}: " . ($lookup['error'] ?? 'no result'));
                continue;
            }
            $resort->newQuery()->whereKey($resort->getKey())->update(Resort::conditionsFrom($lookup['result']) + ['last_checked_at' => Carbon::now()]);
            $refreshed++;
        }

        $summary = "Refreshed {$refreshed} resorts, {$errors} errors";
        $this->info($summary);
        if ($errors) {
            Log::warning($summary);
        }
        return 0;
    }
}
