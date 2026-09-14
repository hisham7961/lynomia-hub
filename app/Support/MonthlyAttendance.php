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
 * التمييزُ الحاسمُ في السجلّ (الجولة ١ · F10): يومٌ بلا صفِّ حضورٍ ولا إجازةٍ يُصنَّف
 * بطبيعتِه لا بقالبٍ واحد — **المستقبلُ** «—» (لم يقع بعد)، و**عطلةُ الأسبوع** (من
 * إعدادِ `cost.weekend` نفسِه الذي تقرؤه `hub_workdays`) «عطلة»، و**اليومُ الجاري**
 * «—» (لم ينتهِ)، و**يومُ العملِ الماضي** وحدَه «غائب» — محسوباً بالفرقِ لا بانتظارِ
 * صفٍّ مختومٍ لن يكتبَه أحد. فلا تُحسَب العطلُ ولا المستقبلُ غياباً في كشفِ الراتب.
 *
 * عقدُ العدّادات قائمٌ (الإضافةُ لا الكسر): `absent` يبقى للصفِّ المختومِ «غائب»
 * حصراً، و`off` مظلّةُ كلِّ يومٍ غيرِ مسجَّل كما كان — والجديدُ عدّاداتٌ منفصلة:
 * `unexcused` (غيابٌ مشتقٌّ من الفرق)، و`missing_out` (شذوذُ «انصرافٍ مفقود» —
 * دخولٌ بلا انصرافٍ في يومٍ ماضٍ · F11) — ظاهرةٌ لا ساقطةٌ بصمت.
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

    /**
     * أيامُ عطلةِ الأسبوع (ترقيمُ ISO: ٥=الجمعة ٦=السبت) — من إعدادِ `cost.weekend`
     * نفسِه الذي تقرؤه `hub_workdays` وكنسُ نهايةِ اليوم: مصدرٌ واحدٌ للعطلة.
     */
    public static function weekendDays(): array
    {
        $off = array_filter(array_map('intval', preg_split('/[،,\s]+/u',
            (string) setting('cost.weekend', '5,6'), -1, PREG_SPLIT_NO_EMPTY)));

        return $off ?: [5, 6];
    }

    /** هل التاريخُ 'Y-m-d' يقع في عطلةِ الأسبوع؟ */
    public static function isWeekend(string $date): bool
    {
        return in_array((int) date('N', strtotime($date)), self::weekendDays(), true);
    }

    /* ═══════════ سجلُّ موظّفٍ واحدٍ في شهر ═══════════ */

    public static function sheet(Employee $emp, string $month): array
    {
        $month = self::normMonth($month);
        $dates = self::daysOf($month);
        $cells = DailyWorkCompliance::resolveRange(collect([$emp]), $dates)[$emp->id] ?? [];

        $rows = []; $tot = self::zeroTotals(); $today = BusinessDate::today();
        foreach ($dates as $d) {
            $c = $cells[$d] ?? null;
            $row = self::classify($d, $c, $today);
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

        $out = []; $today = BusinessDate::today();
        foreach ($emps as $emp) {
            $tot = self::zeroTotals();
            foreach ($dates as $d) {
                $c = $range[$emp->id][$d] ?? null;
                self::accumulate($tot, self::classify($d, $c, $today), $c);
            }
            $tot['reported_hours'] = round($tot['reported_hours'], 2);
            $tot['attendance_hours'] = round($tot['attendance_hours'], 2);
            $out[$emp->id] = ['employee' => $emp, 'totals' => $tot];
        }
        return ['month' => $month, 'rows' => $out];
    }

    /* ────────── تصنيفُ اليوم ────────── */

    /**
     * صنفُ يومٍ للعرض والعدّ (F10). للصفوفِ الفعليّة: leave/present/absent (مختوم).
     * ولليومِ بلا صفٍّ ولا إجازة — بطبيعتِه: **future** (مستقبلٌ · «—»)، **holiday**
     * (عطلةُ الأسبوع · «عطلة»)، **off** (اليومُ الجاري لم ينتهِ · «—»)، **unexcused**
     * (يومُ عملٍ ماضٍ بلا ختمٍ وبلا إجازة · «غائب» — بالفرق). ولا حالةَ محتسبةً
     * تُعرَض لِما لم يقع (مستقبل/عطلة/اليوم الجاري).
     */
    protected static function classify(string $date, ?array $c, string $today): array
    {
        if (! $c) {
            return ['date' => $date, 'kind' => 'off', 'label' => '—', 'tone' => '',
                'time_in' => null, 'time_out' => null, 'hours' => 0.0, 'missing_out' => false,
                'att_id' => null, 'report' => null, 'effective' => null, 'effective_key' => null];
        }
        $kind = $c['on_leave'] ? 'leave'
            : ($c['checked_in'] ? 'present'
                : ($c['attendance'] ? 'absent'
                    : ($date > $today ? 'future'
                        : (self::isWeekend($date) ? 'holiday'
                            : ($date === $today ? 'off' : 'unexcused')))));

        // مستقبلٌ/عطلةٌ/يومٌ جارٍ: لا «غائب» محتسبًا يُفترى على ما لم يقع (F10)
        $noVerdict = in_array($kind, ['future', 'holiday', 'off'], true);

        return [
            'date' => $date,
            'kind' => $kind,
            'label' => match ($kind) {
                'leave' => 'إجازة', 'absent' => 'غائب', 'unexcused' => 'غائب',
                'holiday' => 'عطلة', 'future' => '—', 'off' => '—',
                default => $c['labels']['physical'] ?? 'حاضر',
            },
            'tone' => match ($kind) {
                'present' => 'ok', 'leave' => 'ac', 'absent' => 'bad', 'unexcused' => 'bad',
                default => '',
            },
            'time_in' => $c['time_in'], 'time_out' => $c['time_out'],
            'hours' => (float) $c['hours'],
            // F11: دخولٌ بلا انصرافٍ في يومٍ ماضٍ — شذوذٌ يُوسَم، لا ساعاتٌ تُختلق
            'missing_out' => $c['checked_in'] && ! $c['checked_out'] && $date < $today,
            'att_id' => $c['attendance']?->id,
            'report' => $kind === 'present' ? $c['labels']['compliance'] : null,
            'compliance_key' => $c['compliance'] ?? null,
            'effective' => $noVerdict ? null : ($c['labels']['effective'] ?? null),
            'effective_key' => $noVerdict ? null : ($c['effective'] ?? null),
            'late' => (bool) ($c['late'] ?? false),
            'state' => $c['state'] ?? null,
        ];
    }

    protected static function zeroTotals(): array
    {
        return ['present' => 0, 'late' => 0, 'field' => 0, 'leave' => 0, 'absent' => 0,
            'reported' => 0, 'missing' => 0, 'absence_report' => 0, 'off' => 0,
            'future' => 0, 'holiday' => 0, 'unexcused' => 0, 'missing_out' => 0,
            'attendance_hours' => 0.0, 'reported_hours' => 0.0, 'workdays' => 0];
    }

    protected static function accumulate(array &$t, array $row, ?array $c): void
    {
        $t[$row['kind']] = ($t[$row['kind']] ?? 0) + 1;   // present/leave/absent/off/future/holiday/unexcused
        // المظلّةُ القديمة باقية (الإضافةُ لا الكسر): كلُّ يومٍ غيرِ مسجَّلٍ يُحصى «off»
        // كما كان — فعقدُ «العطلُ غيرُ المسجَّلة ليست غياباً في كشف الراتب» لا يُمسّ
        if (in_array($row['kind'], ['future', 'holiday', 'unexcused'], true)) $t['off']++;
        if ($row['missing_out'] ?? false) $t['missing_out']++;   // F11: شذوذُ الشهرِ ظاهرٌ
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
