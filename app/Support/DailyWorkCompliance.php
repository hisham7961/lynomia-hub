<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\WorkUpdate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **المُحلِّلُ المركزيُّ للامتثالِ اليوميّ — الحقيقةُ الواحدة لحالةِ اليوم (§10/§101).**
 *
 * كانت الحالةُ تُحسَب مرّةً عند الانصراف وتُخزَّن في `attendance.status`، فيطمس
 * «بلا تقرير» الحضورَ الفيزيائيّ، ولا يُعيد تقييمَها تقريرٌ قُدِّم بعده — فبقي
 * يومٌ حاضرٌ مُبلَّغٌ عنه «حاضر — بلا تقرير» أبداً (ROOT_CAUSE.md).
 *
 * هنا تُفصَل الحقائقُ الثلاث (§6) وتُشتقُّ حيّاً — لا محرّكَ حالةٍ يتبيّت:
 *
 *  - **الحضورُ الفيزيائيّ** (`physical`): حاضر/متأخر/ميداني/عن بعد/غائب/إجازة — من
 *    `attendance.status` كما هو، والقديمُ «بلا تقرير» يُقرأ حضوراً (لا يُطمَس).
 *  - **الامتثالُ التقريريّ** (`compliance`): ممتثل/بانتظار/ناقص/متأخر/غيرُ مطلوب —
 *    من وجودِ تقريرٍ صالحٍ قبل المهلة، عبر المسنَدِ الوحيد `hasSubmittedReport`.
 *  - **الأثرُ الفعّالُ للموارد البشرية** (`effective`): حاضر/غياب بسبب عدم التقرير/
 *    إجازة/غائب/معذور — بالسياسةِ والمهلة، مع بقاءِ ختمَي الحضور/الانصراف كما هما.
 *
 * كلُّ شاشةٍ وواجهةٍ تستهلك هذا المُحلِّل — فلا «has-report» مكرّرٌ في مكانين (§101).
 */
class DailyWorkCompliance
{
    /* ────────── الحالاتُ القانونيّة اليوميّة (§11) ────────── */
    public const NOT_REQUIRED = 'not_required';                    // إجازة/عطلة/راحة أو الاشتراطُ مُطفأ
    public const ABSENT = 'absent';                                // لا حضورَ في يومِ عمل
    public const CHECKED_IN = 'checked_in';                        // ورديةٌ مفتوحةٌ بعد
    public const REPORT_PENDING = 'report_pending';                // انصرف، بلا تقرير، قبلَ المهلة
    public const PRESENT_REPORTED = 'present_reported';            // حضورٌ + تقريرٌ صالحٌ في وقته
    public const PRESENT_WITHOUT_REPORT = 'present_without_report';// حضورٌ بلا تقريرٍ بعدَ المهلة
    public const ABSENT_DUE_TO_MISSING_REPORT = 'absent_due_to_missing_report'; // أثرُ السياسة
    public const LATE_REPORT = 'late_report';                      // تقريرٌ قُدِّم بعدَ المهلة

    /* ────────── سياساتُ التقريرِ الناقص (§9) ────────── */
    public const POLICY_WARNING = 'warning_only';
    public const POLICY_NON_COMPLIANT = 'non_compliant';
    public const POLICY_ABSENCE = 'absence_equivalent';

    /* ═══════════ المسنَدُ الوحيد: هل قُدِّم تقريرٌ صالح؟ (§13/§101) ═══════════ */

    /**
     * تعريفُ التقريرِ الصالح (§13/§24): بندُ عملٍ غيرُ محذوفٍ ذو محتوىً فعليٍّ في
     * حقلِ الإنجاز `done` — لا نائبٌ فارغٌ («-» أو فراغ) يُرضي اشتراطاً. المشروعُ
     * غيرُ لازم (§16 العملُ الداخليُّ تقريرٌ صالح). المراجعةُ متعامدة: بندٌ «يحتاج
     * تنقيحاً» ما زال تقريراً قُدِّم — الامتثالُ من الوجودِ لا من قبولِ المدير.
     */
    public static function isValidReportRow($row): bool
    {
        $done = trim((string) ($row->done ?? ''));
        if ($done === '') return false;
        // نائبٌ رمزيٌّ لا يُرضي: شرطةٌ/نقطةٌ/فاصلةٌ وحدَها
        if (in_array($done, ['-', '—', '–', '.', '،', '*', 'x', 'لا', 'لا شيء'], true)) return false;
        return mb_strlen($done) >= 2;
    }

    /**
     * المسنَدُ القانونيّ الوحيد: هل لهذا المستخدمِ تقريرٌ صالحٌ في هذا التاريخِ التجاريّ؟
     * (المطابقةُ عبر `created_by = User.id` — الجسرُ Employee.user_id ↔ User.id، لا emp_id.)
     */
    public static function hasSubmittedReport($userId, string $date): bool
    {
        if (! $userId) return false;
        return self::validReportsQuery($userId, $date)->get(['done'])
            ->contains(fn ($r) => self::isValidReportRow($r));
    }

    /** استعلامُ بنودِ يومٍ لمستخدم — غيرُ محذوفة، بالتاريخِ التجاريّ */
    protected static function validReportsQuery($userId, string $date)
    {
        return DB::table('work_updates')->whereNull('deleted_at')
            ->where('created_by', $userId)->whereDate('work_date', $date);
    }

    /* ═══════════ الحلّ لموظّفٍ واحد ═══════════ */

    /**
     * حالةُ يومِ عملِ موظّفٍ كاملةً — المدخل: موظّفٌ + تاريخٌ تجاريّ (§10).
     * تُعيد مصفوفةً بالحقائقِ الثلاث والحقولِ التي تستهلكها كلُّ الشاشات.
     */
    public static function resolve(Employee $emp, ?string $date = null): array
    {
        $date = $date ?: BusinessDate::today();

        $atts = Attendance::whereNull('deleted_at')->where('emp_id', $emp->id)
            ->whereDate('date', $date)->orderBy('id')->get();

        $reports = $emp->user_id
            ? WorkUpdate::whereNull('deleted_at')->where('created_by', $emp->user_id)
                ->whereDate('work_date', $date)->orderBy('submitted_at')->orderBy('id')->get()
            : collect();

        return self::compose($emp, $date, $atts, $reports);
    }

    /**
     * الحلُّ الجماعيّ (§99 · N+1 = 0): مجموعةُ موظّفين ليومٍ واحد — استعلامان اثنان
     * لا استعلامٌ لكلِّ صف. يُعيد مصفوفةً مفتاحُها `employee.id`.
     */
    public static function resolveMany(Collection $emps, ?string $date = null): array
    {
        $date = $date ?: BusinessDate::today();
        if ($emps->isEmpty()) return [];

        $empIds = $emps->pluck('id')->all();
        $userIds = $emps->pluck('user_id')->filter()->all();

        $attByEmp = Attendance::whereNull('deleted_at')->whereIn('emp_id', $empIds)
            ->whereDate('date', $date)->orderBy('id')->get()->groupBy('emp_id');

        $repByUser = $userIds
            ? WorkUpdate::whereNull('deleted_at')->whereIn('created_by', $userIds)
                ->whereDate('work_date', $date)->orderBy('submitted_at')->orderBy('id')->get()->groupBy('created_by')
            : collect();

        $out = [];
        foreach ($emps as $emp) {
            $out[$emp->id] = self::compose(
                $emp, $date,
                collect($attByEmp->get($emp->id) ?? []),
                collect($emp->user_id ? ($repByUser->get($emp->user_id) ?? []) : [])
            );
        }
        return $out;
    }

    /* ═══════════ التركيب — آلةُ الحالاتِ الواحدة ═══════════ */

    protected static function compose(Employee $emp, string $date, Collection $atts, Collection $reports): array
    {
        $primary = $atts->last(fn ($a) => (bool) $a->time_in) ?: $atts->first();
        $checkedIn = $atts->contains(fn ($a) => (bool) $a->time_in);
        $timeIn = $atts->pluck('time_in')->filter()->sort()->first();
        $timeOut = $atts->pluck('time_out')->filter()->sort()->last();
        $checkedOut = $checkedIn && $timeOut !== null;
        $hours = round((float) $atts->sum(fn ($a) => (float) $a->hours), 2);

        // الحضورُ الفيزيائيّ لا يُطمَس — والقديمُ «بلا تقرير» يُقرأ حضوراً
        $physical = self::physicalStatus($primary);
        $onLeave = Workday::onLeave((string) $emp->id, $date);

        // التقريرُ الصالح (§13) — بندٌ واحدٌ صالحٌ على الأقل
        $valid = $reports->filter(fn ($r) => self::isValidReportRow($r))->values();
        $reportSubmitted = $valid->isNotEmpty();
        $submittedAt = $valid->pluck('submitted_at')->filter()
            ->map(fn ($t) => Carbon::parse($t))->sort()->first();
        $projects = $valid->pluck('project_id')->filter()->unique()->values()->all();
        $hasNonProject = $valid->contains(fn ($r) => blank($r->project_id));
        $reportedHours = round((float) $valid->sum(fn ($r) => (float) $r->hours), 2);

        // مراجعةُ البنود (§27)
        $review = ['pending' => 0, 'accepted' => 0, 'needs_revision' => 0];
        foreach ($valid as $r) {
            $rs = (string) ($r->review_status ?: 'pending_review');
            if ($rs === 'accepted') $review['accepted']++;
            elseif ($rs === 'needs_revision') $review['needs_revision']++;
            else $review['pending']++;
        }

        // الاشتراط: مُفعَّلٌ في الإعداد، وليس يومَ إجازةٍ معتمدة (§44)
        $reportRequired = ((string) setting('work.report_required', '1') === '1') && ! $onLeave;

        // المهلة (§9/§50) — تُشتقّ حيّاً فتستجيب لتغيّرِ الإعداد فوراً (§117)
        $deadline = self::computeDeadline($date, $timeOut, $checkedOut);
        $now = BusinessDate::now();
        $pastDeadline = $deadline !== null && $now->gt($deadline);
        $late = $reportSubmitted && $deadline !== null && $submittedAt !== null && $submittedAt->gt($deadline);

        $policy = self::policy();

        // ── القفلُ اليدويّ/الفعّالُ المُثبَّت (§41/§90): لا يُعاد كتابتُه صامتاً ──
        $finalizedAt = $primary?->compliance_finalized_at;
        $finalizedBy = $primary?->compliance_finalized_by;
        $lockedOutcome = $primary ? ($primary->compliance_outcome ?: null) : null;
        $finalized = $finalizedAt !== null && $lockedOutcome !== null;

        // ── آلةُ الحالات ──
        [$state, $compliance] = self::deriveState(
            $onLeave, $checkedIn, $checkedOut, $reportRequired,
            $reportSubmitted, $pastDeadline, $late
        );

        // الأثرُ الفعّال — القفلُ يعلو، وإلّا يُشتقّ من الحالةِ والسياسة
        if ($finalized) {
            $effective = $lockedOutcome;
        } else {
            $effective = self::deriveEffective($state, $onLeave, $checkedIn, $reportSubmitted, $policy);
        }

        return [
            'employee'      => $emp,
            'date'          => $date,
            'attendance'    => $primary,
            'attendances'   => $atts,
            'multi'         => $atts->count() > 1,                       // §47
            'checked_in'    => $checkedIn,
            'checked_out'   => $checkedOut,
            'time_in'       => $timeIn,
            'time_out'      => $timeOut,
            'hours'         => $hours,
            'physical'      => $physical,                                // §6.A
            'on_leave'      => $onLeave,
            'report_required' => $reportRequired,
            'review_required' => self::reviewRequired(),                // §85
            'report_submitted' => $reportSubmitted,                     // §6.B
            'report_count'  => $valid->count(),
            'submitted_at'  => $submittedAt,
            'projects'      => $projects,
            'has_non_project' => $hasNonProject,                        // §16
            'reported_hours' => $reportedHours,
            'review'        => $review,
            'deadline_at'   => $deadline,
            'grace_remaining_minutes' => ($deadline && ! $pastDeadline)
                ? (int) ceil($now->diffInSeconds($deadline, false) / 60) : null,
            'past_deadline' => $pastDeadline,
            'late'          => $late,
            'compliance'    => $compliance,                             // ممتثل/بانتظار/ناقص/متأخر/غير مطلوب
            'state'         => $state,                                  // الحالةُ القانونيّة (§11)
            'effective'     => $effective,                             // §6.C
            'finalized'     => $finalized,
            'finalized_at'  => $finalizedAt,
            'finalized_by'  => $finalizedBy,
            'finalized_note' => $primary?->compliance_note,
            'needs_review'  => $reportSubmitted && ($review['pending'] > 0 || $review['needs_revision'] > 0),
            'reason'        => self::reason($state, $effective, $deadline),
            'labels'        => self::labels($physical, $state, $effective, $compliance),
        ];
    }

    /**
     * آلةُ الحالات — تُعيد [state, compliance]. لا حالةٌ متناقضة (§11).
     */
    protected static function deriveState(
        bool $onLeave, bool $checkedIn, bool $checkedOut, bool $reportRequired,
        bool $reportSubmitted, bool $pastDeadline, bool $late
    ): array {
        if ($onLeave) return [self::NOT_REQUIRED, 'not_required'];

        // لا حضورَ إطلاقاً (§45: تقريرٌ بلا حضورٍ يبقى غياباً فيزيائيّاً — الحقائقُ منفصلة)
        if (! $checkedIn) return [self::ABSENT, $reportSubmitted ? 'reported' : 'none'];

        // الاشتراطُ مُطفأ ⇒ لا التزامَ تقريريّ
        if (! $reportRequired) return [self::NOT_REQUIRED, 'not_required'];

        if ($reportSubmitted) {
            return $late
                ? [self::LATE_REPORT, 'late']
                : [self::PRESENT_REPORTED, 'compliant'];
        }

        // بلا تقرير:
        if (! $checkedOut) return [self::CHECKED_IN, 'pending'];        // §43/§106 ورديةٌ مفتوحة
        if (! $pastDeadline) return [self::REPORT_PENDING, 'pending'];  // §8 قبلَ المهلة
        return [self::PRESENT_WITHOUT_REPORT, 'missing'];               // §38 بعدَ المهلة
    }

    /**
     * الأثرُ الفعّالُ للموارد البشرية — الحضورُ الفيزيائيُّ يبقى، والسياسةُ تحكم
     * «حضورٌ بلا تقرير» وحدَه (§7/§12).
     */
    protected static function deriveEffective(
        string $state, bool $onLeave, bool $checkedIn, bool $reportSubmitted, string $policy
    ): string {
        if ($onLeave) return 'leave';
        if (! $checkedIn) return 'absent';                             // فيزيائيّاً غائب (وإن قدّم تقريراً — §45)

        if ($state === self::PRESENT_WITHOUT_REPORT) {
            return match ($policy) {
                self::POLICY_ABSENCE => 'absent_due_to_missing_report',
                self::POLICY_NON_COMPLIANT => 'non_compliant',
                default => 'present',                                  // warning_only
            };
        }

        // present_reported / report_pending / checked_in / late_report / not_required ⇒ حاضرٌ فعلاً
        return 'present';
    }

    /* ────────── المهلة (§9/§50) ────────── */

    /**
     * مهلةُ التقرير: أبعدُ نقطتين — (الانصراف + سماحية) و(الحدُّ اليوميّ النهائيّ) —
     * فالألطفُ للموظّف (§8: لا عقوبةَ قبلَ المهلة). ورديةٌ مفتوحةٌ بلا حدٍّ نهائيّ ⇒
     * لا مهلةَ بعد (§46). عبورُ منتصفِ الليل يبقى منسوباً ليومِ العملِ الأصليّ (§50).
     */
    public static function computeDeadline(string $date, ?string $timeOut, bool $checkedOut): ?Carbon
    {
        $grace = max(0, (int) setting('work.report_grace_minutes', 120));
        $cutoff = BusinessDate::at($date, (string) setting('work.report_cutoff_time', ''));

        $candidates = [];
        if ($checkedOut && $timeOut) {
            $out = BusinessDate::at($date, $timeOut);
            if ($out) $candidates[] = $out->copy()->addMinutes($grace);
        }
        if ($cutoff) $candidates[] = $cutoff;

        if (! $candidates) return null;
        return collect($candidates)->sort()->last();                   // الأبعد
    }

    /** السياسةُ من الإعداد — مُتحقَّقٌ منها (§9). الحرفيّ 'absence_equivalent' يطابق الكتالوج */
    public static function policy(): string
    {
        $p = (string) setting('work.missing_report_policy', 'absence_equivalent');
        return in_array($p, [self::POLICY_WARNING, self::POLICY_NON_COMPLIANT, self::POLICY_ABSENCE], true)
            ? $p : self::POLICY_ABSENCE;
    }

    /** هل مراجعةُ المدير مطلوبةٌ للتقارير؟ (§85) — قارئٌ حيٌّ لمفتاح work.review_required */
    public static function reviewRequired(): bool
    {
        return (string) setting('work.review_required', '0') === '1';
    }

    /**
     * صياغةٌ آمنةٌ للـJSON (الواجهة/الجوال §93/§94) — نفسُ المُحلِّل، بلا نموذجِ
     * الموظّفِ وبتواريخَ نصّيّة. مصدرٌ واحدٌ للويبِ والواجهة (§101).
     */
    public static function apiShape(array $c): array
    {
        return [
            'date'            => $c['date'],
            'checked_in'      => $c['checked_in'],
            'checked_out'     => $c['checked_out'],
            'time_in'         => $c['time_in'],
            'time_out'        => $c['time_out'],
            'attendance_hours' => $c['hours'],
            'physical_status' => $c['physical'],
            'on_leave'        => $c['on_leave'],
            'report_required' => $c['report_required'],
            'report_submitted' => $c['report_submitted'],
            'report_count'    => $c['report_count'],
            'submitted_at'    => optional($c['submitted_at'])->toIso8601String(),
            'reported_hours'  => $c['reported_hours'],
            'projects'        => array_values($c['projects']),
            'has_non_project' => $c['has_non_project'],
            'deadline_at'     => optional($c['deadline_at'])->toIso8601String(),
            'grace_remaining_minutes' => $c['grace_remaining_minutes'],
            'past_deadline'   => $c['past_deadline'],
            'late'            => $c['late'],
            'compliance'      => $c['compliance'],
            'state'           => $c['state'],
            'effective_status' => $c['effective'],
            'finalized'       => $c['finalized'],
            'needs_review'    => $c['needs_review'],
            'review'          => $c['review'],
            'reason'          => $c['reason'],
            'labels'          => $c['labels'],
        ];
    }

    /* ────────── الحضورُ الفيزيائيّ (القديمُ «بلا تقرير» يُقرأ حضوراً) ────────── */

    protected static function physicalStatus(?Attendance $row): ?string
    {
        if (! $row) return null;
        $s = (string) $row->status;
        // توافقٌ رجعيّ: صفوفٌ قديمةٌ خُتمت «حاضر — بلا تقرير» تُقرأ حضوراً فيزيائيّاً
        if ($s === Workday::NO_REPORT) return Workday::PRESENT;
        return $s ?: ($row->time_in ? Workday::PRESENT : null);
    }

    /* ────────── العرضُ العربيّ ────────── */

    protected static function reason(string $state, string $effective, ?Carbon $deadline): string
    {
        return match ($state) {
            self::NOT_REQUIRED => 'لا تقريرَ مطلوبٌ اليوم (إجازة/عطلة أو الاشتراطُ مُطفأ)',
            self::ABSENT => 'لا حضورَ مسجَّلٌ في يومِ عمل',
            self::CHECKED_IN => 'وردية مفتوحة — التقريرُ ما زال ممكناً',
            self::REPORT_PENDING => $deadline
                ? 'التقريرُ لم يُقدَّم بعد — المهلةُ حتى ' . $deadline->format('H:i')
                : 'التقريرُ لم يُقدَّم بعد',
            self::PRESENT_REPORTED => 'حاضرٌ وقدّم تقريرَه — ممتثل',
            self::PRESENT_WITHOUT_REPORT => 'حضورٌ فعليٌّ — بدون تقرير، ويُحتسب '
                . ($effective === 'absent_due_to_missing_report' ? 'غيابًا لعدم تقديم التقرير' : 'مخالفةَ امتثال'),
            self::LATE_REPORT => 'قُدِّم التقريرُ متأخّراً — يحتاج مراجعةَ المدير/الموارد البشرية',
            self::ABSENT_DUE_TO_MISSING_REPORT => 'غياب بسبب عدم تقديم التقرير',
            default => '',
        };
    }

    /** بطاقاتُ عرضٍ جاهزةٌ للشاشات (نصٌّ عربيٌّ لكلِّ حقيقة) */
    protected static function labels(?string $physical, string $state, string $effective, string $compliance): array
    {
        $eff = match ($effective) {
            'present' => 'حاضر',
            'leave' => 'إجازة',
            'absent' => 'غائب',
            'absent_due_to_missing_report' => 'غياب بسبب عدم تقديم التقرير',
            'non_compliant' => 'غيرُ ممتثل',
            'excused' => 'معذور',
            default => $effective,
        };
        $comp = match ($compliance) {
            'compliant' => 'مقدَّم',
            'pending' => 'بانتظار التقديم',
            'missing' => 'غيرُ مقدَّم',
            'late' => 'مقدَّم متأخّراً',
            'not_required' => 'غيرُ مطلوب',
            'reported' => 'مقدَّم (بلا حضور)',
            default => 'غيرُ مقدَّم',
        };
        return [
            'physical'   => $physical ?: 'لا حضور',
            'compliance' => $comp,
            'effective'  => $eff,
        ];
    }
}
