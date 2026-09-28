<?php

namespace App\Support\Ai\Proposals;

use App\Models\AiProposal;
use App\Models\User;
use App\Support\Ai\Assist\DraftAssistant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * **طابورُ اقتراحات الذكاء — «الذكاءُ يقترح والبياناتُ تقرّر»** (docs/ai-hub/47 §٢ العمود أ).
 *
 * الذكاءُ لا يكتب في سجلٍّ أعماليٍّ أبداً. يقترح تغييرَ **حقلٍ واحدٍ من قائمةٍ مغلقة** (`KINDS`) مع
 * سببٍ ودليلٍ مقتبس، ويقرّر إنسانٌ يملك تعديلَ ذلك الحقل:
 *
 *  - **الدليلُ يتحقّق منه الخادم لا النموذج** (`verifyEvidence`): كلُّ اقتباسٍ يجب أن يوجد حرفياً في
 *    السجلّ الذي يُنسب إليه، وإلا يُرفض الاقتراحُ قبل أن يُحفظ — أقوى حاجزٍ ضدّ الهلوسة.
 *  - **الاعتمادُ يعيد كلَّ فحص** (`apply`) بعين المعتمِد: صلاحيّةُ التعديل، ونطاقُ السجلّ، وحجبُ الحقل،
 *    **وأنّ القيمةَ الحاليّة ما زالت هي التي رآها الاقتراح** — وإلا صار `superseded` ولم يُطبَّق.
 *    والكتابةُ عبر النموذج نفسِه فيعمل `Auditable` و`HasVersions` كأيِّ تعديل، ومعها أثرٌ صريح `ai.proposal.apply`.
 *  - **الدقّةُ تُطفئ النوعَ الرديء** (`accuracy`): نوعٌ يُرفض ≥ ٦٠٪ من قراراته (بعد عشرةٍ على الأقل)
 *    يتوقّف عن الاقتراح آلياً — كما يفعل `AuditorAccuracy` بكواشف المدقّق.
 */
final class ProposalService
{
    /** الأنواعُ المسموحة — كلُّ نوعٍ حقلٌ واحدٌ في وحدةٍ واحدة. ما خرج عنها يُرفض. */
    public const KINDS = [
        'task_progress'    => ['module' => 'tasks',    'field' => 'progress',     'label' => 'تقدّم المهمة'],
        'task_status'      => ['module' => 'tasks',    'field' => 'status',       'label' => 'حالة المهمة'],
        'task_late_reason' => ['module' => 'tasks',    'field' => 'lateReason',   'label' => 'سبب تأخّر المهمة'],
        'project_progress' => ['module' => 'projects', 'field' => 'progress',     'label' => 'تقدّم المشروع'],
        'project_status'   => ['module' => 'projects', 'field' => 'status',       'label' => 'حالة المشروع'],
        'kr_value'         => ['module' => 'krs',      'field' => 'currentValue', 'label' => 'القيمة الحالية للنتيجة الرئيسية'],
        'request_priority' => ['module' => 'requests', 'field' => 'prioFinal',    'label' => 'أولوية الطلب'],
        'request_days'     => ['module' => 'requests', 'field' => 'estDays',      'label' => 'تقدير أيام الطلب'],
        'request_cost'     => ['module' => 'requests', 'field' => 'estCost',      'label' => 'تقدير تكلفة الطلب'],
    ];

    public const STATUSES = ['open', 'applied', 'rejected', 'expired', 'superseded'];

    /** أفعالُ التدقيق — ثابتان مُعلَنان لا حرفيّان مبعثران */
    public const AUDIT_APPLY = 'ai.proposal.apply';
    public const AUDIT_REJECT = 'ai.proposal.reject';

    /** أقلُّ طولٍ للاقتباس — كلمةٌ أو كلمتان تطابقان أيَّ نصٍّ صدفةً فلا تشهدان */
    public const MIN_QUOTE = 8;

    public const MAX_EVIDENCE = 5;

    /** عتبةُ الإطفاء الآليّ: نسبةُ رفضٍ بعد حدٍّ أدنى من القرارات، في نافذةٍ بالأيّام */
    public const DISABLE_RATIO = 0.6;
    public const DISABLE_MIN_DECISIONS = 10;
    public const ACCURACY_WINDOW_DAYS = 60;

    public static function ready(): bool
    {
        // خبيئةُ المخطَّط لا استعلامٌ في كلِّ صفحة — الرابطُ في الشريط يسأل هنا
        return \App\Support\Platform\SchemaCache::hasTable('ai_proposals');
    }

    public static function enabled(): bool
    {
        return self::ready() && (string) setting('ai.proposals', '1') === '1';
    }

    public static function ttlDays(): int
    {
        return max(1, min(90, (int) setting('ai.proposals_ttl_days', 14)));
    }

    public static function label(string $kind): string
    {
        return (string) (self::KINDS[$kind]['label'] ?? $kind);
    }

    /** تعريفُ الحقل في سجلّ الوحدة — أو null إن غاب (نوعٌ لا يعمل على هذا التنصيب) */
    public static function fieldDef(string $kind): ?array
    {
        $k = self::KINDS[$kind] ?? null;
        if ($k === null) return null;

        foreach ((array) (hub_mod($k['module'])['fields'] ?? []) as $f) {
            if (($f['key'] ?? null) === $k['field']) return (array) $f;
        }

        return null;
    }

    // ── الإنتاج ───────────────────────────────────────────────────────────

    /**
     * **اقتراحٌ جديد** — أو `['ok'=>false,'why'=>…]` بسببٍ يُقال. لا يكتب في السجلّ الأعماليّ شيئاً.
     *
     * @param  list<array{module?:string, record_id?:string, quote?:string}>  $evidence
     * @return array{ok:bool, why?:string, proposal?:AiProposal}
     */
    public static function propose(string $kind, string $recordId, mixed $value, string $rationale,
        array $evidence, ?int $confidence = null, string $source = 'ai'): array
    {
        if (! self::enabled()) return ['ok' => false, 'why' => 'طابورُ الاقتراحات مطفأ'];
        if (! isset(self::KINDS[$kind])) return ['ok' => false, 'why' => 'نوعُ اقتراحٍ غيرُ معروف'];
        if (self::disabled($kind)) return ['ok' => false, 'why' => 'هذا النوعُ موقوفٌ آلياً لكثرة رفضه'];

        $def = self::fieldDef($kind);
        if ($def === null) return ['ok' => false, 'why' => 'الحقلُ غيرُ موجودٍ في سجلّ الوحدة'];

        $module = self::KINDS[$kind]['module'];
        $row = self::row($module, $recordId);
        if ($row === null) return ['ok' => false, 'why' => 'السجلُّ غيرُ موجود'];

        $proposed = self::clean($def, $value);
        if ($proposed === null) return ['ok' => false, 'why' => 'قيمةٌ لا تصلح لهذا الحقل'];

        $current = self::stringify($row->{$def['col']} ?? null);
        if (self::same($def, $current, $proposed)) return ['ok' => false, 'why' => 'القيمةُ المقترحة هي الحاليّة'];

        $ev = self::verifyEvidence($evidence);
        if ($ev === null) return ['ok' => false, 'why' => 'دليلٌ لا يوجد حرفياً في مصدره — رُفض الاقتراح'];

        return DB::transaction(function () use ($kind, $module, $recordId, $def, $current, $proposed, $rationale, $ev, $confidence, $source) {
            $open = self::models(AiProposal::query()->where('status', 'open')->where('module', $module)
                ->where('record_id', $recordId)->where('field', $def['key'])
                ->orderBy('created_at')->orderBy('id')->lockForUpdate()->get());

            // الاقتراحُ نفسُه قائمٌ: لا تكرار — يُمدَّد عمرُه فقط
            if ($dup = $open->first(fn (AiProposal $p) => self::same($def, (string) $p->proposed_value, $proposed))) {
                $dup->forceFill(['expires_at' => now()->addDays(self::ttlDays())])->save();

                return ['ok' => true, 'proposal' => $dup];
            }
            // اقتراحٌ أحدثُ يحلّ محلّ الأقدم على الحقل نفسِه
            foreach ($open as $old) $old->forceFill(['status' => 'superseded', 'decided_at' => now()])->save();

            $p = AiProposal::create([
                'kind' => $kind, 'module' => $module, 'record_id' => $recordId, 'field' => $def['key'],
                'current_value' => $current, 'proposed_value' => $proposed,
                'rationale' => Str::limit(\App\Support\Platform\Redactor::text(trim($rationale)), 1000, '…'),
                'evidence' => $ev, 'confidence' => $confidence === null ? null : max(0, min(100, $confidence)),
                'source' => Str::limit($source, 40, ''), 'status' => 'open',
                'expires_at' => now()->addDays(self::ttlDays()),
            ]);

            return ['ok' => true, 'proposal' => $p];
        });
    }

    /**
     * **الدليلُ الحرفيّ:** كلُّ بندٍ سجلٌّ موجودٌ في وحدةٍ معروفة، واقتباسُه (بعد توحيد المسافات)
     * جزءٌ من نصوص ذلك السجلّ. بندٌ واحدٌ كاذبٌ يُسقط الاقتراحَ كلَّه — لا انتقاءَ لما صدق.
     *
     * @return list<array{module:string, record_id:string, quote:string}>|null
     */
    public static function verifyEvidence(array $evidence): ?array
    {
        $out = [];
        foreach (array_slice($evidence, 0, self::MAX_EVIDENCE) as $e) {
            $module = (string) ($e['module'] ?? '');
            $id = (string) ($e['record_id'] ?? '');
            $quote = self::norm((string) ($e['quote'] ?? ''));
            if (hub_mod($module) === null || $id === '' || mb_strlen($quote) < self::MIN_QUOTE) return null;

            $row = self::row($module, $id);
            if ($row === null) return null;

            $hay = self::norm(implode("\n", array_filter(array_map(
                fn ($v) => is_string($v) ? $v : null, (array) $row))));
            if (! str_contains($hay, $quote)) return null;

            $out[] = ['module' => $module, 'record_id' => $id, 'quote' => Str::limit($quote, 400, '…')];
        }

        return $out === [] ? null : $out;
    }

    // ── القرار ─────────────────────────────────────────────────────────────

    /**
     * **أيملك هذا المستخدمُ أن يقرّر هذا الاقتراح؟** تعديلُ الوحدة + السجلُّ في نطاقه + الحقلُ غيرُ محجوبٍ عنه.
     */
    public static function canAct(User $u, AiProposal $p): bool
    {
        if (hub_is_client($u) || ! hub_can($u, $p->module, 'e')) return false;
        if (hub_field_mode($u, $p->module, (string) $p->field) !== '') return false;

        return self::scoped($u, $p->module)->where('id', $p->record_id)->exists();
    }

    /**
     * **الاعتماد** — بقيمة الاقتراح أو بقيمةٍ عدّلها المعتمِد (`$override`)، والتحقّقُ كلُّه يُعاد هنا.
     *
     * @return array{ok:bool, why?:string, status?:string}
     */
    public static function apply(User $u, AiProposal $p, mixed $override = null): array
    {
        return DB::transaction(function () use ($u, $p, $override) {
            $p = self::models(AiProposal::query()->whereKey($p->id)->lockForUpdate()->get())->first();
            if ($p === null || $p->status !== 'open') return ['ok' => false, 'why' => 'الاقتراحُ لم يعد مفتوحاً'];
            if ($p->expires_at && $p->expires_at->isPast()) {
                $p->forceFill(['status' => 'expired', 'decided_at' => now()])->save();

                return ['ok' => false, 'why' => 'انتهت صلاحيّةُ الاقتراح', 'status' => 'expired'];
            }
            if (! self::canAct($u, $p)) return ['ok' => false, 'why' => 'لا تملك تعديلَ هذا الحقل في هذا السجلّ'];

            $def = self::fieldDef($p->kind);
            if ($def === null) return ['ok' => false, 'why' => 'الحقلُ لم يعد موجوداً'];

            $model = self::model($u, $p->module, $p->record_id);
            if ($model === null) return ['ok' => false, 'why' => 'السجلُّ غيرُ موجود'];

            $col = (string) $def['col'];
            $now = self::stringify($model->getAttribute($col));
            if (! self::same($def, $now, (string) $p->current_value)) {
                $p->forceFill(['status' => 'superseded', 'decided_at' => now()])->save();

                return ['ok' => false, 'why' => 'تغيّرت القيمةُ منذ الاقتراح — لم يُطبَّق', 'status' => 'superseded'];
            }

            $value = $override === null || $override === '' ? (string) $p->proposed_value : self::clean($def, $override);
            if ($value === null) return ['ok' => false, 'why' => 'القيمةُ المعدَّلة لا تصلح لهذا الحقل'];

            $model->setAttribute($col, $value);
            $model->save();

            $p->forceFill(['status' => 'applied', 'applied_value' => $value,
                'decided_by' => (string) $u->id, 'decided_at' => now()])->save();

            hub_audit(self::AUDIT_APPLY, $p->module, (string) $p->record_id, self::label($p->kind), [
                'before' => [$p->field => $now],
                'after' => [$p->field => $value, 'proposal' => (string) $p->id, 'via' => 'ai',
                    'edited' => $value !== (string) $p->proposed_value],
            ]);

            return ['ok' => true, 'status' => 'applied'];
        });
    }

    /** @return array{ok:bool, why?:string} */
    public static function reject(User $u, AiProposal $p, ?string $reason = null): array
    {
        if ($p->status !== 'open') return ['ok' => false, 'why' => 'الاقتراحُ لم يعد مفتوحاً'];
        if (! self::canAct($u, $p)) return ['ok' => false, 'why' => 'لا تملك تعديلَ هذا الحقل في هذا السجلّ'];

        $p->forceFill(['status' => 'rejected', 'decided_by' => (string) $u->id, 'decided_at' => now(),
            'reject_reason' => $reason === null ? null : Str::limit(trim($reason), 290, '…')])->save();
        hub_audit(self::AUDIT_REJECT, $p->module, (string) $p->record_id, self::label($p->kind),
            ['reason' => $p->reject_reason, 'after' => ['field' => $p->field, 'proposal' => (string) $p->id, 'via' => 'ai']]);

        return ['ok' => true];
    }

    /** ما انتهى عمرُه يُغلق — خطوةٌ في الدورة اليوميّة */
    public static function expire(bool $dry = false): int
    {
        if (! self::ready()) return 0;
        $q = AiProposal::query()->where('status', 'open')->whereNotNull('expires_at')->where('expires_at', '<', now());

        return $dry ? $q->count() : $q->update(['status' => 'expired', 'decided_at' => now(), 'updated_at' => now()]);
    }

    // ── القراءة ────────────────────────────────────────────────────────────

    /**
     * الاقتراحاتُ المفتوحة التي يملك هذا المستخدمُ قرارَها — اختياريّاً لسجلٍّ واحد.
     *
     * @return Collection<int, AiProposal>
     */
    public static function openFor(User $u, ?string $module = null, ?string $recordId = null, int $limit = 200): Collection
    {
        if (! self::ready() || hub_is_client($u)) return collect();

        $q = AiProposal::query()->where('status', 'open')
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', now()));
        if ($module !== null) $q->where('module', $module);
        if ($recordId !== null) $q->where('record_id', $recordId);
        $rows = self::models($q->orderByDesc('created_at')->orderByDesc('id')->limit(1000)->get());

        // النطاقُ لكلِّ وحدةٍ باستعلامٍ واحد — لا استعلامَ لكلِّ اقتراح
        $ok = [];
        foreach ($rows->groupBy('module') as $mod => $group) {
            if (! hub_can($u, (string) $mod, 'e')) continue;
            $ids = self::scoped($u, (string) $mod)->whereIn('id', $group->pluck('record_id')->unique()->values())
                ->pluck('id')->map(fn ($x) => (string) $x)->all();
            foreach ($ids as $id) $ok[$mod . ':' . $id] = true;
        }

        return $rows->filter(fn ($p) => isset($ok[$p->module . ':' . $p->record_id])
                && hub_field_mode($u, $p->module, (string) $p->field) === '')
            ->take($limit)->values();
    }

    public static function countFor(User $u): int
    {
        return self::openFor($u)->count();
    }

    /**
     * الدليلُ كما يُعرض لهذا القارئ — اقتباسُ سجلٍّ لا يراه يُستبدل بجملةٍ لا تكشفه.
     *
     * @return list<array{quote:?string, url:?string, module:string}>
     */
    public static function evidenceFor(User $u, AiProposal $p): array
    {
        $out = [];
        foreach ((array) $p->evidence as $e) {
            $m = (string) ($e['module'] ?? '');
            $id = (string) ($e['record_id'] ?? '');
            $visible = hub_mod($m) !== null && hub_can($u, $m, 'v') && self::scoped($u, $m)->where('id', $id)->exists();
            $out[] = $visible
                ? ['quote' => (string) ($e['quote'] ?? ''), 'url' => route('m.show', [$m, $id]), 'module' => $m]
                : ['quote' => null, 'url' => null, 'module' => $m];
        }

        return $out;
    }

    /** عنوانُ السجلّ الهدف كما يراه القارئ (بعد التحقّق من رؤيته في `openFor`) */
    public static function titles(Collection $proposals): array
    {
        $out = [];
        foreach ($proposals->groupBy('module') as $mod => $group) {
            $def = hub_mod((string) $mod);
            $col = hub_display_col((string) $mod);
            if ($def === null || ! $col) continue;
            $names = DB::table((string) $def['table'])->whereIn('id', $group->pluck('record_id')->unique()->values())
                ->pluck($col, 'id');
            foreach ($names as $id => $name) $out[$mod . ':' . $id] = (string) $name;
        }

        return $out;
    }

    // ── الدقّة ─────────────────────────────────────────────────────────────

    /** @return array{applied:int, rejected:int, decisions:int, ratio:?float, disabled:bool} */
    public static function accuracy(string $kind): array
    {
        if (! self::ready()) return ['applied' => 0, 'rejected' => 0, 'decisions' => 0, 'ratio' => null, 'disabled' => false];

        $rows = AiProposal::query()->where('kind', $kind)->whereIn('status', ['applied', 'rejected'])
            ->where('decided_at', '>=', now()->subDays(self::ACCURACY_WINDOW_DAYS))
            ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $a = (int) ($rows['applied'] ?? 0);
        $r = (int) ($rows['rejected'] ?? 0);
        $n = $a + $r;
        $ratio = $n > 0 ? $r / $n : null;

        return ['applied' => $a, 'rejected' => $r, 'decisions' => $n, 'ratio' => $ratio,
            'disabled' => $n >= self::DISABLE_MIN_DECISIONS && $ratio !== null && $ratio >= self::DISABLE_RATIO];
    }

    public static function disabled(string $kind): bool
    {
        return self::accuracy($kind)['disabled'];
    }

    // ── داخليّ ─────────────────────────────────────────────────────────────

    /**
     * صفوفُ الاستعلام نماذجَ `AiProposal` حصراً — تضييقٌ فعليٌّ لا تزيين: ما ليس نموذجاً لا يُعاد.
     *
     * @param  iterable<mixed>  $rows
     * @return Collection<int, AiProposal>
     */
    private static function models(iterable $rows): Collection
    {
        $out = [];
        foreach ($rows as $r) if ($r instanceof AiProposal) $out[] = $r;

        return collect($out);
    }

    private static function scoped(User $u, string $module)
    {
        $def = (array) hub_mod($module);

        return hub_scope(DB::table((string) $def['table'])->whereNull('deleted_at'), $module, $u);
    }

    private static function model(User $u, string $module, string $id): ?Model
    {
        $class = '\\App\\Models\\' . (hub_mod($module)['model'] ?? '');
        if (! class_exists($class)) return null;

        /** @var Model|null */
        return hub_scope($class::query(), $module, $u)->find($id);
    }

    /** الصفُّ الخامُ بلا نطاق — للمُنتِج الآليّ والتحقّق من الدليل وحدَهما، لا للعرض */
    private static function row(string $module, string $id): ?object
    {
        $def = hub_mod($module);
        if ($def === null || ! Schema::hasTable((string) $def['table'])) return null;
        $q = DB::table((string) $def['table'])->where('id', $id);
        if (\App\Support\Platform\SchemaCache::hasColumn((string) $def['table'], 'deleted_at')) $q->whereNull('deleted_at');

        return $q->first();
    }

    /** قيمةٌ مُتحقَّقٌ منها بنوع الحقل — والتقدّمُ بين ٠ و١٠٠ */
    private static function clean(array $def, mixed $v): ?string
    {
        $spec = ['type' => (string) ($def['type'] ?? 'text'),
            'options' => array_values(array_map('strval', (array) ($def['options'] ?? [])))];
        // الأرقامُ بمداها لا بسقفِ المسودّات (١٠٬٠٠٠) — تكلفةُ طلبٍ تتجاوزه عادةً
        if ($spec['type'] === 'num') {
            $s = is_scalar($v) ? trim((string) $v) : '';
            $s = is_numeric($s) && (float) $s >= 0 && (float) $s < 1e12 ? (string) (0 + $s) : null;
        } else {
            $s = DraftAssistant::value($spec, $v);
        }
        if ($s !== null && ($def['col'] ?? '') === 'progress' && ((float) $s < 0 || (float) $s > 100)) return null;

        return $s;
    }

    private static function stringify(mixed $v): string
    {
        return $v === null ? '' : trim((string) $v);
    }

    /** مساواةٌ بنوع الحقل: الأرقامُ عدداً (٤٠ = 40.000) والنصوصُ حرفاً */
    private static function same(array $def, string $a, string $b): bool
    {
        if (($def['type'] ?? '') === 'num' && is_numeric($a) && is_numeric($b)) return abs((float) $a - (float) $b) < 0.0005;

        return trim($a) === trim($b);
    }

    private static function norm(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }
}
