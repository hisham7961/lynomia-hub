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

    /**
     * حالاتُ الطلبِ **قيدَ القرار** (الجولة ٢ · G12): لا معتمَدٌ ولا مرفوضٌ ولا ملغى —
     * تطابق خيارات وحدة `leaves` حرفيّاً. صاحبُها ليس «غائباً بلا عذر»: قرارُه
     * معلَّقٌ على مديرِه/الموارد البشرية، ومراسلتُه كغائبٍ ظلمٌ وبيانٌ كاذب.
     */
    public const PENDING_REQUEST_STATUSES = ['مقدّم', 'موافقة المدير', 'موافقة الموارد البشرية'];

    /**
     * أنواعُ الطلباتِ التي **تفسّر** غيابَ اليوم: إجازاتُ الخصم (من `hub.leave`)
     * زائدَ «إذن خروج» و«عمل عن بعد». ما عداها (سلفة/شهادة راتب) طلبٌ إداريٌّ
     * لا يفسّر غياباً — فلا يُخرج صاحبَه من النداء.
     */
    public static function excuseTypes(): array
    {
        return array_values(array_unique(array_merge(
            (array) config('hub.leave.deduct_types', []), ['إذن خروج', 'عمل عن بعد'])));
    }

    /**
     * **الأعذارُ التي ليست إجازةَ خصم** — «إذن خروج» و«عمل عن بعد» وما يُضاف بعدَهما.
     *
     * وهذا الفرقُ هو مَحلُّ العطل: `on_leave` يقرأ `deduct_types` وحدَها (سؤالُ
     * **رصيدٍ ورواتب**)، فمن عذرُه من هذين النوعين لا يُعدّ في إجازةٍ — ثمّ يسقط
     * في «غائبٌ بلا عذر» لأنّ لا فئةَ ثالثةَ له. والطلبُ **قيدَ القرار** كان أرحمَ
     * حالاً من المعتمَد (G12 تُنصفه) — فكان الاعتمادُ عقوبة.
     */
    public static function nonLeaveExcuseTypes(): array
    {
        return array_values(array_diff(self::excuseTypes(), (array) config('hub.leave.deduct_types', [])));
    }

    /**
     * خريطةُ الأعذارِ المعتمَدةِ غيرِ الخصميّة لمجموعةِ موظّفين — استعلامٌ واحدٌ لا N+1.
     * تُعيد `[emp_id => نوعُ العذر]`. و`date_to` الفارغُ عذرٌ مفتوحُ النهاية.
     */
    public static function excusedMap(array $empIds, string $from, ?string $to = null): array
    {
        $to = $to ?: $from;
        $types = self::nonLeaveExcuseTypes();
        if (! $empIds || ! $types || ! \Illuminate\Support\Facades\Schema::hasTable('leave_requests')) return [];

        $out = [];
        $rows = \App\Models\LeaveRequest::whereNull('deleted_at')->whereIn('emp_id', $empIds)
            ->where('status', 'معتمد')->whereIn('type', $types)
            ->whereDate('date_from', '<=', $to)
            ->where(fn ($q) => $q->whereDate('date_to', '>=', $from)->orWhereNull('date_to'))
            ->orderBy('id')->get(['emp_id', 'type', 'date_from', 'date_to']);
        foreach ($rows as $r) {
            $out[$r->emp_id][] = ['type' => (string) $r->type,
                'from' => self::dstr($r->date_from), 'to' => $r->date_to ? self::dstr($r->date_to) : null];
        }

        return $out;
    }

    /** نوعُ العذرِ المعتمَدِ غيرِ الخصميِّ لموظّفٍ في يومٍ بعينه — أو `null` */
    public static function excuseFor($empId, string $date, ?array $map = null): ?string
    {
        $rows = $map !== null ? ($map[$empId] ?? []) : (self::excusedMap([$empId], $date)[$empId] ?? []);
        foreach ($rows as $r) {
            if ($r['from'] <= $date && ($r['to'] === null || $r['to'] >= $date)) return $r['type'];
        }

        return null;
    }

    /**
     * **المسنَدُ الواحدُ لسؤالِ «من ليس على رأسِ عملِه اليومَ ولماذا؟»** تقرؤه لوحةُ
     * المالكِ بدل استعلامِها الخاصّ (`status LIKE '%معتمد%'` بلا تصفيةِ نوعٍ أصلاً —
     * فكان طلبُ «سلفة» أو «شهادة راتب» معتمدٌ يضع صاحبَه «في إجازةِ اليوم»).
     *
     * تُعيد صفوفاً `[name, type, to, kind]` حيث `kind` إمّا `leave` (إجازةُ خصم)
     * أو `excused` (عذرٌ غيرُ خصميّ) — فيقرأ المالكُ التمييزَ ولا يفقد أحداً.
     */
    public static function onLeaveToday(?string $date = null, $companyId = null): Collection
    {
        $date = $date ?: BusinessDate::today();
        if (! \Illuminate\Support\Facades\Schema::hasTable('leave_requests')) return collect();

        $deduct = (array) config('hub.leave.deduct_types', []);

        return \App\Models\LeaveRequest::query()->whereNull('leave_requests.deleted_at')
            ->where('leave_requests.status', 'معتمد')
            ->whereIn('leave_requests.type', self::excuseTypes())
            ->whereDate('leave_requests.date_from', '<=', $date)
            ->where(fn ($q) => $q->whereDate('leave_requests.date_to', '>=', $date)
                ->orWhereNull('leave_requests.date_to'))
            // العمودُ مؤهَّلٌ لأنّ الجدولين يحملان `company_id` — وغيرُ المؤهَّلِ
            // يرمي «ambiguous column» على المحرّكين
            ->join('employees', 'employees.id', '=', 'leave_requests.emp_id')
            ->whereNull('employees.deleted_at')
            ->when($companyId, fn ($q) => $q->where('employees.company_id', $companyId))
            ->orderBy('employees.name')->orderBy('leave_requests.id')
            ->get(['employees.name as name', 'leave_requests.type as type', 'leave_requests.date_to as to'])
            ->map(function ($r) use ($deduct) {
                $r->kind = in_array((string) $r->type, $deduct, true) ? 'leave' : 'excused';

                return $r;
            });
    }

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

        return self::withOpenShift([(string) $emp->id => self::compose($emp, $date, $atts, $reports)],
            $date)[(string) $emp->id];
    }

    /**
     * تعبئةُ `open_shift` لخلايا يومٍ واحد — استعلامٌ واحدٌ للجماعةِ كلِّها.
     * لا يُملأ إلّا لليومِ الجاري (السؤالُ عن «الآن»)، ولا لمن له ختمُ اليومِ أصلاً.
     */
    protected static function withOpenShift(array $cells, string $date): array
    {
        if ($date !== BusinessDate::today() || ! $cells) return $cells;

        foreach (Workday::openCrossingByEmp(array_keys($cells)) as $empId => $row) {
            $c = $cells[$empId] ?? null;
            if (! $c || $c['checked_in'] || $c['attendance']) continue;

            $cells[$empId]['open_shift'] = [
                'date' => (string) ($row->date?->toDateString() ?? $row->date),
                'time_in' => (string) $row->time_in,
                'since' => substr((string) $row->time_in, 0, 5),
            ];
        }

        return $cells;
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

        return self::withOpenShift($out, $date);
    }

    /**
     * الحلُّ المدَيَّ (§99 · N+1=0): موظّفون × مدىً من التواريخ (شهرٌ مثلاً) — ثلاثةُ
     * استعلاماتٍ فقط (حضور + بنود + إجازات) لا استعلامٌ لكلِّ خليّة. يُعيد مصفوفةً
     * مفتاحُها `employee.id → 'Y-m-d' → نتيجةُ اليوم`. أساسُ العرض الشهريّ للمحاسب.
     *
     * @param  array<int,string>  $dates  قائمةُ تواريخِ 'Y-m-d' مرتّبة
     */
    public static function resolveRange(Collection $emps, array $dates): array
    {
        if ($emps->isEmpty() || ! $dates) return [];
        $from = $dates[0]; $to = $dates[count($dates) - 1];
        $empIds = $emps->pluck('id')->all();
        $userIds = $emps->pluck('user_id')->filter()->all();

        $attByCell = Attendance::whereNull('deleted_at')->whereIn('emp_id', $empIds)
            ->whereBetween('date', [$from, $to])->orderBy('id')->get()
            ->groupBy(fn ($a) => $a->emp_id . '|' . self::dstr($a->date));

        $repByCell = $userIds
            ? WorkUpdate::whereNull('deleted_at')->whereIn('created_by', $userIds)
                ->whereBetween('work_date', [$from, $to])->orderBy('submitted_at')->orderBy('id')->get()
                ->groupBy(fn ($w) => $w->created_by . '|' . self::dstr($w->work_date))
            : collect();

        // خريطةُ الإجازاتِ المعتمدةِ (أنواعُ الخصم) المتداخلةِ مع المدى — لكلِّ موظّف
        $leaveRows = collect();
        if (\Illuminate\Support\Facades\Schema::hasTable('leave_requests')) {
            $leaveRows = \App\Models\LeaveRequest::whereNull('deleted_at')->whereIn('emp_id', $empIds)
                ->where('status', 'معتمد')->whereIn('type', config('hub.leave.deduct_types', []))
                ->whereDate('date_from', '<=', $to)
                ->where(fn ($q) => $q->whereDate('date_to', '>=', $from)->orWhereNull('date_to'))
                ->get(['emp_id', 'date_from', 'date_to'])->groupBy('emp_id');
        }
        $onLeave = function ($empId, string $date) use ($leaveRows): bool {
            foreach ($leaveRows->get($empId) ?? [] as $r) {
                $f = self::dstr($r->date_from); $t = $r->date_to ? self::dstr($r->date_to) : null;
                if ($f <= $date && ($t === null || $t >= $date)) return true;
            }
            return false;
        };

        // الأعذارُ غيرُ الخصميّةِ للمجموعةِ كلِّها — استعلامٌ واحدٌ كخريطةِ الإجازات
        $excusedMap = self::excusedMap($empIds, $from, $to);

        $out = [];
        foreach ($emps as $emp) {
            foreach ($dates as $date) {
                $out[$emp->id][$date] = self::compose(
                    $emp, $date,
                    collect($attByCell->get($emp->id . '|' . $date) ?? []),
                    collect($emp->user_id ? ($repByCell->get($emp->user_id . '|' . $date) ?? []) : []),
                    $onLeave($emp->id, $date),
                    $excusedMap
                );
            }
        }
        return $out;
    }

    /** تاريخٌ نصّيّ 'Y-m-d' من قيمةٍ مقيَّدةٍ أو نص */
    protected static function dstr($d): string
    {
        return (string) ($d instanceof \DateTimeInterface ? $d->format('Y-m-d') : substr((string) $d, 0, 10));
    }

    /* ═══════════ نداءُ اليوم (الجولة ١ · F9) ═══════════ */

    /**
     * **«من غائبٌ اليوم؟» — الإجابةُ بالفرقِ لا بانتظارِ صفوفٍ لن يكتبَها أحد:**
     * النشطون − من له ختمُ حضورٍ − من في إجازةٍ معتمدةٍ تشمل اليوم = غائبٌ بلا عذر.
     *
     * الفئاتُ الخمس بالأسماء: `present` (ختمَ في الوقت)، `late` (ختمَ بعد بدايةِ
     * الدوام `sec.hours_start` + سماحيةِ `work.late_grace` — يُشتقُّ من وقتِ الختمِ
     * نفسِه فلا يفلت صفٌّ يدويٌّ بلا وسم، والميدانيُّ/عن بعد لا يُوسَم متأخراً كما
     * في `Workday::checkIn`)، `leave`، `absent` (بلا ختمٍ وبلا إجازة — أو صفٌّ
     * مختومٌ «غائب»)، `noreport` (ختمَ ولم يقدّم تقريراً صالحاً والاشتراطُ قائم).
     * العطلةُ الأسبوعية ليست غياباً: يومُ عطلةٍ يُعلَّم `weekend` وتخلو فئةُ الغياب.
     *
     * تقرؤه شاشةُ «فريقي اليوم» وبطاقةُ «الفريق اليوم» في لوحة CEO — مصدرٌ واحدٌ
     * فلا تتناقض شاشتان. التنطيقُ على العاتقِ المستدعي: مرِّر موظّفين منطَّقين.
     *
     * **ولا حكمَ قبلَ أوانه** (الجولة ٢ · G12): في الرابعةِ فجراً أعلن النداءُ
     * اثنين وثلاثين موظّفاً «غائباً بلا عذر» — والدوامُ لم يبدأ، وفيهم المالكُ
     * والمتفرّجُ نفسُه. المعادلةُ صادقةٌ حسابيّاً وميتةٌ سياقيّاً. فقبلَ
     * `sec.hours_start` + سماحيةِ `work.late_grace` **لا يُعلَن أحدٌ غائباً**:
     * `not_started = true` ومن لم يختم في فئةِ `not_yet` («لم يصل بعد»). ومن له
     * طلبٌ قيدَ القرارِ يشمل اليومَ (إذنُ خروجٍ بموافقةِ مديرٍ ينتظر الموارد مثلاً)
     * في فئةِ `pending` («بانتظار قرار») لا في الغياب. والصفُّ المختومُ صراحةً
     * («غائب» من كنسِ نهايةِ اليوم) حكمٌ قائمٌ لا تؤجّله الساعةُ ولا يُخفيه طلب.
     */
    public static function rollCall(Collection $emps, ?string $date = null): array
    {
        $date = $date ?: BusinessDate::today();
        $cells = self::resolveMany($emps, $date);
        $weekend = MonthlyAttendance::isWeekend($date);

        // بدايةُ الدوام إن وُجد مفهومُها في الإعدادات — نفسُ قراءةِ Workday::checkIn
        $start = trim((string) setting('sec.hours_start', '08:00'));
        $grace = max(0, (int) setting('work.late_grace', 15));
        $startMin = null;
        if ($start !== '' && preg_match('/^\d{1,2}:\d{2}/', $start)) {
            [$sh, $sm] = array_map('intval', array_pad(explode(':', $start), 2, 0));
            $startMin = $sh * 60 + $sm + $grace;
        }

        // **قبلَ بدءِ الدوام لا نداءَ غياب** (G12): اليومُ الجاريُّ وحدَه — واليومُ
        // الماضي انقضى فحكمُه واقعٌ لا انتظار
        $nowAt = BusinessDate::now();
        $notStarted = $date === BusinessDate::today() && $startMin !== null
            && ((int) $nowAt->format('H') * 60 + (int) $nowAt->format('i')) < $startMin;

        // الطلباتُ قيدَ القرارِ الشاملةُ لليوم — استعلامٌ واحدٌ للمجموعة (لا N+1)
        $pendingReq = [];
        if ($emps->isNotEmpty() && \Illuminate\Support\Facades\Schema::hasTable('leave_requests')) {
            $pendingReq = \App\Models\LeaveRequest::whereNull('deleted_at')
                ->whereIn('emp_id', $emps->pluck('id')->all())
                ->whereIn('status', self::PENDING_REQUEST_STATUSES)
                ->whereIn('type', self::excuseTypes())
                ->whereDate('date_from', '<=', $date)
                ->where(fn ($q) => $q->whereDate('date_to', '>=', $date)->orWhereNull('date_to'))
                ->orderBy('id')->pluck('emp_id')->flip()->all();
        }

        $buckets = ['present' => [], 'late' => [], 'leave' => [], 'excused' => [], 'absent' => [],
            'noreport' => [], 'pending' => [], 'not_yet' => []];
        $anyStamp = false;

        // **ومن ورديّتُه عبرت منتصفَ الليل ليس غائباً** (التحقّقُ العاشر · N-13):
        // نداءُ اليومِ كان يضعه في سلّةِ الغياب بينما بطاقتُه تعرض «انصراف».
        // استعلامٌ واحدٌ للجماعة — الجوابُ نفسُه الذي يسأله الحارسُ والشاشة.
        $crossing = $date === BusinessDate::today()
            ? Workday::openCrossingByEmp($emps->pluck('id'))
            : [];

        foreach ($emps as $emp) {
            $c = $cells[$emp->id] ?? null;
            if (! $c) continue;
            if ($c['attendance']) $anyStamp = true;                  // أيُّ صفٍّ (ولو «غائب» مختوماً) بيانات
            $entry = ['id' => (string) $emp->id, 'name' => (string) $emp->name];

            if ($c['on_leave']) { $buckets['leave'][] = $entry; continue; }

            if (! $c['checked_in'] && ! $c['attendance'] && isset($crossing[(string) $emp->id])) {
                $buckets['present'][] = $entry;
                continue;
            }

            if ($c['checked_in']) {
                $late = $c['physical'] === Workday::LATE;
                if (! $late && $startMin !== null && $c['time_in'] && $c['physical'] === Workday::PRESENT) {
                    [$h, $m] = array_map('intval', array_pad(explode(':', (string) $c['time_in']), 3, 0));
                    $late = ($h * 60 + $m) > $startMin;
                }
                $buckets[$late ? 'late' : 'present'][] = $entry;
                if ($c['report_required'] && ! $c['report_submitted']) $buckets['noreport'][] = $entry;
                continue;
            }

            // لا ختمَ ولا إجازة (أو صفٌّ مختومٌ «غائب»): غائبٌ — في يومِ عملٍ فقط
            if ($weekend) continue;

            // **«مأذون» قبلَ كلِّ شيء** (الخاتمة · X1): عذرٌ معتمَدٌ غيرُ خصميٍّ
            // («إذن خروج»/«عمل عن بعد») — ليس غياباً بلا عذرٍ وليس إجازة. ويسبق
            // حتّى الصفَّ المختومَ «غائب»: ذاك ختمُ كنسٍ آليٍّ قرأ `onLeave` وحدَها
            // فأدان صاحبَ العذر، فلا يُقلَّد حكماً يعلو على اعتمادِ الموارد البشرية.
            if (! empty($c['excused'])) { $buckets['excused'][] = $entry; continue; }

            // الصفُّ المختومُ صراحةً حكمٌ قائم؛ وما دونَه يُؤجَّل أو يُعلَّق قبلَ أن يُدان
            if (! $c['attendance']) {
                if (isset($pendingReq[$emp->id])) { $buckets['pending'][] = $entry; continue; }
                if ($notStarted) { $buckets['not_yet'][] = $entry; continue; }
            }
            $buckets['absent'][] = $entry;
        }

        // ترتيبٌ دلاليٌّ بالاسم — لا اعتمادَ على ترتيبِ إدراجٍ يقرعه المحرّكان
        foreach ($buckets as &$b) usort($b, fn ($x, $y) => strcmp($x['name'], $y['name']));
        unset($b);

        $n = ['emps' => $emps->count(),
            'in' => count($buckets['present']) + count($buckets['late'])];
        foreach ($buckets as $k => $b) $n[$k] = count($b);

        return ['date' => $date, 'weekend' => $weekend, 'any_stamp' => $anyStamp,
            'not_started' => $notStarted,
            'start_at' => $start !== '' ? $start : null,
            'buckets' => $buckets, 'n' => $n];
    }

    /* ═══════════ التركيب — آلةُ الحالاتِ الواحدة ═══════════ */

    protected static function compose(Employee $emp, string $date, Collection $atts, Collection $reports, ?bool $onLeaveOverride = null, ?array $excusedMap = null): array
    {
        /*
         * **والصفُّ الأوّلُ بمعنًى لا بقرعة** (التحقّقُ الحادي عشر): كان
         * `$atts->last(fn …)` على مجموعةٍ مرتّبةٍ بـ`orderBy('id')` — و`id` عشوائيّ.
         * فكانت حالةُ اليومِ تنقلب بين «حاضر» و«متأخر» بتبديلِ بادئةِ UUID وحدَها،
         * **في الكشفِ الذي يغذّي الرواتب** — على بُعدِ سطرين من `$outRow` الذي
         * طُهّر في v2.515. تعريفٌ واحدٌ الآن يسأله الاثنان: `Workday::pickRow()`.
         */
        $primary = Workday::pickRow($atts) ?: $atts->first();
        $checkedIn = $atts->contains(fn ($a) => (bool) $a->time_in);
        $timeIn = $atts->pluck('time_in')->filter()->sort()->first();
        /*
         * **الانصرافُ والعبورُ من الصفِّ الواحد** (التحقّقُ المستقلّ العاشر · ع‑د).
         * كان `time_out` يُؤخذ **أكبرَ نصٍّ** في كلِّ صفوفِ اليوم، ورايةُ العبورِ من
         * **صفٍّ آخر** آخرِ الترتيب — و`attendance.id` هو `char(36)` عشوائيّ، فكان
         * `orderBy('id')` **قرعةً** تمنعها CLAUDE.md بالاسم: فارقُ أربعٍ وعشرين
         * ساعةً في المهلةِ على البياناتِ نفسِها، بتبديلِ بادئةِ UUID وحدَها.
         * والأسوأُ أنّ «أكبرَ نصّ» يُخطئ أصلاً في اليومِ العابر: «03:48» أصغرُ نصّاً
         * من أيِّ انصرافٍ نهاريّ فيُهمَل وهو الأخير واقعاً.
         *
         * فالترتيبُ الآن **بلحظةِ الانصرافِ الحقيقيّة** (`out_at`)، والقيمتانِ من
         * صفٍّ واحدٍ لا من صفَّين.
         */
        $outRow = $atts->filter(fn ($a) => $a->time_out !== null)
            ->sortBy(fn ($a) => $a->out_at
                ? $a->out_at->getTimestamp()
                : strtotime(((string) ($a->date?->toDateString() ?? $a->date)) . ' ' . $a->time_out))
            ->last();
        $timeOut = $outRow?->time_out;
        $checkedOut = $checkedIn && $timeOut !== null;
        $hours = round((float) $atts->sum(fn ($a) => (float) $a->hours), 2);

        // الحضورُ الفيزيائيّ لا يُطمَس — والقديمُ «بلا تقرير» يُقرأ حضوراً
        $physical = self::physicalStatus($primary);
        // الإجازةُ: تُمرَّر محسوبةً مسبقاً في الحلِّ المدَيَّ (§99 · بلا استعلامٍ لكلِّ يوم)
        $onLeave = $onLeaveOverride ?? Workday::onLeave((string) $emp->id, $date);
        // العذرُ غيرُ الخصميّ («إذن خروج»/«عمل عن بعد») حقيقةٌ **متعامدةٌ** على
        // الإجازة: لا يمسّ الرصيدَ ولا يُسقط تقريرَ اليوم — ويمنع وسمَ «بلا عذر»
        $excuse = $onLeave ? null : self::excuseFor($emp->id, $date, $excusedMap);

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
        // العبورُ من الصفِّ الحاملِ للانصرافِ نفسِه (المُختار أعلاه بترتيبٍ دلاليّ)
        $crossed = $outRow !== null && (bool) $outRow->overnight
            && $outRow->time_in !== null && (string) $outRow->time_out < (string) $outRow->time_in;
        $deadline = self::computeDeadline($date, $timeOut, $checkedOut, $crossed);
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
            $effective = self::deriveEffective($state, $onLeave, $checkedIn, $reportSubmitted, $policy,
                $excuse !== null);
        }

        return [
            'employee'      => $emp,
            'date'          => $date,
            /*
             * **حقلٌ إضافيٌّ لا قلبَ معنًى** (مجلسُ الخبراء · N-19): `state` جوابُ
             * «ماذا وقع في هذا اليوم» ويبقى كما هو — عقدٌ منشور. وهذا الحقلُ جوابُ
             * سؤالٍ آخرَ تعرضه التقارير: «أهو **الآن** على رأسِ عملِه؟». يُملأ
             * لليومِ الجاري وحدَه ومن الجوابِ الجماعيِّ نفسِه الذي تسأله البطاقةُ
             * وشاشةُ المدير. ويبقى مفتاحاً **موجوداً دائماً** (‏`null` حين لا ورديّة)
             * كي لا يبتلعَه `??` في قارئٍ فيمرَّ فارغاً.
             */
            'open_shift'    => null,
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
            'excused'       => $excuse !== null,                         // عذرٌ معتمَدٌ غيرُ خصميّ
            'excuse_type'   => $excuse,
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
        string $state, bool $onLeave, bool $checkedIn, bool $reportSubmitted, string $policy,
        bool $excused = false
    ): string {
        if ($onLeave) return 'leave';
        // **العذرُ المعتمَدُ يسبق حكمَ الغياب** (الخاتمة · X1ب): هذا العمودُ هو ما
        // يُصدَّر وما تُبنى عليه المحاسبةُ الشهريّة، فبقاؤه «غائباً» كان يُرسل
        // المأذونَ غائباً إلى الرواتب — وهو الضررُ الذي جاء الإصلاحُ ليمنعه.
        if ($excused && ! $checkedIn) return 'excused';
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
    public static function computeDeadline(string $date, ?string $timeOut, bool $checkedOut,
                                           bool $crossedMidnight = false): ?Carbon
    {
        $grace = max(0, (int) setting('work.report_grace_minutes', 120));
        // **الحدُّ الأقصى (cutoff) يبقى على يومِ العمل** — هو «قدّم تقريرَك قبل ساعةِ كذا
        // من يومِك»، لا من يومِ انصرافِك. أمّا مهلةُ الانصرافِ فتُبنى على لحظتِه الحقيقيّة.
        $cutoff = BusinessDate::at($date, (string) setting('work.report_cutoff_time', ''));

        $candidates = [];
        if ($checkedOut && $timeOut) {
            /*
             * **الورديّةُ العابرةُ تنصرف في اليومِ التالي** (التحقّقُ المستقلّ التاسع · ع‑٣):
             * كانت المهلةُ تُبنى من **تاريخِ الصفّ** وساعةِ الانصراف، فتقع قبلَ الانصرافِ
             * الحقيقيِّ باثنتين وعشرين ساعة — فيُدان موظّفُ الليلِ بتأخيرِ تقريرٍ قدّمه
             * في وقتِه، وتحت سياسةِ `absence_equivalent` يصير ذلك طريقاً إلى «غياب».
             */
            $outDate = $crossedMidnight
                ? \Illuminate\Support\Carbon::parse($date)->addDay()->toDateString()
                : $date;
            $out = BusinessDate::at($outDate, $timeOut);
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
            // إضافةٌ لا كسر: `state` كما هو، ومعه جوابُ «أهو الآن على رأسِ عملِه؟»
            'open_shift'      => $c['open_shift'] ?? null,
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
            // مفردةٌ واحدةٌ للحالةِ الواحدة: النداءُ يقول «مأذون» فلا يقول الجدولُ
            // «معذور» — لفظان لحالةٍ واحدةٍ عيبٌ عرفه هذا المستودعُ من قبل (v2.165)
            'excused' => 'مأذون',
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
