<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * **الحضورُ الشهريّ — سجلٌّ لكلِّ موظّفٍ شهراً بشهر (للمحاسبة والاعتماد).**
 *
 * نموذجُ قراءةٍ يُركّب فوق `DailyWorkCompliance` (لا محرّكَ حضورٍ ثانٍ · §122): لكلِّ
 * يومٍ من الشهرِ حالةُ الحضورِ الفيزيائيّ، والتقريرِ، والأثرِ المحتسَب (الذي يعكس ما
 * اعتمده المدير/الموارد البشرية §41). منه شاشةُ «الحضور الشهريّ» ومصفوفةُ المحاسب
 * والتصديرُ — كلُّها من مصدرٍ واحد، منطَّقةٌ شركةً وصلاحيّة.
 *
 * التمييزُ الحاسمُ في السجلّ: يومٌ **بلا صفِّ حضورٍ ولا إجازةٍ** ليس «غياباً» بل «غير
 * مسجَّل/عطلة» — الغيابُ حصراً لصفٍّ مختومٍ «غائب» (يختمه كنسُ نهاية اليوم لأيّامِ
 * العملِ فقط). فلا تُحسَب العطلُ غياباً في كشفِ الراتب.
 */
class MonthlyAttendance
{
    /** تواريخُ شهرٍ 'Y-m' كقائمةِ 'Y-m-d' — بتوقيتِ المنشأة */
    public static function daysOf(string $month): array
    {
        $first = Carbon::parse($month . '-01', BusinessDate::tz())->startOfMonth();
        $n = $first->daysInMonth;
        $out = [];
        for ($i = 0; $i < $n; $i++) $out[] = $first->copy()->addDays($i)->toDateString();
        return $out;
    }

    /** التحقّقُ من صيغةِ الشهرِ وإرجاعُ 'Y-m' صالحٍ (أو شهرِ اليوم) */
    public static function normMonth(?string $month): string
    {
        $month = trim((string) $month);
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            try { return Carbon::parse($month . '-01', BusinessDate::tz())->format('Y-m'); }
            catch (\Throwable $e) {}
        }
        return BusinessDate::now()->format('Y-m');
    }

    /* ═══════════ سجلُّ موظّفٍ واحدٍ في شهر ═══════════ */

    public static function sheet(Employee $emp, string $month): array
    {
        $month = self::normMonth($month);
        $dates = self::daysOf($month);
        $cells = DailyWorkCompliance::resolveRange(collect([$emp]), $dates)[$emp->id] ?? [];

        $rows = []; $tot = self::zeroTotals();
        foreach ($dates as $d) {
            $c = $cells[$d] ?? null;
            $row = self::classify($d, $c);
            self::accumulate($tot, $row, $c);
            $rows[] = $row;
        }
        $tot['reported_hours'] = round($tot['reported_hours'], 2);
        $tot['attendance_hours'] = round($tot['attendance_hours'], 2);

        return ['employee' => $emp, 'month' => $month, 'days' => $rows, 'totals' => $tot];
    }

    /* ═══════════ ملخّصٌ شهريٌّ لمجموعةِ موظّفين (شبكةُ المحاسب) ═══════════ */

    public static function summary(Collection $emps, string $month): array
    {
        $month = self::normMonth($month);
        $dates = self::daysOf($month);
        $range = DailyWorkCompliance::resolveRange($emps, $dates);

        $out = [];
        foreach ($emps as $emp) {
            $tot = self::zeroTotals();
            foreach ($dates as $d) {
                $c = $range[$emp->id][$d] ?? null;
                self::accumulate($tot, self::classify($d, $c), $c);
            }
            $tot['reported_hours'] = round($tot['reported_hours'], 2);
            $tot['attendance_hours'] = round($tot['attendance_hours'], 2);
            $out[$emp->id] = ['employee' => $emp, 'totals' => $tot];
        }
        return ['month' => $month, 'rows' => $out];
    }

    /* ────────── تصنيفُ اليوم ────────── */

    /**
     * صنفُ يومٍ للعرض والعدّ. يوم بلا صفِّ حضورٍ ولا إجازة = «off» (عطلة/غير مسجَّل)
     * لا غياب. الغيابُ حصراً لصفٍّ مختومٍ «غائب».
     */
    protected static function classify(string $date, ?array $c): array
    {
        if (! $c) {
            return ['date' => $date, 'kind' => 'off', 'label' => '—', 'tone' => '',
                'time_in' => null, 'time_out' => null, 'hours' => 0.0,
                'report' => null, 'effective' => null];
        }
        $kind = $c['on_leave'] ? 'leave'
            : ($c['checked_in'] ? 'present'
                : ($c['attendance'] ? 'absent' : 'off'));

        return [
            'date' => $date,
            'kind' => $kind,
            'label' => match ($kind) {
                'leave' => 'إجازة', 'absent' => 'غائب', 'off' => '—',
                default => $c['labels']['physical'] ?? 'حاضر',
            },
            'tone' => match ($kind) {
                'present' => 'ok', 'leave' => 'ac', 'absent' => 'bad', default => '',
            },
            'time_in' => $c['time_in'], 'time_out' => $c['time_out'],
            'hours' => (float) $c['hours'],
            'report' => $kind === 'present' ? $c['labels']['compliance'] : null,
            'compliance_key' => $c['compliance'] ?? null,
            'effective' => $c['labels']['effective'] ?? null,
            'effective_key' => $c['effective'] ?? null,
            'late' => (bool) ($c['late'] ?? false),
            'state' => $c['state'] ?? null,
        ];
    }

    protected static function zeroTotals(): array
    {
        return ['present' => 0, 'late' => 0, 'field' => 0, 'leave' => 0, 'absent' => 0,
            'reported' => 0, 'missing' => 0, 'absence_report' => 0, 'off' => 0,
            'attendance_hours' => 0.0, 'reported_hours' => 0.0, 'workdays' => 0];
    }

    protected static function accumulate(array &$t, array $row, ?array $c): void
    {
        $t[$row['kind']] = ($t[$row['kind']] ?? 0) + 1;   // present/leave/absent/off
        if (! $c) return;

        // أيامُ العملِ المسجَّلة (حضورٌ فعليّ) — تُميّز المتأخّرَ والميدانيّ
        if ($c['checked_in']) {
            $t['attendance_hours'] += (float) $c['hours'];
            if (($c['physical'] ?? '') === Workday::LATE) $t['late']++;
            if (in_array($c['physical'] ?? '', [Workday::FIELD, Workday::REMOTE], true)) $t['field']++;
            $t['workdays']++;
        }
        if ($c['report_submitted']) { $t['reported']++; $t['reported_hours'] += (float) $c['reported_hours']; }
        if (($c['state'] ?? '') === DailyWorkCompliance::PRESENT_WITHOUT_REPORT) $t['missing']++;
        if (($c['effective'] ?? '') === 'absent_due_to_missing_report') $t['absence_report']++;
    }
}
