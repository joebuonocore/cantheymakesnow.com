<?php namespace AlbrightLabs\CanTheyMakeSnow\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use AlbrightLabs\CanTheyMakeSnow\Classes\IndexNow;
use AlbrightLabs\CanTheyMakeSnow\Classes\Sitemap;

/**
 * Daily: tell IndexNow search engines (Bing and others) to recrawl the home
 * page and the resort pages, whose forecasts change all the time. The other
 * pages rarely change, so they're only sent with --all.
 */
class SubmitIndexNow extends Command
{
    protected $signature = 'ctms:indexnow
        {--all : Submit every page in the sitemap}
        {--dry-run : List the URLs without submitting them}';

    protected $description = 'Ask Bing and other IndexNow search engines to recrawl the site';

    public function handle()
    {
        $urls = $this->option('all')
            ? Sitemap::urls()
            : array_merge([rtrim(url('/'), '/') . '/'], Sitemap::resortUrls());

        if ($this->option('dry-run')) {
            foreach ($urls as $url) {
                $this->line($url);
            }
            $this->info(count($urls) . ' URLs (not submitted)');
            return 0;
        }

        $host = parse_url(url('/'), PHP_URL_HOST);
        if (!IndexNow::isPublicHost($host)) {
            $this->warn("APP_URL host {$host} isn't public; nothing submitted.");
            return 0;
        }

        $result = (new IndexNow())->submit($urls);
        if (!$result['ok']) {
            Log::warning('IndexNow submission failed', $result);
            $this->error("IndexNow failed ({$result['status']}): {$result['error']}");
            return 1;
        }

        $this->info('Submitted ' . count($urls) . " URLs to IndexNow (HTTP {$result['status']}).");
        return 0;
    }
}
