<?php

namespace App\Support\Ai\Sources;

use Illuminate\Support\Facades\Http;

/**
 * **زائرُ موقع المشروع** — «يدخل الرابط ويشوف المشروع» (docs/ai-hub/47 §العمود ج).
 *
 * يقرأ الصفحةَ الرئيسيّة وحتى `ai.sources_site_pages` صفحةً داخليّةً من روابطها **على النطاق نفسِه**،
 * ويستخرج العنوانَ والوصفَ والعناوينَ والنصَّ المرئيّ، ويسجّل الصفحاتِ المعطوبة. وبحرّاسٍ لا تُتجاوز:
 *  - **كلُّ طلبٍ يمرّ بـ`hub_outbound_ok`** (لا عناوينَ خاصّةً ولا داخليّة — SSRF)، ويُثبَّت على العنوان
 *    المُجاز (`hub_resolve_pin`) فلا يتقلّب DNS بين الفحص والاتصال.
 *  - **لا اتّباعَ آليّاً للتحويل:** يُتبَع يدوياً ثلاثَ قفزاتٍ على الأكثر، كلٌّ منها يُعاد فحصُها،
 *    وعلى النطاق نفسِه (أو `www.` منه) وحدَه.
 *  - **`robots.txt` محترَم** (`Disallow` لكلِّ الوكلاء)، و`text/html` وحدَه، وحجمٌ محدود، ومهلةٌ قصيرة.
 * ونصُّ الموقع **بياناتٌ غيرُ موثوقة** — يُسيَّج عند إرساله للنموذج ولا يُنفَّذ منه شيء.
 */
final class SiteReader
{
    public const UA = 'LynomiaHub-SiteReader/1.0';

    public const MAX_BYTES = 2 * 1024 * 1024;

    public const PAGE_CHARS = 15000;

    public const MAX_REDIRECTS = 3;

    public const TIMEOUT = 10;

    public static function maxPages(): int
    {
        return max(0, min(20, (int) setting('ai.sources_site_pages', 8)));
    }

    /**
     * @return array{status: string, http_status: ?int, error: ?string, pages: list<array>, meta: array, text: string}
     */
    public static function read(string $url): array
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') return self::fail('INVALID_URL');

        $rules = self::robots($url);
        if (in_array('/', $rules, true)) return ['status' => 'blocked', 'http_status' => null, 'error' => 'ROBOTS', 'pages' => [], 'meta' => [], 'text' => ''];

        $home = self::fetch($url, $host);
        if ($home['html'] === null) {
            return ['status' => 'failed', 'http_status' => $home['status'], 'error' => $home['error'], 'pages' => [$home['row']], 'meta' => [], 'text' => ''];
        }

        $parsed = self::parse($home['html'], $home['url']);
        $pages = [$home['row'] + ['title' => $parsed['title'], 'chars' => mb_strlen($parsed['text'])]];
        $texts = [self::block($home['url'], $parsed)];
        $broken = [];

        $seen = [self::key($home['url']) => true];
        foreach ($parsed['links'] as $link) {
            if (count($pages) > self::maxPages()) break;
            $k = self::key($link);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            if (self::disallowed($link, $rules)) continue;

            $p = self::fetch($link, $host);
            if ($p['html'] === null) {
                $pages[] = $p['row'];
                if (in_array($p['status'], [404, 410, 500, 502, 503], true)) $broken[] = ['url' => $link, 'status' => $p['status']];
                continue;
            }
            $pp = self::parse($p['html'], $p['url']);
            $pages[] = $p['row'] + ['title' => $pp['title'], 'chars' => mb_strlen($pp['text'])];
            $texts[] = self::block($p['url'], $pp);
        }

        return [
            'status' => 'ok', 'http_status' => $home['status'], 'error' => null, 'pages' => $pages,
            'meta' => ['title' => $parsed['title'], 'description' => $parsed['description'], 'lang' => $parsed['lang'], 'broken' => $broken],
            'text' => DocumentText::clean(implode("\n\n", $texts)),
        ];
    }

    /**
     * طلبٌ واحدٌ بحرّاسه — والتحويلُ يدويٌّ على النطاق نفسِه.
     *
     * @return array{html: ?string, url: string, status: ?int, error: ?string, row: array}
     */
    public static function fetch(string $url, string $host): array
    {
        $t0 = microtime(true);
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (! self::sameSite((string) parse_url($url, PHP_URL_HOST), $host)) return self::miss($url, null, 'OFFSITE_REDIRECT', $t0);
            $gate = hub_outbound_ok($url);
            if (! $gate['ok']) return self::miss($url, null, 'OUTBOUND_BLOCKED', $t0);

            try {
                $res = Http::withOptions(['allow_redirects' => false, 'curl' => hub_resolve_pin($url, $gate['ip'])])
                    ->timeout(self::TIMEOUT)->withHeaders(['User-Agent' => self::UA, 'Accept' => 'text/html'])->get($url);
            } catch (\Throwable $e) {
                return self::miss($url, null, 'CONNECT', $t0);
            }

            $status = $res->status();
            if ($status >= 300 && $status < 400 && ($loc = (string) $res->header('Location')) !== '') {
                $url = self::absolute($loc, $url);
                if ($url === null) return self::miss((string) $loc, $status, 'BAD_REDIRECT', $t0);
                continue;
            }
            if ($status >= 400) return self::miss($url, $status, 'HTTP_' . $status, $t0);
            if (! str_contains(strtolower((string) $res->header('Content-Type')), 'text/html')) return self::miss($url, $status, 'NOT_HTML', $t0);

            $body = (string) $res->body();
            if (strlen($body) > self::MAX_BYTES) $body = substr($body, 0, self::MAX_BYTES);

            return ['html' => $body, 'url' => $url, 'status' => $status, 'error' => null,
                'row' => ['url' => $url, 'status' => $status, 'ms' => (int) round((microtime(true) - $t0) * 1000)]];
        }

        return self::miss($url, null, 'TOO_MANY_REDIRECTS', $t0);
    }

    /**
     * صفحةٌ ⇐ عنوانٌ ووصفٌ ولغةٌ وعناوينُ ونصٌّ مرئيٌّ وروابطُ داخليّة. تعابيرُ نمطيّة لا `DOMDocument`
     * (HTML مشوّه لا يُسقطها، ولا كيانات خارجيّة).
     *
     * @return array{title: string, description: string, lang: string, headings: list<string>, text: string, links: list<string>}
     */
    public static function parse(string $html, string $base): array
    {
        $txt = fn (string $s) => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m);
        $title = mb_substr($txt($m[1] ?? ''), 0, 200);
        preg_match('#<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)#i', $html, $d);
        preg_match('#<html[^>]*\blang=["\']([a-zA-Z-]{2,10})#i', $html, $l);
        preg_match_all('#<h[1-3][^>]*>(.*?)</h[1-3]>#is', $html, $h);
        $headings = array_values(array_filter(array_map(fn ($x) => mb_substr($txt($x), 0, 160), $h[1] ?? [])));

        $links = [];
        preg_match_all('~<a\s[^>]*href=["\']([^"\'#]+)~i', $html, $a);
        foreach ($a[1] ?? [] as $href) {
            if (preg_match('#^(mailto|tel|javascript|data):#i', $href)) continue;
            if (preg_match('#\.(jpe?g|png|gif|svg|webp|pdf|zip|rar|mp4|mp3|css|js|ico|xml)(\?|$)#i', $href)) continue;
            $abs = self::absolute($href, $base);
            if ($abs !== null && self::sameSite((string) parse_url($abs, PHP_URL_HOST), strtolower((string) parse_url($base, PHP_URL_HOST)))) {
                $links[self::key($abs)] = $abs;
            }
        }

        $body = (string) preg_replace('#<(script|style|noscript|svg|template|iframe)\b[^>]*>.*?</\1>#is', ' ', $html);
        $body = (string) preg_replace('#<head\b[^>]*>.*?</head>#is', ' ', $body);
        $body = (string) preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr|/section|/article)\b[^>]*>#i', "\n", $body);
        $text = DocumentText::clean(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return ['title' => $title, 'description' => mb_substr($txt($d[1] ?? ''), 0, 300), 'lang' => strtolower($l[1] ?? ''),
            'headings' => array_slice($headings, 0, 30), 'text' => mb_substr($text, 0, self::PAGE_CHARS), 'links' => array_values($links)];
    }

    /** قواعدُ `Disallow` لكلِّ الوكلاء (`User-agent: *`) — مساراتٌ تبدأ بها الصفحاتُ الممنوعة */
    public static function robots(string $url): array
    {
        $root = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST)
            . (($port = parse_url($url, PHP_URL_PORT)) ? ':' . $port : '');
        $gate = hub_outbound_ok($root . '/robots.txt');
        if (! $gate['ok']) return [];
        try {
            $res = Http::withOptions(['allow_redirects' => false, 'curl' => hub_resolve_pin($root . '/robots.txt', $gate['ip'])])
                ->timeout(5)->withHeaders(['User-Agent' => self::UA])->get($root . '/robots.txt');
        } catch (\Throwable) {
            return [];
        }
        if (! $res->successful()) return [];

        $rules = [];
        $applies = false;
        foreach (preg_split('/\R/', mb_substr((string) $res->body(), 0, 100000)) as $line) {
            $line = trim((string) preg_replace('/#.*/', '', $line));
            if (preg_match('/^user-agent:\s*(.+)$/i', $line, $m)) {
                $agent = strtolower(trim($m[1]));
                $applies = $agent === '*' || str_contains(strtolower(self::UA), $agent);
            } elseif ($applies && preg_match('/^disallow:\s*(\S+)/i', $line, $m)) {
                $rules[] = $m[1];
            }
        }

        return array_values(array_unique($rules));
    }

    private static function disallowed(string $url, array $rules): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        foreach ($rules as $r) if ($r !== '' && str_starts_with($path, $r)) return true;

        return false;
    }

    /** النطاقُ نفسُه أو `www.` منه/إليه — لا نطاقاتٌ فرعيّةٌ أخرى ولا غريبة */
    public static function sameSite(string $a, string $b): bool
    {
        $n = fn (string $h) => preg_replace('/^www\./', '', strtolower($h));

        return $a !== '' && $n($a) === $n($b);
    }

    private static function absolute(string $href, string $base): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '') return null;
        if (preg_match('#^https?://#i', $href)) return $href;
        if (str_starts_with($href, '//')) return (parse_url($base, PHP_URL_SCHEME) ?: 'https') . ':' . $href;

        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($base, PHP_URL_HOST);
        if (! $host) return null;
        $port = parse_url($base, PHP_URL_PORT);
        $root = $scheme . '://' . $host . ($port ? ':' . $port : '');
        if (str_starts_with($href, '/')) return $root . $href;

        $dir = rtrim(str_contains((string) parse_url($base, PHP_URL_PATH), '/')
            ? substr((string) parse_url($base, PHP_URL_PATH), 0, strrpos((string) parse_url($base, PHP_URL_PATH), '/') + 1) : '/', '/') . '/';

        return $root . $dir . ltrim($href, './');
    }

    /** مفتاحُ الصفحة: بلا الجزء (#) وبلا الشرطة الأخيرة */
    private static function key(string $url): string
    {
        $u = strtolower((string) preg_replace('/#.*$/', '', $url));

        return rtrim((string) preg_replace('#^https?://(www\.)?#', '', $u), '/');
    }

    private static function block(string $url, array $p): string
    {
        return '## ' . ($p['title'] !== '' ? $p['title'] : $url) . "\n"
            . ($p['description'] !== '' ? $p['description'] . "\n" : '')
            . ($p['headings'] !== [] ? '• ' . implode(' • ', array_slice($p['headings'], 0, 12)) . "\n" : '')
            . $p['text'];
    }

    private static function miss(string $url, ?int $status, string $error, float $t0): array
    {
        return ['html' => null, 'url' => $url, 'status' => $status, 'error' => $error,
            'row' => ['url' => $url, 'status' => $status, 'error' => $error, 'ms' => (int) round((microtime(true) - $t0) * 1000)]];
    }

    private static function fail(string $error): array
    {
        return ['status' => 'failed', 'http_status' => null, 'error' => $error, 'pages' => [], 'meta' => [], 'text' => ''];
    }
}
