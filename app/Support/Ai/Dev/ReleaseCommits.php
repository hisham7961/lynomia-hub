<?php

namespace App\Support\Ai\Dev;

use Illuminate\Support\Facades\Http;

/**
 * **التزاماتُ الإصدار من GitHub** (المرحلة ٥) — ما بين التزام الإصدار السابق والتزام هذا الإصدار، بواجهة
 * `compare` لـGitHub. قراءةٌ فقط، والمضيفُ **ثابتٌ** (`api.github.com`) — فلا يصير حقلُ «المستودع» بابَ SSRF:
 * يُقبل منه `github.com/{مالك}/{مستودع}` بمحارفَ مسمّاة وحدَه، والالتزامان بمحارفِ مراجعِ git فقط.
 *
 * الرمزُ (`dev.github_token`) مشفَّرٌ في الإعدادات كمفتاح البوّابة، ولا يلزم للمستودع العامّ. وأيُّ إخفاقٍ
 * (لا رمز لمستودعٍ خاصّ · حدُّ المعدّل · شبكة) يعود إلى اللصق اليدويّ بسببٍ يُقال — لا بسجلٍّ مختلَق.
 */
final class ReleaseCommits
{
    public const HOST = 'https://api.github.com';

    public const MAX = 200;

    public static function enabled(): bool
    {
        return (string) setting('dev.github_commits', '0') === '1';
    }

    /** `[owner, repo]` من رابط github.com — أو `null` */
    public static function repo(?string $url): ?array
    {
        $url = trim((string) $url);
        if (! preg_match('#^(?:https?://)?(?:www\.)?github\.com/([A-Za-z0-9_.-]{1,100})/([A-Za-z0-9_.-]{1,100}?)(?:\.git)?/?$#', $url, $m)) return null;
        if (in_array($m[1], ['.', '..'], true) || in_array($m[2], ['.', '..'], true)) return null;

        return [$m[1], $m[2]];
    }

    /** مرجعٌ صالح (sha أو وسم أو فرع) — لا فراغَ ولا `..` ولا محارفَ مسار */
    public static function ref(?string $v): ?string
    {
        $v = trim((string) $v);

        return preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$#', $v) && ! str_contains($v, '..') ? $v : null;
    }

    /**
     * @return array{ok: bool, lines: list<string>, total: int, why: ?string}
     */
    public static function between(string $repoUrl, string $base, string $head): array
    {
        $out = ['ok' => false, 'lines' => [], 'total' => 0, 'why' => null];
        if (! self::enabled()) return ['why' => 'جلبُ الالتزامات من GitHub مطفأ (dev.github_commits)'] + $out;
        $repo = self::repo($repoUrl);
        [$b, $h] = [self::ref($base), self::ref($head)];
        if ($repo === null) return ['why' => 'رابطُ المستودع ليس github.com/مالك/مستودع'] + $out;
        if ($b === null || $h === null) return ['why' => 'ينقص التزامُ الإصدار أو الإصدارِ السابق'] + $out;

        $url = self::HOST . '/repos/' . rawurlencode($repo[0]) . '/' . rawurlencode($repo[1])
            . '/compare/' . rawurlencode($b) . '...' . rawurlencode($h);
        $token = trim((string) setting('dev.github_token', ''));
        try {
            $req = Http::acceptJson()->timeout(10)->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])->withoutRedirecting();
            if ($token !== '') $req = $req->withToken($token);
            $res = $req->get($url);
        } catch (\Throwable) {
            return ['why' => 'تعذّر الوصولُ إلى GitHub'] + $out;
        }
        if (! $res->successful()) {
            return ['why' => match ($res->status()) {
                401, 403 => $token === '' ? 'المستودعُ يحتاج رمزاً (dev.github_token) أو بلغ حدَّ المعدّل' : 'الرمزُ مرفوضٌ أو بلغ حدَّ المعدّل',
                404 => 'المستودعُ أو أحدُ الالتزامين غيرُ موجود' . ($token === '' ? ' (أو خاصٌّ يحتاج رمزاً)' : ''),
                default => 'ردَّ GitHub بـ' . $res->status(),
            }] + $out;
        }

        $lines = [];
        foreach (array_slice((array) $res->json('commits', []), -self::MAX) as $c) {
            $msg = strtok((string) ($c['commit']['message'] ?? ''), "\n");
            if ($msg === false || trim($msg) === '') continue;
            $lines[] = substr((string) ($c['sha'] ?? ''), 0, 7) . ' ' . mb_substr(trim($msg), 0, 200);
        }

        return ['ok' => true, 'lines' => $lines, 'total' => (int) $res->json('total_commits', count($lines)), 'why' => null];
    }
}
