<?php

namespace App\Support;

use App\Models\Attachment;
use App\Models\CodeRelease;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **الكود المصدري كما يُقرأ فعلاً: إصداراتٌ لا صفوفُ جدول.**
 *
 * وحدةُ «الكود المصدري» كانت جدولاً كبقيّة الجداول: صفٌّ لكل إصدارٍ فيه رقمُ
 * نسخةٍ وتاريخٌ وحالة، وسجلُّ التغييرات محشورٌ في خليةٍ مقصوصةٍ بعد ٣٠ حرفاً.
 * ومن أراد أن يعرف «ما الجديد في النسخة الأخيرة؟» فتح السجل، ونسخَ النصّ، ثم
 * قارن بالذاكرة مع سابقتها.
 *
 * والناسُ يعرفون شكلاً واحداً لهذا: **صفحةُ الإصدارات في GitHub** — أحدثُ
 * إصدارٍ متصدّرٌ بوسمه وشارة «الأحدث»، وتحته سجلُّ تغييراتٍ منسّق، وحِزَمُه
 * القابلة للتنزيل، وقبله تاريخُه ومن أصدره. فهذا ما تبنيه هذه الطبقة:
 *
 *   · **الوسمُ الدلاليّ**: `v3.2.0` يُفكَّك إلى (major.minor.patch) فيُعرف
 *     نوعُ القفزة من الرقم نفسِه لا من حقلٍ يُملأ باليد — وتُرتَّب الإصدارات
 *     ترتيباً دلالياً لا أبجدياً (`v10` بعد `v9` لا قبله).
 *   · **الحِزَم**: ملفُّ الإصدار ومرفقاتُه صارت «أصول التنزيل» بأحجامها وعدد
 *     تنزيلاتها — وهي في GitHub أهمُّ ما في الصفحة.
 *   · **الوتيرة**: متوسّطُ الأيام بين إصدارين، وعمرُ آخر إصدار — رقمان يقولان
 *     أحيٌّ المشروع أم توقّف.
 */
class CodeHub
{
    public const TTL = 120;

    /** استعلامُ الإصدارات بنطاق القارئ وشركته النشطة */
    public static function scoped()
    {
        return hub_company_scope(hub_scope(CodeRelease::query(), 'code'), 'code');
    }

    /**
     * تفكيكُ وسم النسخة: `v3.2.0-beta` → [3, 2, 0] مع اللاحقة.
     * ما لا يُفكَّك يبقى نصّاً ويُرتَّب بتاريخه — لا يسقط من القائمة.
     */
    public static function semver(?string $ver): array
    {
        $raw = trim((string) $ver);
        if (! preg_match('/(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:[-+ ]?([A-Za-z0-9._-]+))?/', $raw, $m)) {
            return ['ok' => false, 'major' => 0, 'minor' => 0, 'patch' => 0, 'pre' => null, 'raw' => $raw];
        }

        return [
            'ok' => true,
            'major' => (int) $m[1],
            'minor' => (int) ($m[2] ?? 0),
            'patch' => (int) ($m[3] ?? 0),
            'pre'   => ($m[4] ?? '') !== '' ? $m[4] : null,
            'raw'   => $raw,
        ];
    }

    /** حجمُ القفزة عن سابقتها — ما يقوله الرقمُ نفسُه عن التغيير */
    public static function bump(?string $ver, ?string $prev): ?string
    {
        if (! $ver || ! $prev) return null;
        $a = self::semver($ver);
        $b = self::semver($prev);
        if (! $a['ok'] || ! $b['ok']) return null;

        if ($a['major'] > $b['major']) return 'رئيسي';
        if ($a['minor'] > $b['minor']) return 'ميزات';
        if ($a['patch'] > $b['patch']) return 'إصلاحات';

        return null;
    }

    /**
     * إصداراتٌ جاهزةٌ للعرض: مرتّبةً دلالياً (الأحدثُ أولاً)، مع حِزَمها وقافزها
     * ومَن أصدرها. `$app` يحصرها بتطبيقٍ واحد، و`$project` بمشروع.
     */
    public static function releases(?string $appId = null, ?string $projectId = null, int $limit = 40): array
    {
        if (! hub_can(auth()->user(), 'code', 'v') || ! Schema::hasTable('code_releases')) return [];

        $q = self::scoped();
        if ($appId) $q->where('app_id', $appId);
        if ($projectId) $q->where('project_id', $projectId);

        // `date` تاريخٌ بلا وقتٍ فتتساوى قيمُه، و`created_at` بدقّة الثانية —
        // فالمفتاحُ فاصلُ التعادل الحاسم كي لا تُقرع القائمةُ بين المحرّكين.
        $rows = $q->orderByDesc('date')->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit)->get();
        if ($rows->isEmpty()) return [];

        // **ترتيبٌ دلاليّ**: `v10.0.0` بعد `v9.9.9` لا قبله — والترتيبُ الأبجديّ
        // يعكسهما. وعند تساوي الوسم يفصل التاريخُ ثم المفتاح.
        $sorted = $rows->sort(function ($a, $b) {
            $x = self::semver($a->ver);
            $y = self::semver($b->ver);
            $cmp = [$y['major'], $y['minor'], $y['patch']] <=> [$x['major'], $x['minor'], $x['patch']];
            if ($cmp !== 0) return $cmp;

            return [(string) $b->date, (string) $b->created_at, (string) $b->id]
                <=> [(string) $a->date, (string) $a->created_at, (string) $a->id];
        })->values();

        $users = DB::table('users')->whereIn('id', $sorted->pluck('created_by')->filter()->unique())
            ->pluck('name', 'id');
        $apps = hub_can(auth()->user(), 'apps', 'v')
            ? DB::table('applications')->whereIn('id', $sorted->pluck('app_id')->filter()->unique())
                ->pluck('name', 'id')
            : collect();

        $assets = self::assets($sorted->pluck('id')->all());

        $out = [];
        foreach ($sorted as $i => $r) {
            $prev = $sorted[$i + 1] ?? null;
            $out[] = [
                'row'    => $r,
                'id'     => $r->id,
                'ver'    => (string) ($r->ver ?: '—'),
                'sem'    => self::semver($r->ver),
                'latest' => $i === 0,
                'date'   => $r->date ? substr((string) $r->date, 0, 10) : null,
                'ago'    => $r->date ? Carbon::parse($r->date)->diffForHumans() : null,
                'by'     => $r->created_by ? ($users[$r->created_by] ?? '—') : '—',
                'app'    => $r->app_id ? ($apps[$r->app_id] ?? null) : null,
                'bump'   => self::bump($r->ver, $prev?->ver),
                'assets' => $assets[$r->id] ?? [],
                'tags'   => is_array($r->tags) ? $r->tags : (json_decode((string) $r->tags, true) ?: []),
                'notes'  => (string) ($r->notes ?? ''),
            ];
        }

        return $out;
    }

    /**
     * أصولُ التنزيل لكل إصدار: مرفقاتُه + ملفُّ الحزمة في حقل الوحدة.
     * استعلامٌ واحدٌ للجميع لا استعلامٌ لكل إصدار.
     */
    protected static function assets(array $ids): array
    {
        if (! $ids) return [];

        $out = [];
        foreach (Attachment::where('module', 'code')->whereIn('record_id', $ids)
            ->orderBy('sort')->orderBy('created_at')->orderBy('id')->get() as $a) {
            $out[$a->record_id][] = [
                'name' => (string) $a->original_name,
                'size' => (int) $a->size,
                'url'  => route('att.dl', $a->id),
                'n'    => (int) $a->downloads,
            ];
        }

        // حزمةُ الإصدار المرفوعة في حقل `file` — ملفٌّ على القرص لا مرفقٌ مسجَّل
        foreach (CodeRelease::whereIn('id', $ids)->whereNotNull('file_id')->get(['id', 'file_id', 'ver']) as $r) {
            $out[$r->id][] = [
                'name' => 'حزمة ' . ($r->ver ?: 'الإصدار'),
                'size' => null,
                'url'  => route('file.show', ['path' => $r->file_id, 'dl' => 1]),
                'n'    => null,
            ];
        }

        return $out;
    }

    /** وتيرةُ الإصدار: كم بينهما من يوم، وكم مضى على الأخير — أحيٌّ المشروع؟ */
    public static function cadence(array $releases): array
    {
        $dates = collect($releases)->pluck('date')->filter()->values();
        $gaps = [];
        for ($i = 0; $i + 1 < $dates->count(); $i++) {
            $gaps[] = (int) Carbon::parse($dates[$i + 1])->diffInDays(Carbon::parse($dates[$i]));
        }

        $last = $dates->first();
        $age = $last ? (int) Carbon::parse($last)->startOfDay()->diffInDays(now()->startOfDay()) : null;

        return [
            'n'    => count($releases),
            'avg'  => $gaps ? (int) round(array_sum($gaps) / count($gaps)) : null,
            'last' => $last,
            'age'  => $age,
            'tone' => $age === null ? 'g' : ($age > 180 ? 'bad' : ($age > 90 ? 'wn' : 'ok')),
        ];
    }

    /**
     * **سجلُّ التغييرات بصيغة Markdown خفيفة** — كما يُكتب في GitHub فعلاً.
     *
     * كان النصُّ يُطبع خاماً بأسطرٍ ملتصقة، فقائمةُ التغييرات تصير فقرةً واحدة.
     * المدعومُ هنا ما يُكتب فعلاً في سجلات التغيير: العناوين، والقوائم،
     * والغامق، والشيفرة المضمّنة، والروابط. **والهروبُ أولاً**: النصُّ يكتبه
     * مستخدمٌ ويُطبع في صفحةٍ لغيره، فلا وسمَ يمرّ إلا ما نبنيه نحن.
     */
    public static function notesHtml(?string $text): string
    {
        $t = trim((string) $text);
        if ($t === '') return '';

        $esc = e($t);
        $out = [];
        $list = false;

        foreach (preg_split('/\r?\n/', $esc) as $line) {
            $l = rtrim($line);

            if (preg_match('/^\s*(?:[-*•]|\d+[.)])\s+(.*)$/u', $l, $m)) {
                if (! $list) { $out[] = '<ul>'; $list = true; }
                $out[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }
            if ($list) { $out[] = '</ul>'; $list = false; }

            if (preg_match('/^\s*#{1,4}\s*(.+)$/u', $l, $m)) {
                $out[] = '<h4>' . self::inline($m[1]) . '</h4>';
            } elseif (trim($l) === '') {
                $out[] = '';
            } else {
                $out[] = '<p>' . self::inline($l) . '</p>';
            }
        }
        if ($list) $out[] = '</ul>';

        return implode("\n", array_filter($out, fn ($x) => $x !== ''));
    }

    /** تنسيقُ السطر: غامقٌ وشيفرةٌ ورابط — على نصٍّ **مُهرَّبٍ سلفاً** */
    protected static function inline(string $s): string
    {
        $s = preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $s);
        $s = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $s);
        // الروابطُ العارية وحدها، وبـhttps/http فقط — لا `javascript:` تمرّ
        $s = preg_replace('#(?<!["\'>])\b(https?://[^\s<]+)#u',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>', $s);

        return $s;
    }
}
