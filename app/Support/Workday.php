<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **محرك يوم العمل — منطقُ الحضور كلُّه في مكانٍ واحد.**
 *
 * كان الحضور صفَّ إدخالٍ يدويّ: وقتان نصيّان وساعاتٌ تُكتب باليد ولا زرَّ
 * حضورٍ أصلاً. صار يوماً حياً: الموظف يسجّل حضوره بضغطة (بوضعه ومشروعه
 * وعميله)، والانصرافُ يحسب الساعات، والحالةُ النهائية تُقيَّم من الواقع كله —
 * الجدول والإجازة المعتمدة والتقرير اليومي.
 *
 * **القاعدة الذهبية: غيابُ التقرير ≠ غياب.** من حضر ونسي الكتابة حالتُه
 * «حاضر — بلا تقرير» تُراجَع، والغيابُ حصراً لمن كان يومُ عملٍ مجدولٌ عليه
 * ولم يحضر ولا إجازةَ له — ويختمه كنسُ نهاية اليوم لا لحظةُ التقييم.
 *
 * ليس منتجَ مراقبة: لا تتبعَ مستمراً — يُسجَّل عنوانُ الشبكة والجهاز لحظةَ
 * الحضور والانصراف فقط (كما يسجّلهما الدخولُ أصلاً)، ضمن meta لا في عمودٍ
 * يُعرض لكل قارئ.
 */
class Workday
{
    /** حالاتُ اليوم — تطابق خيارات وحدة الحضور حرفياً */
    public const PRESENT = 'حاضر';
    public const LATE = 'متأخر';
    public const ABSENT = 'غائب';
    public const LEAVE = 'إجازة';
    public const REMOTE = 'عمل عن بعد';
    public const FIELD = 'عمل ميداني';
    public const NO_REPORT = 'حاضر — بلا تقرير';

    /** أوضاعُ العمل التي تُحسب حضوراً عن بُعد/ميدانياً في التقييم */
    public const REMOTE_MODES = ['عن بعد' => self::REMOTE, 'عمل ميداني' => self::FIELD];

    /* ────────── الجسر: المستخدم ← ملفه الوظيفي ────────── */

    public static function emp(?User $user): ?Employee
    {
        if (! $user) return null;

        return Employee::whereNull('deleted_at')->where('user_id', $user->id)
            ->where('status', 'نشط')->orderBy('id')->first();
    }

    /** صفُّ اليوم للموظف — أو null */
    public static function today(string $empId, ?string $date = null): ?Attendance
    {
        return Attendance::whereNull('deleted_at')->where('emp_id', $empId)
            ->whereDate('date', $date ?? now()->toDateString())
            ->orderBy('id')->first();
    }

    /* ────────── الحضور ────────── */

    /**
     * تسجيلُ الحضور — يكتب لسجل صاحبه وحده، فلا يحتاج صلاحيةَ وحدة الحضور:
     * كبوابة الموظف الذاتية تماماً. النداءُ الثاني في اليوم نفسه لا يكرر صفاً.
     */
    public static function checkIn(User $user, array $in = []): array
    {
        $emp = self::emp($user);
        if (! $emp) return ['ok' => false, 'msg' => 'لا ملفَ موظفٍ نشطاً مربوطاً بحسابك — اطلب من الموارد البشرية ربطه من حقل «حساب النظام»'];

        $row = self::today($emp->id);
        if ($row && $row->time_in) {
            return ['ok' => false, 'msg' => 'حضورُك اليوم مسجَّلٌ منذ ' . $row->time_in, 'row' => $row];
        }

        $now = now();
        $start = (string) setting('sec.hours_start', '08:00');
        $grace = max(0, (int) setting('work.late_grace', 15));
        // مقارنةٌ بدقائق اليوم لا بنصٍّ: بدايةٌ قرب منتصف الليل + سماحية كانت
        // تلتفّ إلى «00:14» فيُوسم النهارُ كلُّه متأخراً
        [$sh, $sm] = array_map('intval', array_pad(explode(':', $start), 2, 0));
        $late = ((int) $now->format('H') * 60 + (int) $now->format('i')) > ($sh * 60 + $sm + $grace);

        $mode = in_array($in['mode'] ?? '', ['مكتب', 'عن بعد', 'موقع عميل', 'عمل ميداني', 'مهمة خارجية'], true)
            ? $in['mode'] : 'مكتب';

        $status = self::REMOTE_MODES[$mode] ?? ($late ? self::LATE : self::PRESENT);

        // **مشروع/عميلُ الحضورِ ضمنَ نطاقِ الموظّف** (Permissions 360 · 10.4): قيمةٌ خارجَ
        // نطاقِه تُتجاهَل — فلا يُربَطُ حضورُه بمشروعٍ/عميلٍ لا يراه (صفٌّ ذاتيٌّ، لكنّ المرجعَ
        // يُنطَّق كسائرِ الكتابات). النطاقُ الشاملُ يمرّ بلا تغيير.
        $pid = $in['project_id'] ?? null;
        if ($pid && ! hub_scope(\App\Models\Project::query(), 'projects', $user)->whereKey($pid)->exists()) {
            $pid = null;
        }
        $cid = $in['client_id'] ?? null;
        if ($cid && ! hub_scope(\App\Models\Client::query(), 'clients', $user)->whereKey($cid)->exists()) {
            $cid = null;
        }

        $row = $row ?: new Attendance(['emp_id' => $emp->id, 'date' => $now->toDateString()]);
        $row->fill([
            'time_in' => $now->format('H:i'),
            'mode' => $mode,
            'client_id' => $cid,
            'project_id' => $pid,
            'company_id' => $emp->company_id,
            'status' => $status,
            'meta' => array_merge((array) $row->meta, ['checkin' => array_filter([
                'ip' => request()?->ip(),
                'device' => hub_fit((string) request()?->userAgent(), 200),
                'geo' => (setting('work.geo', '0') === '1' && ($in['geo'] ?? null)) ? $in['geo'] : null,
            ])]),
        ]);
        $row->save();

        hub_audit('تسجيل حضور', 'attend', $row->id, $emp->name,
            ['after' => array_filter(['mode' => $mode, 'in' => $row->time_in, 'project' => $in['project_id'] ?? null])]);

        return ['ok' => true, 'msg' => 'سُجّل حضورُك ' . $row->time_in . ($late ? ' — متأخراً عن ' . $start : ''), 'row' => $row];
    }

    /** الانصراف: يحسب الساعات ويختم الحالةَ النهائية من الواقع كله */
    public static function checkOut(User $user): array
    {
        $emp = self::emp($user);
        if (! $emp) return ['ok' => false, 'msg' => 'لا ملفَ موظفٍ مربوطاً بحسابك'];

        $row = self::today($emp->id);
        if (! $row || ! $row->time_in) return ['ok' => false, 'msg' => 'لا حضورَ مسجَّلاً اليوم — سجّل حضورَك أولاً'];
        if ($row->time_out) return ['ok' => false, 'msg' => 'انصرافُك مسجَّلٌ منذ ' . $row->time_out, 'row' => $row];

        $now = now();
        $mins = (strtotime($now->format('H:i')) - strtotime((string) $row->time_in)) / 60;
        if ($mins < 0) $mins += 24 * 60;                     // وردية تعبر منتصف الليل

        $row->time_out = $now->format('H:i');
        $row->hours = round(max(0, $mins) / 60, 2);
        // الحالةُ فيزيائيّةٌ محضة — لا تُطمَس بغيابِ التقرير (§6)
        $row->status = self::evaluate($row, $user);
        // مهلةُ التقرير تُختم عند الانصراف (§53): للعرضِ ولمرشّحِ أمرِ المصالحة (§51)
        if (Schema::hasColumn('attendance', 'report_deadline_at')) {
            $deadline = \App\Support\DailyWorkCompliance::computeDeadline(
                (string) ($row->date?->toDateString() ?? $row->date), $row->time_out, true);
            $row->report_deadline_at = $deadline;
        }
        $row->meta = array_merge((array) $row->meta, ['checkout' => array_filter([
            'ip' => request()?->ip(), 'device' => hub_fit((string) request()?->userAgent(), 200),
        ])]);
        $row->save();

        hub_audit('تسجيل انصراف', 'attend', $row->id, $emp->name,
            ['after' => ['out' => $row->time_out, 'hours' => $row->hours, 'status' => $row->status]]);

        // رسالةُ الانصراف (§64): إن كان التقريرُ ما زال ناقصاً وواجباً، أخبِرْ بالمهلة —
        // بلا حجبِ الانصراف، ومن المُحلِّلِ المركزيّ لا من عمودِ حالةٍ مطموس
        $c = \App\Support\DailyWorkCompliance::resolve($emp, (string) ($row->date?->toDateString() ?? $row->date));
        $pendingNote = '';
        if (in_array($c['state'], [\App\Support\DailyWorkCompliance::REPORT_PENDING,
            \App\Support\DailyWorkCompliance::PRESENT_WITHOUT_REPORT], true)) {
            $pendingNote = '. تقريرُ اليوم لم يُقدَّم بعد'
                . ($c['deadline_at'] ? '، ويجب تقديمُه قبل ' . $c['deadline_at']->format('H:i')
                    . ' — بعدها يُحتسب اليومُ غيابًا لعدم تقديم التقرير' : ' — أضِف بنودَ يومك من تحديثات العمل');
        }

        return ['ok' => true, 'row' => $row, 'compliance' => $c,
            'msg' => 'انصرفتَ ' . $row->time_out . ' — ' . $row->hours . ' ساعة' . $pendingNote];
    }

    /**
     * الحالةُ الفيزيائيّة النهائية لليوم — **حضورٌ لا امتثال** (§6).
     *
     * إجازةٌ معتمدة تغلب، ثم متأخّر/ميدانيّ/عن بعد/حاضر. **لا يطمس هذا العمودُ
     * غيابَ التقرير أبداً** بعد اليوم: الامتثالُ التقريريُّ والأثرُ الفعّال يُشتقّان
     * مركزيّاً في `DailyWorkCompliance` (فيُعاد تقييمُهما لحظةَ تقديمِ التقريرِ ولو
     * بعد الانصراف). كان هذا الموضعُ يُرجع «حاضر — بلا تقرير» فيدهس الحضورَ ولا
     * يُعاد أبداً — جذرُ العيب (ROOT_CAUSE.md).
     */
    public static function evaluate(Attendance $row, ?User $user = null): string
    {
        if (self::onLeave((string) $row->emp_id, (string) ($row->date?->toDateString() ?? $row->date))) {
            return self::LEAVE;
        }

        $base = self::REMOTE_MODES[$row->mode] ?? null;
        return in_array($row->status, [self::LATE], true) ? self::LATE : ($base ?: self::PRESENT);
    }

    /** أَعلى الموظفِ إجازةٌ معتمدةٌ من أنواع الغياب تشمل هذا اليوم؟ */
    public static function onLeave(string $empId, string $date): bool
    {
        if (! Schema::hasTable('leave_requests')) return false;

        return LeaveRequest::whereNull('deleted_at')->where('emp_id', $empId)
            ->where('status', 'معتمد')
            ->whereIn('type', config('hub.leave.deduct_types', []))
            ->whereDate('date_from', '<=', $date)
            ->where(fn ($q) => $q->whereDate('date_to', '>=', $date)->orWhereNull('date_to'))
            ->exists();
    }

    /**
     * كنسُ نهاية اليوم (الأتمتة): من كان يومُ أمسٍ يومَ عملٍ عليه ولم يسجّل
     * حضوراً ولا إجازةَ معتمدةً له — يُختم غائباً. idempotent: الصفُّ الموجود
     * لا يُمسّ، والعطلةُ الأسبوعية ليست غياباً.
     */
    public static function close(?string $date = null): int
    {
        $date = $date ?? now()->subDay()->toDateString();
        if (! Schema::hasTable('attendance') || ! Schema::hasTable('employees')) return 0;
        // عطلة الأسبوع من الإعداد نفسه الذي تقرؤه hub_workdays — لا غياب في عطلة
        $weekend = array_map('intval', array_filter(explode(',', (string) setting('cost.weekend', '5,6'))));
        if (in_array((int) date('N', strtotime($date)), $weekend, true)) return 0;

        $n = 0;
        Employee::whereNull('deleted_at')->where('status', 'نشط')
            ->orderBy('id')->chunkById(100, function ($emps) use ($date, &$n) {
                foreach ($emps as $emp) {
                    if (self::today($emp->id, $date)) continue;
                    if (self::onLeave($emp->id, $date)) continue;
                    Attendance::create([
                        'emp_id' => $emp->id, 'date' => $date, 'status' => self::ABSENT,
                        'company_id' => $emp->company_id,
                        'notes' => 'ختم آلي: يوم عمل بلا حضور ولا إجازة معتمدة',
                    ]);
                    $n++;
                }
            });

        return $n;
    }

    /* ────────── شاشة المدير: فريقي اليوم ────────── */

    public static function teamToday(): array
    {
        return hub_screen('wd:team', 120, fn () => self::teamCalc(), ['attendance', 'work_updates', 'employees']);
    }

    protected static function teamCalc(): array
    {
        if (! hub_can(auth()->user(), 'hr', 'v') || ! Schema::hasTable('employees')) {
            return ['rows' => [], 'n' => []];
        }

        $today = now()->toDateString();
        $emps = hub_company_scope(hub_scope(Employee::query(), 'hr'), 'hr')
            ->whereNull('deleted_at')->where('status', 'نشط')
            ->orderBy('name')->get(['id', 'name', 'dept', 'user_id']);

        // الحلُّ الجماعيُّ المركزيّ (§101 · N+1=0): استعلامان لا استعلامٌ لكلِّ صف
        $comp = \App\Support\DailyWorkCompliance::resolveMany($emps, $today);

        // بلاغاتُ العوائق: تُقرأ مع بنودِ اليوم دفعةً واحدة (لا استعلامَ لكلِّ موظف)
        $blockersByUser = DB::table('work_updates')->whereNull('deleted_at')
            ->whereDate('work_date', $today)
            ->whereIn('created_by', $emps->pluck('user_id')->filter())
            ->whereNotNull('problems')->where('problems', '!=', '')
            ->get(['created_by'])->groupBy('created_by');

        $projects = hub_ref_labels('projects',
            collect($comp)->pluck('projects')->flatten(1)->filter()->unique()->values()->all());

        $rows = [];
        $n = ['emps' => $emps->count(), 'in' => 0, 'noreport' => 0, 'leave' => 0,
            'field' => 0, 'absent' => 0, 'late' => 0, 'none' => 0, 'hours' => 0.0, 'blockers' => 0,
            'reported' => 0, 'pending' => 0, 'missing' => 0, 'absence_report' => 0];

        foreach ($emps as $e) {
            $c = $comp[$e->id];
            $a = $c['attendance'];
            $blockers = (int) ($blockersByUser->get($e->user_id)?->count() ?? 0);

            if ($c['checked_in']) $n['in']++;
            // «تقريرٌ ناقص» = حاضرٌ بلا تقريرٍ صالح (بانتظار أو ناقص) — تتبع الإشارةَ القائمة
            if ($c['checked_in'] && ! $c['report_submitted'] && ! $c['on_leave']) $n['noreport']++;
            if ($c['report_submitted']) $n['reported']++;
            if ($c['compliance'] === 'pending') $n['pending']++;
            if ($c['state'] === \App\Support\DailyWorkCompliance::PRESENT_WITHOUT_REPORT) $n['missing']++;
            if ($c['effective'] === 'absent_due_to_missing_report') $n['absence_report']++;
            if ($c['on_leave']) $n['leave']++;
            if (in_array($c['physical'], [self::FIELD, self::REMOTE], true)) $n['field']++;
            if ($c['physical'] === self::ABSENT || (! $c['checked_in'] && $a)) $n['absent']++;
            if ($c['physical'] === self::LATE) $n['late']++;
            if (! $a) $n['none']++;
            $n['hours'] += $c['reported_hours'];
            $n['blockers'] += $blockers;

            $rows[] = [
                'emp' => $e, 'att' => $a, 'comp' => $c,
                'entries' => $c['report_count'], 'hours' => $c['reported_hours'],
                'blockers' => $blockers,
                'projects' => collect($c['projects'])->map(fn ($pid) => $projects[$pid] ?? '—')->values()->all(),
            ];
        }

        $n['hours'] = round($n['hours'], 1);

        return ['rows' => $rows, 'n' => $n, 'date' => $today];
    }

    /** بطاقةُ الموظف الذاتية (ودجة الرئيسية): حالُ يومي وبنودُه */
    public static function mine(User $user): ?array
    {
        $emp = self::emp($user);
        if (! $emp) return null;

        $row = self::today($emp->id);
        $entries = DB::table('work_updates')->whereNull('deleted_at')
            ->where('created_by', $user->id)->whereDate('work_date', now()->toDateString())
            ->get(['project_id', 'hours']);

        return [
            'emp' => $emp, 'att' => $row,
            'entries' => $entries->count(),
            'hours' => round((float) $entries->sum('hours'), 1),
            'projects' => hub_ref_options_scoped('projects', null, $user),
            'clients' => hub_can($user, 'clients', 'v') ? hub_ref_options_scoped('clients', null, $user) : [],
        ];
    }
}
