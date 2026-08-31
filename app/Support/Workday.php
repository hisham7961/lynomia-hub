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

        $row = $row ?: new Attendance(['emp_id' => $emp->id, 'date' => $now->toDateString()]);
        $row->fill([
            'time_in' => $now->format('H:i'),
            'mode' => $mode,
            'client_id' => $in['client_id'] ?? null,
            'project_id' => $in['project_id'] ?? null,
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
        $row->status = self::evaluate($row, $user);
        $row->meta = array_merge((array) $row->meta, ['checkout' => array_filter([
            'ip' => request()?->ip(), 'device' => hub_fit((string) request()?->userAgent(), 200),
        ])]);
        $row->save();

        hub_audit('تسجيل انصراف', 'attend', $row->id, $emp->name,
            ['after' => ['out' => $row->time_out, 'hours' => $row->hours, 'status' => $row->status]]);

        $noReport = $row->status === self::NO_REPORT;

        return ['ok' => true, 'row' => $row,
            'msg' => 'انصرفتَ ' . $row->time_out . ' — ' . $row->hours . ' ساعة'
                . ($noReport ? '. تقريرُك اليومي لم يُكتب بعد — أضِف بنودَ يومك من تحديثات العمل' : '')];
    }

    /**
     * الحالةُ النهائية لليوم: إجازةٌ معتمدة تغلب، ثم حضورٌ بتقريرٍ أو بدونه —
     * وغيابُ التقرير حالةُ مراجعةٍ لا غياب (قابل للإطفاء من الإعدادات).
     */
    public static function evaluate(Attendance $row, ?User $user = null): string
    {
        if (self::onLeave((string) $row->emp_id, (string) ($row->date?->toDateString() ?? $row->date))) {
            return self::LEAVE;
        }

        $base = self::REMOTE_MODES[$row->mode] ?? null;
        $current = in_array($row->status, [self::LATE], true) ? self::LATE : ($base ?: self::PRESENT);

        if ((string) setting('work.report_required', '1') === '1' && $user) {
            $has = DB::table('work_updates')->whereNull('deleted_at')
                ->where('created_by', $user->id)
                ->whereDate('work_date', $row->date)
                ->exists();
            if (! $has) return self::NO_REPORT;
        }

        return $current;
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

        $att = Attendance::whereNull('deleted_at')->whereDate('date', $today)
            ->whereIn('emp_id', $emps->pluck('id'))->get()->keyBy('emp_id');

        $logs = DB::table('work_updates')->whereNull('deleted_at')
            ->whereDate('work_date', $today)
            ->whereIn('created_by', $emps->pluck('user_id')->filter())
            ->get(['created_by', 'project_id', 'hours', 'problems'])
            ->groupBy('created_by');

        $projects = hub_ref_labels('projects',
            $logs->flatten(1)->pluck('project_id')->filter()->unique()->values()->all());

        $rows = [];
        $n = ['emps' => $emps->count(), 'in' => 0, 'noreport' => 0, 'leave' => 0,
            'field' => 0, 'absent' => 0, 'late' => 0, 'none' => 0, 'hours' => 0.0, 'blockers' => 0];

        foreach ($emps as $e) {
            $a = $att->get($e->id);
            $mine = $e->user_id ? ($logs->get($e->user_id) ?? collect()) : collect();
            $blockers = $mine->pluck('problems')->filter(fn ($p) => trim((string) $p) !== '')->count();
            $hours = (float) $mine->sum('hours');

            $st = $a?->status;
            if ($a && $a->time_in) $n['in']++;
            if ($st === self::NO_REPORT || ($a && $a->time_in && $mine->isEmpty() && ! $a->time_out)) $n['noreport']++;
            if ($st === self::LEAVE) $n['leave']++;
            if (in_array($st, [self::FIELD, self::REMOTE], true)) $n['field']++;
            if ($st === self::ABSENT) $n['absent']++;
            if ($st === self::LATE) $n['late']++;
            if (! $a) $n['none']++;
            $n['hours'] += $hours;
            $n['blockers'] += $blockers;

            $rows[] = [
                'emp' => $e, 'att' => $a, 'entries' => $mine->count(), 'hours' => $hours,
                'blockers' => $blockers,
                'projects' => $mine->pluck('project_id')->filter()->unique()
                    ->map(fn ($pid) => $projects[$pid] ?? '—')->values()->all(),
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
