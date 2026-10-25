<?php namespace AlbrightLabs\CanTheyMakeSnow\Classes;

use Illuminate\Support\Facades\Http;

/**
 * IndexNow: tells Bing (and Yandex, Seznam, Naver) which pages changed so
 * they recrawl them without waiting. The key isn't a secret: the engines
 * fetch /{KEY}.txt from the site to confirm the submitter owns it.
 */
class IndexNow
{
    const KEY = 'ad3b79fecb19b5c3f0651273dcf8d338';
    const ENDPOINT = 'https://api.indexnow.org/indexnow';
    const HTTP_TIMEOUT = 15;
    const HTTP_CONNECT_TIMEOUT = 5;

    /**
     * Submit URLs on the site's own host (APP_URL).
     * Returns ['ok' => bool, 'status' => ?int, 'error' => ?string].
     * 200 means accepted; 202 means accepted while the key is still being checked.
     */
    public function submit(array $urls): array
    {
        $base = rtrim(url('/'), '/');
        try {
            $response = Http::acceptJson()
                ->connectTimeout(self::HTTP_CONNECT_TIMEOUT)
                ->timeout(self::HTTP_TIMEOUT)
                ->post(self::ENDPOINT, [
                    'host' => parse_url($base, PHP_URL_HOST),
                    'key' => self::KEY,
                    'keyLocation' => $base . '/' . self::KEY . '.txt',
                    'urlList' => array_values($urls),
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'error' => $e->getMessage()];
        }

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'error' => $response->successful() ? null : (trim($response->body()) ?: 'HTTP ' . $response->status()),
        ];
    }

    /**
     * Only a public host can be submitted; local dev hosts are skipped.
     */
    public static function isPublicHost(?string $host): bool
    {
        return (bool) $host && !preg_match('/^(localhost|127\.0\.0\.1|\[::1\])$|\.(test|local|localhost)$/i', $host);
    }
}
