<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * مركزُ الأهداف (WP-8.5 · spec §6.10 · §47) — **مصدرٌ واحدٌ للرقم**.
 *
 * كانت `/okrs` تحسب النسبةَ من نتائجها (`hub_okr_progress`)، وكانت
 * `/performance` تقرأ عمودَ `objectives.progress` المخزَّن — رقمان لهدفٍ واحدٍ
 * على شاشتين، وأحدُهما كاذبٌ حتماً (العمودُ لا يُكتب إلا بتثبيتٍ مأذونٍ من
 * قارئٍ غيرِ مقيَّد، فقد يتخلّف شهراً). هنا: الشاشتان تقرآن `hub_okr_board`
 * ولا شيءَ غيرَه، والعمودُ يبقى **أثرَ آخر تثبيت** لا مصدرَ عرض.
 *
 * وفوق اللوحة القائمة يضيف هذا الصنفُ ما تطلبه §47 ولا يملكه أحد: عدّاداتُ
 * «فات موعدَه · متعثّر · راكد»، وتسلسلٌ بالمستوى ثم المشروع (لا `parent_id`
 * في المخطّط — التسلسلُ من `level` و`project_id`)، ومالكٌ **باسمه**.
 */
class OkrCentre
{
    /**
     * عتبةُ الركود بالأيام — **عتبةُ `ExecutionStats::STALL_DAYS` نفسُها** لا
     * رقمٌ ثانٍ: «راكد» كلمةٌ واحدةٌ في النظام فلتكن لها عتبةٌ واحدة.
     */
    public const STALL_DAYS = ExecutionStats::STALL_DAYS;

    /** سقفُ صفوف التسلسل المعروضة في لوحة الأداء — يُعلَن للقارئ */
    public const LEVELS_CAP = 20;

    /** ترتيبُ المستويات = ترتيبُ خيارات السجل، وما خرج عنها يذيّل تحت «أخرى» */
    public const OTHER = 'أخرى';

    /**
     * اللوحةُ كاملةً: صفوفُ `hub_okr_board` مُغنّاةً بأعلامها ومالكِها ومشروعِها،
     * ثم العدّادات، ثم التسلسل. كلُّ الأرقام من الصفوف نفسِها — لا استعلامَ
     * عدٍّ ثانٍ يجيب رقماً يخالف الجدولَ تحته.
     */
    public static function board($user = null): array
    {
        $user = $user ?? auth()->user();
        $b = hub_okr_board($user);
        $rows = $b['rows'] ?? [];

        // الأسماءُ دفعةً واحدة: مالكٌ ومشروعٌ لكلّ الصفوف باستعلامين لا باستعلامٍ لكل صفّ
        $ownerIds = collect($rows)->map(fn ($r) => $r['o']->owner_id ?? null)->filter()->unique()->values()->all();
        $projIds = collect($rows)->map(fn ($r) => $r['o']->project_id ?? null)->filter()->unique()->values()->all();
        $owners = $ownerIds ? DB::table('users')->whereIn('id', $ownerIds)->orderBy('id')->pluck('name', 'id')->all() : [];
        $projects = $projIds ? DB::table('projects')->whereIn('id', $projIds)->orderBy('id')->pluck('name', 'id')->all() : [];

        /*
         * آخرُ قراءةٍ آليّة **من العمود المحفوظ** لا من صفوف اللوحة: اللوحةُ
         * تُحدّث `read_at` في الذاكرة عند كل فتحةٍ (`hub_okr_progress($refresh)`)
         * فيبدو كلُّ هدفٍ آليٍّ «قُرئ الآن» ولو لم يُثبَّت منذ شهر. والركودُ
         * سؤالٌ عن **آخر تثبيتٍ محفوظ** لا عن لحظة الرسم.
         * واستعلامٌ واحدٌ مُجمَّعٌ لكل الأهداف لا واحدٌ لكل صفّ.
         */
        $objIds = collect($rows)->map(fn ($r) => $r['o']->id)->values()->all();
        $lastReads = $objIds
            ? DB::table('key_results')->whereNull('deleted_at')->whereIn('objective_id', $objIds)
                ->groupBy('objective_id')
                ->pluck(DB::raw('max(read_at)'), 'objective_id')->all()
            : [];

        $today = now()->startOfDay();
        $out = [];
        foreach ($rows as $r) {
            $o = $r['o'];
            $p = $r['p'] ?? null;
            $pct = $p['pct'] ?? null;
            $closed = in_array((string) ($o->status ?? ''), hub_closed_states(), true);

            // ① فات موعدَه: تاريخُ الاستحقاق مضى والهدفُ لم يُغلق ولم يبلغ ١٠٠٪
            $overdue = ! $closed && $o->due
                && Carbon::parse($o->due)->startOfDay()->lt($today)
                && ($pct === null || $pct < 100);

            // ② متعثّر: **حالةٌ معلنة في السجل** لا استنتاج — من كتبها قصدها
            $blocked = (string) ($o->status ?? '') === 'متعثر';

            // ③ راكد: هدفٌ **يُقاس** ولم يُلمس قياسُه منذ العتبة. والمَلمَسُ بترتيب
            //    الصدق: آخرُ قراءةٍ آليّة لنتائجه، فآخرُ تثبيتٍ محسوب، فآخرُ تعديل.
            //    وهدفٌ بلا نتائجَ أصلاً **ليس راكداً — هو غيرُ مقيس**، ولا يُخلط
            //    البابان (البابُ الثاني معروضٌ في `unmeasured`).
            $lastRead = ($lr = $lastReads[$o->id] ?? null) ? Carbon::parse($lr) : null;
            $touched = $lastRead
                ?? ($o->computed_at ? Carbon::parse($o->computed_at) : null)
                ?? ($o->updated_at ? Carbon::parse($o->updated_at) : null);
            $stalled = ! $closed && $p !== null && $pct !== null
                && ($touched === null || $touched->lt(now()->subDays(self::STALL_DAYS)));

            $out[] = $r + [
                'pct' => $pct,
                'owner' => $o->owner_id ? ($owners[$o->owner_id] ?? null) : null,
                'project' => $o->project_id ? ($projects[$o->project_id] ?? null) : null,
                'level' => (string) ($o->level ?: self::OTHER),
                'closed' => $closed,
                'overdue' => (bool) $overdue,
                'blocked' => (bool) $blocked,
                'stalled' => (bool) $stalled,
                'touched' => $touched,
            ];
        }

        // `array_merge` لا `+`: العاملُ `+` يُبقي مفتاحَ اليسار، فكان `rows`
        // الأصليّةُ (بلا أعلامٍ ولا مالك) تغلب المُغنّاةَ صامتةً
        return array_merge($b, [
            'rows' => $out,
            'overdue' => count(array_filter($out, fn ($r) => $r['overdue'])),
            'blocked' => count(array_filter($out, fn ($r) => $r['blocked'])),
            'stalled' => count(array_filter($out, fn ($r) => $r['stalled'])),
            'stallDays' => self::STALL_DAYS,
            'levels' => self::levels($out),
            'attention' => self::attention($out),
        ]);
    }

    /**
     * التسلسل: المستوى أولاً بترتيب خيارات السجل، ثم المشروع داخله، ثم
     * الاستحقاق. والترتيبُ **حتميّ**: `due` تتساوى بين صفوف فيُحسم التساوي
     * بالمعرّف — وإلا اختلف ترتيبُ الشاشة بين المحرّكين وبَدَت القرعةُ ترتيباً.
     *
     * @return array<string, array<int, array>>
     */
    public static function levels(array $rows, ?int $cap = null): array
    {
        $order = self::levelOrder();
        $open = array_values(array_filter($rows, fn ($r) => ! $r['closed']));

        usort($open, function ($a, $b) use ($order) {
            $ra = $order[$a['level']] ?? count($order);
            $rb = $order[$b['level']] ?? count($order);

            return ($ra <=> $rb)
                ?: strcmp((string) ($a['project'] ?? ''), (string) ($b['project'] ?? ''))
                ?: strcmp((string) ($a['o']->due ?? '9999-12-31'), (string) ($b['o']->due ?? '9999-12-31'))
                ?: strcmp((string) $a['o']->id, (string) $b['o']->id);
        });

        $open = array_slice($open, 0, $cap ?? self::LEVELS_CAP);

        $out = [];
        foreach ($open as $r) $out[$r['level']][] = $r;

        return $out;
    }

    /** ما يحتاج نظرةً الآن: فات موعدُه أو متعثّرٌ أو راكد — بهذا الترتيب */
    public static function attention(array $rows): array
    {
        $flagged = array_values(array_filter($rows,
            fn ($r) => $r['overdue'] || $r['blocked'] || $r['stalled']));

        usort($flagged, fn ($a, $b) => (($b['overdue'] ? 1 : 0) <=> ($a['overdue'] ? 1 : 0))
            ?: (($b['blocked'] ? 1 : 0) <=> ($a['blocked'] ? 1 : 0))
            ?: strcmp((string) ($a['o']->due ?? '9999-12-31'), (string) ($b['o']->due ?? '9999-12-31'))
            ?: strcmp((string) $a['o']->id, (string) $b['o']->id));

        return $flagged;
    }

    /** رتبةُ كل مستوىً من خيارات السجل نفسِها — لا قائمةٌ ثانيةٌ تُصان يدوياً */
    protected static function levelOrder(): array
    {
        $def = hub_mod('okrs');
        $opts = (array) (collect($def['fields'] ?? [])->firstWhere('key', 'level')['options'] ?? []);
        $out = [];
        foreach (array_values($opts) as $i => $lvl) $out[(string) $lvl] = $i;
        $out[self::OTHER] = count($out);

        return $out;
    }
}
