<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkUpdate;
use Illuminate\Support\Facades\DB;

/**
 * **مراجعةُ التقارير وقفلُ الامتثال — خدمةٌ واحدةٌ للويب والواجهة (§27/§94).**
 *
 * مراجعةٌ خفيفةٌ على حقولِ `WorkUpdate` القائمة (لا محرّكَ موافقاتٍ ثانٍ §27):
 * `pending_review → accepted / needs_revision`، مع إشعارٍ للموظفِ وأثرٍ مدقَّقٍ
 * وحفظِ التاريخِ عبر HasVersions القائم (§29). القبولُ لا يمسّ الساعاتِ ولا يعيد
 * عدَّها (§73) — يغيّر حالةَ الاعتمادِ فقط.
 *
 * وقفلُ الامتثالِ اليوميّ (§41/§90): قرارُ HR/مدير — «غياب لعدم التقرير»، «معذور»،
 * أو «حاضر (بعد قبولِ تقريرٍ متأخر)» — يُختم على صفِّ الحضورِ بمن ومتى ولماذا، فلا
 * يُعاد كتابتُه صامتاً بتقريرٍ لاحق. لا يُزوَّر ختمُ الحضور/الانصراف غياباً (§7).
 */
class ReportReview
{
    public const PENDING = 'pending_review';
    public const ACCEPTED = 'accepted';
    public const NEEDS_REVISION = 'needs_revision';

    /* ═══════════ صلاحيّةُ المراجعة (§32/§33/§76 — تنطيقٌ لا اسمُ دور) ═══════════ */

    /**
     * من يراجع هذا البند؟ المالكُ مطلقاً؛ ومن يملك `hr:e`؛ ومديرُ مشروعِ البند ضمن
     * نطاقه (`hub_scope('projects')`). لا يراجع أحدٌ تقريرَه (§76) إلّا المالك.
     */
    public static function canReview(User $actor, WorkUpdate $w): bool
    {
        if (hub_is_client($actor)) return false;

        $owner = $actor->role?->is_owner;
        // لا مراجعةَ لتقريرِ النفس إلّا للمالك (فصلُ التنفيذ عن الحكم)
        if (! $owner && (string) $w->created_by === (string) $actor->id) return false;
        if ($owner) return true;

        // مسؤولُ موارد بشرية (يرى الامتثالَ ويحكمه) — عبر التنطيق لا الاسم
        if (hub_can($actor, 'hr', 'e')) return true;

        // مديرُ المشروع: البندُ ضمن نطاقِ مشاريعه، وله تعديلُ التحديثات
        if ($w->project_id && hub_can($actor, 'updates', 'e')) {
            return hub_scope(DB::table('projects')->whereNull('deleted_at')->where('id', $w->project_id),
                'projects', $actor)->exists();
        }
        return false;
    }

    /** أيقدر المستخدمُ رؤيةَ مركزِ المراجعة أصلاً؟ (بوّابةُ السطح) */
    public static function canReviewAny(?User $actor): bool
    {
        if (! $actor || hub_is_client($actor)) return false;
        return (bool) ($actor->role?->is_owner || hub_can($actor, 'hr', 'v') || hub_can($actor, 'updates', 'e'));
    }

    /* ═══════════ §30 قفلُ التحرير بعد القبول ═══════════ */

    /**
     * تقريرٌ مقبولٌ لا يعيد الموظفُ كتابتَه صامتاً (§30): مقفولٌ على كاتبِه إلّا
     * إن كان مالكاً/مسؤولَ موارد بشرية (يُعيد فتحَه بمراجعةٍ مدقَّقة).
     */
    public static function isLockedForEditor(WorkUpdate $w, User $actor): bool
    {
        if ($w->review_status !== self::ACCEPTED) return false;
        if ($actor->role?->is_owner || hub_can($actor, 'hr', 'e')) return false;
        return (string) $w->created_by === (string) $actor->id;
    }

    /* ═══════════ أفعالُ المراجعة ═══════════ */

    public static function accept(WorkUpdate $w, User $actor, ?string $feedback = null): void
    {
        self::stamp($w, self::ACCEPTED, $actor, $feedback);
        hub_audit('قبول تقرير', 'updates', $w->id, $actor->name,
            ['after' => ['review' => self::ACCEPTED]]);
        // إشعارُ الكاتب — القبولُ لا يمسّ الساعاتِ (§73)
        self::notifyAuthorOnce($w, 'report_accepted',
            '✅ اعتُمد تقريرُك ليوم ' . self::dayOf($w) . (($p = self::projLabel($w)) ? ' — ' . $p : ''));
    }

    public static function needsRevision(WorkUpdate $w, User $actor, string $feedback): void
    {
        self::stamp($w, self::NEEDS_REVISION, $actor, $feedback);
        hub_audit('طلب تنقيح تقرير', 'updates', $w->id, $actor->name,
            ['after' => ['review' => self::NEEDS_REVISION, 'feedback' => \Illuminate\Support\Str::limit($feedback, 200)]]);
        self::notifyAuthorOnce($w, 'report_needs_revision',
            '✏️ طُلب تنقيحُ تقريرِك ليوم ' . self::dayOf($w) . ': ' . \Illuminate\Support\Str::limit($feedback, 300), true);
    }

    /** إعادةُ فتحِ تقريرٍ مقبول للمراجعة (§30 — بأثرٍ مدقَّق) */
    public static function reopen(WorkUpdate $w, User $actor): void
    {
        self::stamp($w, self::PENDING, $actor, null);
        hub_audit('إعادة فتح تقرير للمراجعة', 'updates', $w->id, $actor->name,
            ['after' => ['review' => self::PENDING]]);
    }

    protected static function stamp(WorkUpdate $w, string $status, User $actor, ?string $feedback): void
    {
        // عبر forceFill: الحقولُ محروسةٌ من التعبئة الجماعية عمداً — تُختم هنا فقط
        $w->forceFill([
            'review_status' => $status,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'review_feedback' => $feedback,
        ])->save();
    }

    /* ═══════════ §40/§41/§90 قفلُ الامتثالِ اليوميّ ═══════════ */

    /**
     * ختمُ الأثرِ الفعّالِ على يومِ حضورٍ — قرارُ HR/مدير لا يُعاد كتابتُه صامتاً.
     * القيمُ: absent_due_to_missing_report / excused / present (قبولُ تقريرٍ متأخر).
     * لا يمسّ time_in/time_out (§7). يتطلّب سبباً وفاعلاً وزمناً (§90).
     */
    public static function finalizeCompliance(Attendance $row, User $actor, string $outcome, ?string $note = null): void
    {
        $allowed = ['absent_due_to_missing_report', 'excused', 'present', 'non_compliant'];
        if (! in_array($outcome, $allowed, true)) $outcome = 'present';

        $row->forceFill([
            'compliance_outcome' => $outcome,
            'compliance_finalized_at' => now(),
            'compliance_finalized_by' => $actor->id,
            'compliance_note' => $note ? \Illuminate\Support\Str::limit($note, 490) : null,
        ])->save();

        hub_audit('ختم أثر الامتثال', 'attend', $row->id, $actor->name,
            ['after' => ['outcome' => $outcome, 'note' => \Illuminate\Support\Str::limit((string) $note, 200)]]);
    }

    /** رفعُ الختم — يُعيد اليومَ للاشتقاقِ الحيّ (بأثرٍ مدقَّق) */
    public static function clearComplianceLock(Attendance $row, User $actor): void
    {
        $row->forceFill([
            'compliance_outcome' => null, 'compliance_finalized_at' => null,
            'compliance_finalized_by' => null, 'compliance_note' => null,
        ])->save();
        hub_audit('رفع ختم الامتثال', 'attend', $row->id, $actor->name);
    }

    /* ────────── مساعدات ────────── */

    /** إشعارٌ للكاتبِ مرّةً واحدةً لكلِّ (بند/نوع) — لا تكرارَ عند إعادةِ الفعل (§39/§84) */
    protected static function notifyAuthorOnce(WorkUpdate $w, string $kind, string $text, bool $force = false): void
    {
        if (! $w->created_by) return;
        if (! $force) {
            $dup = DB::table('notifications_hub')->where('user_id', $w->created_by)
                ->where('kind', $kind)->where('record_id', $w->id)->exists();
            if ($dup) return;
        }
        hub_notify($w->created_by, $kind, $text, 'updates', $w->id);
    }

    protected static function dayOf(WorkUpdate $w): string
    {
        return (string) ($w->work_date instanceof \DateTimeInterface
            ? $w->work_date->format('Y-m-d') : $w->work_date);
    }

    protected static function projLabel(WorkUpdate $w): ?string
    {
        if (! $w->project_id) return 'عمل داخليّ';
        return hub_ref_labels('projects', [$w->project_id])[$w->project_id] ?? null;
    }

    /**
     * موظّفُ صاحبِ البند — عبرَ الجسرِ القانونيّ User.id ← Employee.user_id (لا emp_id).
     */
    public static function employeeOf(WorkUpdate $w): ?Employee
    {
        if (! $w->created_by) return null;
        return Employee::whereNull('deleted_at')->where('user_id', $w->created_by)->orderBy('id')->first();
    }
}
