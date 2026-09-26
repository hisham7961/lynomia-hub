<?php

namespace App\Support\Ai\Auditor;

use App\Models\AiFinding;
use App\Models\SignalState;
use App\Models\User;
use App\Models\WorkUpdate;
use App\Support\Workforce\ReportReview;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **نتائجُ المدقّق إشاراتٍ في مركز الفعل — مُعادةَ التنطيق للمشاهد** (§٣.٢ · §٣.٣).
 *
 * المدقّقُ قرأ بهويّةٍ واسعةِ النطاق؛ فاتّساعُ قراءته لا يصير اتّساعَ رؤيةٍ لأحد.
 * نتيجةٌ تُعرَض لمشاهدٍ **فقط** إن اجتمعت خمسة:
 *
 *  ① **كلُّ سجلٍّ في شاهدها** يراه بـ`hub_can` + `hub_scope` (شركةٌ وعميلٌ ومشروعٌ معاً)
 *     — فالعددُ في الملخّص نفسُه لا يكشف سجلّاً محجوباً عنه.
 *  ② **كلُّ حقلٍ قرأه كاشفُها** غيرُ محجوبٍ عنه بـ`hub_field_mode` — فالملخّصُ قد يحمل قيمتَه.
 *  ③ **ليست حكماً على عمله هو** — صاحبُ العمل (`subject_user_id`) لا يرى حكمَ المدقّقِ
 *     الخامَ عليه (قرارُ المالك §٣.٦)؛ يصله ما اعتمده مديرُه. والمالكُ وحده مستثنى —
 *     كما يستثنيه `ReportReview::canReview` نفسُه.
 *  ④ **بوّابةُ الموضوع:** نتيجةٌ على تقريرٍ يوميٍّ لمن يملك **مراجعتَه**
 *     (`ReportReview::canReview`)؛ وعلى سواه لمن يملك تعديلَ الوحدة.
 *  ⑤ ليس حسابَ عميل.
 *
 * **والسقفُ بعد الترشيح لا قبله:** تُقرأ النتائجُ صفحةً صفحة (مفتاحاً لا إزاحة) حتى يجتمع
 * للمشاهد `MAX` ممّا يراه — فسقفٌ عامٌّ قبل الترشيح كان سيُري مراجعاً في شركةٍ أخرى
 * صفّاً فارغاً وله فيه نتائج، ويرفض تصرّفَه بها.
 */
final class AuditorSignals
{
    /** سقفُ ما يُعرَض — نتائجُ أكثرُ من هذا ضجيجٌ لا صفُّ عمل */
    public const MAX = 60;

    /** حجمُ الصفحة وسقفُ الصفحات — كلفةُ العرضِ محدودةٌ مهما كثرت النتائج */
    public const PAGE = 120;

    public const MAX_PAGES = 10;

    /**
     * نوعُ الإشارة — **ليس** `audit`: ذاك في `AttentionQueue::TYPES` نوعُ سلامةِ سلسلةِ
     * التدقيق (حالةُ نظام)، فيُساق إلى مستوى التحكّم ويُعَدّ «حالةَ نظام». وحكمٌ على عملِ
     * فريقٍ إشارةُ أعمالٍ لا حالةُ نظام.
     */
    public const TYPE = 'auditor';

    public const TYPE_LABEL = [self::TYPE => 'المدقّق'];

    /**
     * شدّةُ النتيجة ⇒ شدّةُ الإشارة. **ولا «حرج» أبداً:** المدقّقُ يشير ولا يحكم، والحرجُ
     * في مركز الفعل لا يُرفَض — ونتيجةُ مدقّقٍ يجب أن يستطيع المديرُ رفضَها دائماً.
     */
    public const SEV = ['high' => 'مهم', 'medium' => 'مهم', 'info' => 'اطّلاع'];

    /** مهلةُ الخبيئة — والختمُ يسبقها: جولةٌ جديدةٌ أو تغيّرُ دورٍ يُبطلها فوراً */
    public const TTL = 120;

    /** @return list<array> بشكل `hub_recommendations` */
    public static function visibleTo(?User $u, ?string $projectId = null, bool $fresh = false): array
    {
        if (! $u || hub_is_client($u) || ! Auditor::enabled() || ! Schema::hasTable('ai_findings')) return [];

        $key = 'auditor:sig:' . $u->id . ':' . ($projectId ?? '-') . ':' . md5(implode(',', AuditorAccuracy::disabled()))
            . hub_data_stamp(['ai_findings', 'roles', 'users', 'work_updates', 'tasks', 'decisions', 'meetings']);

        return hub_cached($key, self::TTL, $fresh, fn () => self::compute($u, $projectId));
    }

    /**
     * **ملاحظاتُ المدقّق على تقاريرَ بعينها — لشاشةِ المراجعة** (§٣.٤ · A3).
     *
     * لكلِّ تقريرٍ: ما يراه هذا المراجعُ من نتائجَ مفتوحةٍ موضوعُها التقرير (بالشروطِ الخمسةِ
     * نفسِها)، ومسودةُ ملاحظةٍ للموظّف إن كانت. **والمسودةُ لا تصل الموظّفَ بذاتها** — تُعبّأ في
     * حقلِ ملاحظةِ المراجعة، والمراجعُ يحرّرها ويرسلها بـ«طلب تنقيح» أو يتركها.
     *
     * @param  iterable<\App\Models\WorkUpdate>  $reports
     * @return array<string, list<array{summary: string, label: string, ai: bool, note: ?string}>>
     */
    public static function notesFor(User $u, iterable $reports): array
    {
        if (hub_is_client($u) || ! Auditor::enabled() || ! Schema::hasTable('ai_findings')) return [];
        $ids = [];
        foreach ($reports as $w) $ids[] = (string) $w->id;
        if ($ids === []) return [];

        $q = AiFinding::query()->where('status', 'open')->where('subject_module', 'updates')
            ->whereIn('subject_id', $ids)->orderBy('detected_at')->orderBy('id');
        if (($off = AuditorAccuracy::disabled()) !== []) $q->whereNotIn('detector', $off);

        $labels = [];
        foreach (Auditor::detectors() as $d) $labels[$d->key()] = $d->label();

        $rows = self::filter($u, $q->get(), null);
        $hide = self::hiddenKeys(array_map(fn ($f) => $f->signalKey(), $rows));

        $out = [];
        foreach ($rows as $f) {
            if (isset($hide[$f->signalKey()])) continue;   // رفضها مديرٌ أو أجّلها ⇒ لا تعود من هنا
            $out[$f->subject_id][] = [
                'summary' => $f->summary,
                'label' => $labels[$f->detector] ?? $f->detector,
                'ai' => $f->source === 'ai',
                'note' => is_string($f->draft['note'] ?? null) ? $f->draft['note'] : null,
            ];
        }

        return $out;
    }

    /** مسوداتُ الملاحظة المفتوحة على تقريرٍ — لحارسِ «القبول» (لا عرضَ هنا) @return list<string> */
    public static function draftNotes(WorkUpdate $w): array
    {
        if (! Schema::hasTable('ai_findings')) return [];

        return AiFinding::query()->where('subject_module', 'updates')->where('subject_id', (string) $w->id)
            ->where('status', 'open')->orderBy('id')->get()
            ->map(fn ($f) => is_string($f->draft['note'] ?? null) ? trim($f->draft['note']) : null)
            ->filter()->values()->all();
    }

    /**
     * **ما يخفيه تصرّفُ المديرين** — رفضٌ أو تأجيلٌ لم ينقضِ. تقرؤه كلُّ قراءةٍ غيرِ صفِّ مركز الفعل
     * (صفحةُ المراجعة، الملخّص) فلا يعود ما أُخفي هناك من بابٍ آخر.
     *
     * @param  list<string>  $keys
     * @return array<string, true>
     */
    public static function hiddenKeys(array $keys): array
    {
        if ($keys === [] || ! Schema::hasTable('signal_states')) return [];
        $out = [];
        foreach (SignalState::query()->whereIn('skey', $keys)->get() as $st) {
            if ($st->hidesNow()) $out[$st->skey] = true;
        }

        return $out;
    }

    /** إشاراتُ المدقّق **الظاهرةُ** لمشاهد — بعد إسقاطِ المرفوضِ والمؤجَّل (للملخّص الأسبوعيّ) */
    public static function openFor(User $u): array
    {
        $all = self::visibleTo($u, null, true);
        $hide = self::hiddenKeys(array_values(array_filter(array_column($all, 'key'))));

        return array_values(array_filter($all, fn ($s) => ! isset($hide[$s['key'] ?? ''])));
    }

    /** هل يرى هذا المشاهدُ هذه النتيجةَ بعينها؟ (للاختبار ولأيِّ بابِ قراءةٍ آخر) */
    public static function canSee(User $u, AiFinding $f): bool
    {
        return ! hub_is_client($u) && self::filter($u, collect([$f]), null) !== [];
    }

    private static function compute(User $u, ?string $projectId): array
    {
        $labels = [];
        foreach (Auditor::detectors() as $d) $labels[$d->key()] = $d->label();

        $out = [];
        $cursor = null;
        for ($page = 0; $page < self::MAX_PAGES && count($out) < self::MAX; $page++) {
            $rows = self::page($u, $cursor);
            if ($rows->isEmpty()) break;
            $last = $rows->last();
            $cursor = [$last->detected_at, (string) $last->id];

            foreach (self::filter($u, $rows, $projectId) as $f) {
                if (count($out) >= self::MAX) break;
                $out[] = self::signal($f, $labels, $u);
            }
            if ($rows->count() < self::PAGE) break;
        }

        return $out;
    }

    /**
     * صفحةٌ من النتائج المفتوحة — الأحدثُ رصداً أوّلاً، والمعرّفُ يكسر التعادل.
     * **وتضييقٌ رخيصٌ بالشركة قبل السقف** (ليس الحكمَ — الحكمُ في `filter`): من له قائمةُ
     * شركاتٍ لا يُقرأ له ما خارجها أصلاً.
     */
    private static function page(User $u, ?array $cursor): Collection
    {
        $q = AiFinding::query()->where('status', 'open')
            ->orderByDesc('detected_at')->orderBy('id')->limit(self::PAGE);
        // كاشفٌ أُطفئ (لكثرة الرفض أو بيد المالك) تُخفى نتائجُه معه — وتعود إن أُعيد
        if (($off = AuditorAccuracy::disabled()) !== []) $q->whereNotIn('detector', $off);
        if (($cids = hub_company_ids($u)) !== null) {
            $q->where(fn ($w) => $w->whereIn('company_id', $cids)->orWhereNull('company_id'));
        }
        if ($cursor !== null) {
            [$at, $id] = $cursor;
            $q->where(fn ($w) => $w->where('detected_at', '<', $at)
                ->orWhere(fn ($x) => $x->where('detected_at', $at)->where('id', '>', $id)));
        }

        return $q->get();
    }

    /** الشروطُ الخمسة على دفعةٍ واحدة — باستعلامٍ لكلِّ وحدةٍ لا لكلِّ نتيجة @return list<AiFinding> */
    private static function filter(User $u, Collection $rows, ?string $projectId): array
    {
        if ($rows->isEmpty()) return [];

        $owner = (bool) $u->role?->is_owner;
        $visible = self::visibleEvidence($u, $rows);
        $reports = self::reports($rows);
        $projects = $projectId !== null ? self::subjectProjects($rows) : [];
        $reviewMemo = [];

        $out = [];
        foreach ($rows as $f) {
            if ($projectId !== null && ($projects[$f->subject_module . ':' . $f->subject_id] ?? null) !== $projectId) continue;
            if (! $owner && $f->subject_user_id !== null && (string) $f->subject_user_id === (string) $u->id) continue;
            if (! self::evidenceVisible($f, $visible)) continue;
            if (! self::fieldsVisible($u, $f)) continue;
            if (! self::subjectGate($u, $f, $reports, $reviewMemo)) continue;
            $out[] = $f;
        }

        return $out;
    }

    private static function signal(AiFinding $f, array $labels, User $u): array
    {
        // **مسودةٌ ثمّ تأكيد** (§٣.٤): نتيجةٌ تحمل مسودةً تفتح نموذجَ الإنشاءِ القائمَ معبّأً —
        // لمن يملك الإضافةَ في وحدتها وحدَه؛ والحفظُ فعلُه هو بصلاحيّاته وموافقاته
        $draft = (array) ($f->draft ?? []);
        $draftUrl = null;
        if (($draft['module'] ?? null) && hub_mod((string) $draft['module']) && hub_can($u, (string) $draft['module'], 'a')) {
            $draftUrl = route('m.create', ['module' => $draft['module']]) . '?' . http_build_query((array) ($draft['fields'] ?? []));
        }

        return [
            'key' => $f->signalKey(),
            'sev' => self::SEV[$f->severity] ?? 'اطّلاع',
            'ico' => '🔎',
            'title' => '🔎 ' . $f->summary,
            'why' => 'المدقّق · ' . ($labels[$f->detector] ?? $f->detector)
                . ($f->source === 'ai' ? ' (بالذكاء الاصطناعيّ — تحقّق قبل أن تحكم)' : '')
                . ($f->suggestion ? ' — ' . $f->suggestion : ''),
            'module' => $f->subject_module,
            'record_id' => $f->subject_id,
            'url' => $draftUrl ?? route('m.show', [$f->subject_module, $f->subject_id]),
            'action' => $draftUrl ? 'سجّله قراراً' : 'افتح',
            'type' => self::TYPE,
            // إضافةٌ متوافقة: الكاشفُ واسمُه — لمن يجمع الإشاراتِ به (الملخّصُ الأسبوعيّ)
            'detector' => $f->detector,
            'label' => $labels[$f->detector] ?? $f->detector,
        ];
    }

    /** سجلّاتُ الشاهدِ المرئيّةُ للمشاهد — استعلامٌ مُنطَّقٌ واحدٌ لكلِّ وحدة @return array<string, array<string, true>> */
    private static function visibleEvidence(User $u, Collection $rows): array
    {
        $want = [];
        foreach ($rows as $f) {
            foreach ((array) $f->evidence as $e) {
                $want[(string) ($e['module'] ?? '')][(string) ($e['id'] ?? '')] = true;
            }
        }

        $out = [];
        foreach ($want as $module => $ids) {
            $out[$module] = [];
            $table = hub_modules()[$module]['table'] ?? null;
            if ($module === '' || $table === null || ! hub_can($u, $module, 'v')) continue;

            foreach (array_chunk(array_keys($ids), 500) as $chunk) {
                $q = DB::table($table)->whereIn('id', $chunk);
                if (Schema::hasColumn($table, 'deleted_at')) $q->whereNull('deleted_at');
                foreach (hub_scope($q, $module, $u)->pluck('id') as $id) $out[$module][(string) $id] = true;
            }
        }

        return $out;
    }

    private static function evidenceVisible(AiFinding $f, array $visible): bool
    {
        $ev = (array) $f->evidence;
        if ($ev === []) return false;   // نتيجةٌ بلا شاهدٍ لا تُعرَض — لا تُصدَّق بلا سند
        foreach ($ev as $e) {
            if (! isset($visible[(string) ($e['module'] ?? '')][(string) ($e['id'] ?? '')])) return false;
        }

        return true;
    }

    private static function fieldsVisible(User $u, AiFinding $f): bool
    {
        foreach ((array) $f->fields as $module => $keys) {
            foreach ((array) $keys as $k) {
                if (hub_field_mode($u, (string) $module, (string) $k) === 'hide') return false;
            }
        }

        return true;
    }

    /** @return array<string, WorkUpdate> */
    private static function reports(Collection $rows): array
    {
        $ids = $rows->where('subject_module', 'updates')->pluck('subject_id')->unique()->values()->all();

        return $ids === [] ? [] : WorkUpdate::query()->whereIn('id', $ids)->get()->keyBy('id')->all();
    }

    /**
     * بوّابةُ الموضوع. وحكمُ المراجعةِ يُقرأ من `ReportReview::canReview` **نفسِه** لا من
     * نسخةٍ عنه — ويُحفَظ لكلِّ (كاتب · مشروع) مرّةً، فهما كلُّ ما يتوقّف عليه الحكم من البند،
     * فلا يتكرّر استعلامُه لكلِّ نتيجة.
     */
    private static function subjectGate(User $u, AiFinding $f, array $reports, array &$memo): bool
    {
        if ($f->subject_module === 'updates') {
            $w = $reports[$f->subject_id] ?? null;
            if ($w === null) return false;
            $k = (string) $w->created_by . '|' . (string) $w->project_id;

            return $memo[$k] ??= ReportReview::canReview($u, $w);
        }

        return hub_can($u, $f->subject_module, 'e');
    }

    /** مشروعُ كلِّ موضوع — لعدسةِ المشروع في مركز الفعل @return array<string, ?string> */
    private static function subjectProjects(Collection $rows): array
    {
        $out = [];
        foreach ($rows->groupBy('subject_module') as $module => $group) {
            $table = hub_modules()[$module]['table'] ?? null;
            if ($table === null || ! Schema::hasColumn($table, 'project_id')) continue;
            foreach (DB::table($table)->whereIn('id', $group->pluck('subject_id')->unique()->values()->all())
                ->get(['id', 'project_id']) as $r) {
                $out[$module . ':' . $r->id] = $r->project_id !== null ? (string) $r->project_id : null;
            }
        }

        return $out;
    }
}
