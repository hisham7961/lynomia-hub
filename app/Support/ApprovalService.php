<?php

namespace App\Support;

use App\Http\Controllers\Web\ModuleController;
use App\Models\Approval;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * **خدمةُ الموافقات المشتركة** — منطقُ الحسمِ وتصفيفِ الطلب في مكانٍ واحد يستدعيه
 * الويبُ والجوالُ معاً — Mobile Readiness · الطور D · Critic F2/F3.
 *
 * **لماذا (Critic F2):** `ApprovalDecisionController::approve/reject` كانا موصولَين
 * بطبقة عرض الويب — يعيدان `back()->withErrors(...)` عند التقادم و`back()->with('ok')`
 * عند النجاح. فاستدعاؤهما من الجوال يعطي 302/HTML لا رمزاً آليّاً، والبديلُ نسخُ
 * الجسمِ = ازدواجُ قاعدة الأعمال المحظور. فالجسمُ (المعاملة + قفلُ الصفّ + حارسُ
 * تقادمِ `meta.ver` + إعادةُ الحمولة الملتقطة + إشعارُ الطالب) هنا، يعيد
 * `ApprovalResult` محايداً، وكلُّ سطحٍ يترجمه.
 *
 * **وتصفيفُ الطلب (`submit`) للطور D · F3:** الكتابةُ المحمية في الجوال لا تعيد
 * «نفّذها من الواجهة» المسدودة — بل تُصفّ طلباً حقيقيّاً بهذه السكّة نفسِها التي
 * يستعملها الويبُ (`ModuleController::queueApproval`)، ثم تعيد `APPROVAL_REQUIRED`
 * بوجهةِ اعتماداتٍ للجوال (`{approvals, id}`).
 *
 * **حتميّةٌ (CLAUDE.md):** القفلُ داخل المعاملة (`lockForUpdate` + `DB::transaction`)،
 * والبحثُ بـ`findOrFail` (مفتاحٌ أوّليّ — صفٌّ واحد). كلُّ الطرائق ساكنة.
 */
class ApprovalService
{
    /* رموزُ النتيجة (تقرؤها ApprovalResult والسطحان) */
    public const APPROVED         = 'approved';
    public const REJECTED         = 'rejected';
    public const VERSION_CONFLICT = 'version_conflict';
    public const FORBIDDEN        = 'forbidden';
    public const ALREADY_DECIDED  = 'already_decided';
    public const NOT_EXECUTABLE   = 'not_executable';

    /**
     * **حسمُ طلبِ موافقة** — الاعتمادُ يُنفّذ العمليةَ المؤجّلة (تعديلٌ بالحمولة
     * المخزّنة أو حذف)، والرفضُ يوقفها — وفي الحالَين يُشعَر الطالب.
     *
     * جسمٌ منقولٌ حرفاً بحرف من `ApprovalDecisionController::approve/reject`
     * (والحرّاسُ من `pending`): معاملةٌ بقفلِ صفٍّ، فحصُ صلاحيةِ الوحدة ثم نطاقِها،
     * حارسُ «حُسم سلفاً»، حارسُ تقادمِ `meta.ver` (للتعديل والحذف)، إعادةُ الحمولة
     * الملتقطة بمحرّك `fill` نفسِه، ثم ختمُ القرار وإشعارُ الطالب.
     *
     * **يعتمد على `auth()->user()` كونه `$approver`:** كلا السطحَين يُرسي الهويّة
     * (الويبُ بالجلسة، الجوالُ بـ`MobileSessionAuth`) قبل النداء — و`fill`/`hub_scope`
     * يقرآن الهويّةَ من `auth()` كما كان الجسمُ الأصليّ يفعل تماماً.
     *
     * @param  Approval|string $approval  الطلبُ أو معرّفُه
     * @param  string          $decision  'approve' | 'reject'
     * @param  array           $input     مدخلاتُ القرار (reject: `note`)
     */
    public static function decide($approval, string $decision, User $approver, array $input = []): ApprovalResult
    {
        // حارسُ المعتمِدين — قبل فتحِ المعاملة (نظيرُ رأسِ approve/reject الأصليّ)
        if (! hub_approver()) {
            return ApprovalResult::fail(self::FORBIDDEN, 403, 'الحسم للمعتمدين فقط');
        }

        $id = $approval instanceof Approval ? $approval->id : (string) $approval;

        return DB::transaction(function () use ($id, $decision, $approver, $input) {
            // قفلُ الصفّ داخل المعاملة — معتمِدان متزامنان لا يجتازان «معلّق» معاً
            $ap = Approval::lockForUpdate()->findOrFail($id);

            // ── حرّاسُ pending() الأصليّة (بالترتيب نفسِه) ──
            // (١) صلاحيةُ الوحدة: الاعتمادُ **تنفيذٌ لا تأشير** — يلزمه ما يلزم فاعلَه
            if ($ap->mod && hub_mod((string) $ap->mod)) {
                $op = in_array((string) $ap->op, ['a', 'e', 'd'], true) ? (string) $ap->op : 'e';
                if (! hub_can($approver, (string) $ap->mod, $op)) {
                    return ApprovalResult::fail(self::FORBIDDEN, 403,
                        'حسمُ هذا الطلب يتطلب صلاحيتَه على وحدته — الاعتمادُ تنفيذٌ لا تأشير');
                }
            }

            // (٢) نطاقُ الحاسم على السجل الهدف — لا يُحسم طلبٌ على سجلٍ خارج الحدّ
            if ($ap->mod && $ap->record_id && ($md = hub_mod((string) $ap->mod))) {
                $inScope = hub_scope(
                    DB::table($md['table'])->where('id', $ap->record_id),
                    (string) $ap->mod)->exists();
                if (! $inScope) {
                    return ApprovalResult::fail(self::FORBIDDEN, 403, 'هذا الطلب على سجلٍ خارج نطاقك');
                }
            }

            // (٣) لا حسمٌ مزدوج — الفيصلُ `decided_at` قبل عمود الحالة (لا يُنقض بقلبِ نصّ)
            if (! ($ap->mod && $ap->decided_at === null
                && in_array($ap->status, [null, '', 'معلّق'], true))) {
                return ApprovalResult::fail(self::ALREADY_DECIDED, 422, 'حُسم هذا الطلب من قبل');
            }

            // ── الرفض ──
            if ($decision === 'reject') {
                $note = trim(hub_str($input['note'] ?? ''));
                $ap->forceFill(['status' => 'مرفوض', 'decided_by' => $approver->id, 'decided_at' => now()])->save();
                self::tellRequester($ap, $approver,
                    'رُفض طلبك: ' . $ap->title . ($note !== '' ? " — السبب: {$note}" : ''));

                return ApprovalResult::ok(self::REJECTED, $ap, false, 'رُفضت العملية وأُبلغ الطالب');
            }

            // ── الاعتماد ──
            $def = hub_mod($ap->mod);
            if (! ($def && $ap->record_id)) {
                return ApprovalResult::fail(self::NOT_EXECUTABLE, 422, 'طلب غير قابل للتنفيذ');
            }

            $class = '\\App\\Models\\' . $def['model'];
            $m = $class::withTrashed()->findOrFail($ap->record_id);

            // حمولةٌ قديمة لا تُعاد فوق تعديلٍ أحدث: النسخةُ الملتقطةُ وقت الطلب هي الفيصل
            $ver = data_get($ap->meta, 'ver');
            if (in_array($ap->op, ['e', 'd'], true) && $ver !== null && (int) $ver !== (int) ($m->version ?? 0)) {
                return ApprovalResult::fail(self::VERSION_CONFLICT, 409,
                    'تغيّر السجل بعد تقديم هذا الطلب — راجعه مع الطالب وليُعِد تقديمه على النسخة الحالية. '
                    . 'لم يُنفَّذ شيء ولم يُحسم الطلب.');
            }

            // سببُ التغيير في التدقيق = مرجعُ الموافقة + سببُ الطالب (على الطلب العامّ الذي يقرؤه التدقيق)
            request()->merge(['_reason' => trim('تنفيذ موافقة معتمدة' . ($ap->reason ? ' — ' . $ap->reason : ''))]);

            if ($ap->op === 'd') {
                $did = ! $m->trashed();
                if ($did) $m->delete();
            } else {
                // إعادةُ الحمولة الملتقطة بمحرّك fill نفسِه — لا نسخَ لمنطق التعبئة
                $payload = (array) $ap->payload;
                $did = app(ModuleController::class)->applyApprovedPayload($def, (string) $ap->mod, $m, $payload);
            }

            $ap->forceFill(['status' => 'معتمد', 'decided_by' => $approver->id, 'decided_at' => now()])->save();
            self::tellRequester($ap, $approver, $did
                ? 'اعتُمد ونُفّذ طلبك: ' . $ap->title
                : 'اعتُمد طلبك ولم يتغيّر شيء — السجل يطابق المطلوب أصلاً: ' . $ap->title);

            return ApprovalResult::ok(self::APPROVED, $ap, $did, $did
                ? 'اعتُمدت العملية ونُفّذت'
                : 'اعتُمدت العملية — ولم يتغيّر شيء في السجل لأنه يطابق المطلوب أصلاً');
        });
    }

    /**
     * **تصفيفُ طلبِ موافقة** — السكّةُ الوحيدة (F3): بدل التنفيذ يُصفَّف طلبٌ بحمولة
     * التعديل ويُشعَر المعتمدون. جسمٌ منقولٌ من `ModuleController::queueApproval`
     * (منزوعَ إعادةِ التوجيه) — يعيد الطلبَ المُنشأ ليبني عليه كلُّ سطحٍ عرضَه: الويبُ
     * إعادةَ توجيهٍ، والجوالُ `APPROVAL_REQUIRED` بوجهةِ `{approvals, id}`.
     *
     * الملفاتُ المرفوعة لا تُؤجَّل (تُستثنى)، وما لا يملك الطالبُ كتابتَه لا يدخل الطابور
     * أصلاً (تنقيةٌ عند الالتقاط بـ`hub_field_mode`) — فلا يُوقَّع على ما لا يُرى.
     *
     * @param string $op  'e' (تعديل) | 'd' (حذف)
     */
    public static function submit(array $def, string $module, string $op, Model $m, Request $r, ?array $onlyKeys = null): Approval
    {
        $payload = null;
        if ($op === 'e') {
            $u = auth()->user();
            $keys = collect($def['fields'])
                ->reject(fn ($f) => in_array($f['type'], ['file', 'img'], true))
                ->reject(fn ($f) => hub_field_mode($u, $module, (string) ($f['key'] ?? '')) !== '')
                ->pluck('key')->push('custom')->all();
            // **حصرٌ صريحٌ بمفاتيحَ مقصودة** (إجراءُ حالةٍ محميّ · Mobile · الطور D):
            // انتقالُ الحالة يُصفَّف بتغييرِ الحالة **وحدَه** — فلا تركب حقولٌ كاتبةٌ
            // أخرى أرسلها العميلُ على قرارِ الحالة خفيةً عن المعتمِد (يعتمد ما يراه لا
            // ما دُسّ معه). null = الحمولةُ الكاملة (تعديلٌ محميّ عبر PUT/PATCH).
            if ($onlyKeys !== null) $keys = array_values(array_intersect($keys, $onlyKeys));
            $payload = collect($r->only($keys))->filter(fn ($v) => $v !== null)->all();
        }

        $name = \Illuminate\Support\Str::limit((string) ($m->{hub_display_col($module)} ?? $m->id), 60);
        // نطاقٌ لكلّ معتمِد — لا يُسنَد ولا يُشعَر باسمِ سجلٍّ خارج حدّه (المالكُ دوماً في المجموعة)
        $approvers = hub_approvers_for($module, $m->id) ?: hub_approvers();

        $ap = Approval::create([
            'title'        => ($op === 'd' ? 'حذف ' : 'تعديل ') . $def['label'] . ': ' . $name,
            'type'         => 'عملية محمية',
            'reason'       => $r->input('_reason'),
            'due'          => now()->addDays(3)->toDateString(),
            'project_id'   => $m->project_id ?? null,
            'approver_id'  => $approvers[0] ?? null,
            'mod'          => $module,
            'record_id'    => $m->id,
            'op'           => $op,
            'payload'      => $payload,
            'requested_by' => auth()->id(),
            'status'       => 'معلّق',
            // نسخةُ السجل وقت الطلب — بها يُكشف عند الحسم أنّ أحداً عدّله في الطابور
            'meta'         => ['ver' => (int) ($m->version ?? 0)],
        ]);

        foreach ($approvers as $uid) {
            if ($uid === auth()->id()) continue;
            hub_notify($uid, 'approval',
                'طلب موافقة من ' . auth()->user()->name . ': ' . $ap->title, 'approvals', $ap->id);
        }

        return $ap;
    }

    /** إشعارُ طالبِ التنفيذ بقرار الحسم — لا يُشعَر الحاسمُ نفسَه (منقولٌ من ApprovalDecisionController) */
    private static function tellRequester(Approval $ap, User $approver, string $text): void
    {
        if (! $ap->requested_by || $ap->requested_by === $approver->id) return;
        hub_notify($ap->requested_by, 'approval',
            $text . ' — بقرار من ' . $approver->name, 'approvals', $ap->id);
    }
}
