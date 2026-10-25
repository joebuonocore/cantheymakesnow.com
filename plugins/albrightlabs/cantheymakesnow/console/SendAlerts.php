<?php namespace AlbrightLabs\CanTheyMakeSnow\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use AlbrightLabs\CanTheyMakeSnow\Classes\AlertRules;
use AlbrightLabs\CanTheyMakeSnow\Classes\SnowReport;
use AlbrightLabs\CanTheyMakeSnow\Classes\Units;
use AlbrightLabs\CanTheyMakeSnow\Classes\WeatherService;
use AlbrightLabs\CanTheyMakeSnow\Models\Subscriber;
use AlbrightLabs\CanTheyMakeSnow\Models\Subscription;

/**
 * Hourly: check the forecast for every resort with active subscriptions and
 * email each subscriber one message covering everything new for them.
 * Uses cached NOAA data only (no Google calls). Run with --dry-run to see
 * what would be sent without sending or recording anything.
 */
class SendAlerts extends Command
{
    protected $signature = 'ctms:send-alerts
        {--dry-run : List alerts without sending or recording them}
        {--resort= : Only this resort slug}
        {--email= : Only this subscriber}
        {--force : Ignore quiet hours and spacing (for testing; still records what was sent)}';

    protected $description = 'Email snowmaking and powder alerts to subscribers';

    const LAST_RUN_KEY = 'ctms:alerts:last_run';

    protected AlertRules $rules;
    protected WeatherService $weather;

    public function handle()
    {
        $this->rules = new AlertRules();
        $this->weather = new WeatherService();
        $now = Carbon::now('UTC');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        // Links in emails come from APP_URL when run from the scheduler.
        if (app()->environment('production') && preg_match('#//(localhost|127\.0\.0\.1)#', (string) config('app.url'))) {
            Log::warning('APP_URL points at localhost; alert email links will be wrong.');
            $this->warn('APP_URL points at localhost; alert email links will be wrong.');
        }

        $query = Subscription::active()->with(['subscriber', 'resort']);
        if ($slug = $this->option('resort')) {
            $query->whereHas('resort', fn ($q) => $q->where('slug', $slug));
        }
        if ($email = $this->option('email')) {
            $query->whereHas('subscriber', fn ($q) => $q->where('email', strtolower($email)));
        }
        $subscriptions = $query->get();

        $stats = ['at' => $now->toIso8601String(), 'resorts' => 0, 'subscriptions' => $subscriptions->count(), 'alerts' => 0, 'emails' => 0, 'errors' => 0, 'quiet' => 0];
        $bySubscriber = [];
        $report = new SnowReport();

        foreach ($subscriptions->groupBy('resort_id') as $resortSubscriptions) {
            $resort = $resortSubscriptions->first()->resort;
            $stats['resorts']++;

            // The full resort lookup, so base and summit are both checked.
            $lookup = $report->resolve(null, null, null, $resort->slug);
            if (!$lookup || isset($lookup['error'])) {
                $this->warn("{$resort->slug}: " . ($lookup['error'] ?? 'no result'));
                $stats['errors']++;
                continue;
            }
            $result = $lookup['result'];
            $this->recordConditions($resort, $result, $now, $dryRun);

            $timeZone = $result['timeZone'];
            if (!$force && $this->rules->isQuietHours($now, $timeZone)) {
                $stats['quiet']++;
                continue;
            }

            foreach ($resortSubscriptions as $subscription) {
                $hit = $this->evaluate($subscription, $result, $now, $force);
                if ($hit) {
                    $bySubscriber[$subscription->subscriber_id][] = $hit + ['subscription' => $subscription, 'resort' => $resort, 'timeZone' => $timeZone];
                    $stats['alerts']++;
                }
            }
        }

        foreach ($bySubscriber as $hits) {
            $subscriber = $hits[0]['subscription']->subscriber;
            $email = $this->compose($subscriber, $hits, $now);
            $this->line(($dryRun ? '[dry run] ' : '') . "{$subscriber->email}: {$email['subject']}");
            if ($dryRun) {
                continue;
            }
            try {
                $this->send($subscriber, $email);
                $this->record($subscriber, $hits, $now);
                $stats['emails']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('Alert email failed', ['subscriber' => $subscriber->id, 'error' => $e->getMessage()]);
                $this->error("{$subscriber->email}: {$e->getMessage()}");
            }
        }

        $summary = "Alerts: {$stats['resorts']} resorts, {$stats['subscriptions']} subscriptions, {$stats['alerts']} alerts, {$stats['emails']} emails, {$stats['errors']} errors, {$stats['quiet']} in quiet hours";
        $this->info(($dryRun ? '[dry run] ' : '') . $summary);
        if (!$dryRun) {
            Cache::put(self::LAST_RUN_KEY, $stats, 7 * 86400);
            Log::info($summary);
        }
        return 0;
    }

    /**
     * Which alerts (if any) one subscription should get from this report.
     */
    protected function evaluate(Subscription $sub, array $result, Carbon $now, bool $force): ?array
    {
        $hit = [];

        if ($sub->snowmaking) {
            // Base and summit each get a say; the earliest qualifying window wins.
            $zones = $result['zones'] ?? ['here' => $result];
            foreach ($zones as $key => $zone) {
                $window = $this->rules->snowmakingAlert(
                    $zone['windowList'] ?? [],
                    $sub->snowmaking_min_tier,
                    $force ? null : $sub->last_snowmaking_start,
                    $force ? null : $sub->last_snowmaking_end,
                    $force ? null : $sub->last_snowmaking_sent_at,
                    $now
                );
                if ($window && (!isset($hit['snowmaking']) || $window['start']->timestamp < $hit['snowmaking']['start']->timestamp)) {
                    $hit['snowmaking'] = $window + ['zone' => count($zones) > 1 ? strtolower($zone['label']) : null];
                }
            }
        }

        if ($sub->powder && !empty($result['snow']['storms'])) {
            $powder = $this->rules->powderAlert(
                $result['snow']['storms'],
                (int) $sub->powder_threshold_in,
                $force ? null : $sub->last_powder_start,
                $force ? null : $sub->last_powder_end,
                $force || $sub->last_powder_in === null ? null : (float) $sub->last_powder_in,
                $force ? null : $sub->last_powder_sent_at,
                $now
            );
            if ($powder) {
                $hit['powder'] = $powder;
            }
        }

        return $hit ?: null;
    }

    /**
     * Subject and per-resort lines for one subscriber, in their units and
     * each resort's local time.
     */
    protected function compose(Subscriber $subscriber, array $hits, Carbon $now): array
    {
        $u = $subscriber->units === 'c' ? 'c' : 'f';
        $items = [];
        $subjects = [];

        foreach ($hits as $hit) {
            $resort = $hit['resort'];
            $localNow = $now->copy()->setTimezone($hit['timeZone']);
            $local = fn (Carbon $t) => $this->weather->relativeTime($t->copy()->setTimezone($hit['timeZone']), $localNow);
            $lines = [];

            if (isset($hit['powder'])) {
                $storm = $hit['powder']['storm'];
                $amount = Units::snow(round($storm['totalIn']), $u);
                $lines[] = $hit['powder']['update']
                    ? "Powder update: now {$amount} of new snow expected (was " . Units::snow(round($hit['powder']['previousIn']), $u) . "), {$local($storm['start'])} – {$local($storm['end'])}."
                    : "Powder: {$amount} of new snow expected, {$local($storm['start'])} – {$local($storm['end'])}.";
                $subjects[] = "❄ Powder at {$resort->name}: {$amount} by {$local($storm['end'])}";
            }

            if (isset($hit['snowmaking'])) {
                $window = $hit['snowmaking'];
                $coldest = Units::temp($window['coldestWetBulbC'], $u);
                $at = $window['zone'] ? " at the {$window['zone']}" : '';
                $lines[] = $window['start']->timestamp <= $now->timestamp
                    ? "They can make snow{$at} now, until {$local($window['end'])} (coldest wet-bulb {$coldest})."
                    : "Snowmaking window{$at}: {$local($window['start'])} – {$local($window['end'])} (coldest wet-bulb {$coldest}).";
                $subjects[] = "Snowmaking " . ($window['start']->timestamp <= $now->timestamp ? 'now' : $local($window['start'])) . " at {$resort->name}" . ($window['zone'] ? " ({$window['zone']})" : '');
            }

            $items[] = [
                'resort' => $resort->label,
                'lines' => $lines,
                'url' => rtrim(url('/'), '/') . '/?' . http_build_query(['resort' => $resort->slug]),
            ];
        }

        $subject = count($subjects) <= 2
            ? implode(' · ', $subjects)
            : 'Snow alerts for ' . implode(', ', array_slice(array_unique(array_map(fn ($h) => $h['resort']->name, $hits)), 0, 2)) . ' and more';

        return ['subject' => $subject, 'items' => $items];
    }

    protected function send(Subscriber $subscriber, array $email): void
    {
        $oneClick = url('alerts/unsubscribe-now/' . $subscriber->token);
        Mail::send('albrightlabs.cantheymakesnow::mail.alert', [
            'subject' => $email['subject'],
            'items' => $email['items'],
            'manageUrl' => $subscriber->manageUrl(),
            'unsubscribeUrl' => $subscriber->unsubscribeUrl(),
        ], function ($message) use ($subscriber, $oneClick) {
            $message->to($subscriber->email);
            $headers = $message->getHeaders();
            $headers->addTextHeader('List-Unsubscribe', "<{$oneClick}>");
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });
    }

    /**
     * Remember what was sent so the same window or storm isn't repeated.
     */
    protected function record(Subscriber $subscriber, array $hits, Carbon $now): void
    {
        foreach ($hits as $hit) {
            $sub = $hit['subscription'];
            if (isset($hit['snowmaking'])) {
                $sub->last_snowmaking_start = $hit['snowmaking']['start'];
                $sub->last_snowmaking_end = $hit['snowmaking']['end'];
                $sub->last_snowmaking_sent_at = $now;
            }
            if (isset($hit['powder'])) {
                $sub->last_powder_start = $hit['powder']['storm']['start'];
                $sub->last_powder_end = $hit['powder']['storm']['end'];
                $sub->last_powder_in = round($hit['powder']['storm']['totalIn'], 1);
                $sub->last_powder_sent_at = $now;
            }
            $sub->save();
        }
        $subscriber->last_emailed_at = $now;
        $subscriber->save();
    }

    /**
     * Keep the resort's map pin fresh while we have the forecast anyway.
     */
    protected function recordConditions($resort, array $result, Carbon $now, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        $resort->newQuery()->whereKey($resort->getKey())->update([
            'last_wet_bulb' => round($result['answer']['wetBulbC'], 2),
            'last_tier' => $result['answer']['tier'],
            'last_checked_at' => $now,
        ]);
    }
}
