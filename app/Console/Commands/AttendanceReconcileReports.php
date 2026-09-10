<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Employee;
use App\Support\DailyWorkCompliance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **مصالحةُ تقارير الحضور — شبكةُ الأمان المجدولة (§52).**
 *
 * الاشتقاقُ حيٌّ في `DailyWorkCompliance` (§53)، وهذا الأمرُ شبكةُ أمانٍ لا محرّكٌ
 * ثانٍ: يجد **الأيامَ المرشَّحةَ فقط** — فات موعدُها ولم تُقفَل بعد (فهرسُ
 * `report_deadline_at, compliance_finalized_at`، بلا مسحٍ لكلِّ تاريخٍ كلَّ دقيقة
 * §51) — ويصالح امتثالَها، ويُشعِر بالتقريرِ الناقصِ **مرّةً واحدةً** لكلِّ
 * (موظّف/يوم/نوع) عبر مسنَدِ الوجودِ في `notifications_hub` (§39) — لا تكرارَ مهما
 * أُعيد التشغيل. لا يُغيّر ختمَ الحضور/الانصراف، ولا يقفل الأثرَ (القفلُ قرارُ
 * إنسانٍ §41) — يُشعِر ويترك الاشتقاقَ حيّاً كي يقبل تقريراً متأخّراً بلا رجعةٍ صامتة.
 */
class AttendanceReconcileReports extends Command
{
    protected $signature = 'attendance:reconcile-reports {--dry : عرض ما سيحدث دون كتابة} {--days=3 : أيامُ النافذة الخلفية}';
    protected $description = 'مصالحةُ امتثالِ تقارير الحضور للأيام التي فات موعدُها + تنبيهاتٌ لا تتكرّر';

    public function handle(): int
    {
        if (! Schema::hasTable('attendance') || ! Schema::hasColumn('attendance', 'report_deadline_at')) {
            return self::SUCCESS;
        }
        $dry = (bool) $this->option('dry');
        // السياسةُ warning_only لا تُنشئ مخالفةً — لا داعيَ للتنبيه
        $policy = DailyWorkCompliance::policy();
        $notify = ((string) setting('work.report_reminder', '1') === '1') && $policy !== DailyWorkCompliance::POLICY_WARNING;

        $lookback = max(1, (int) $this->option('days'));
        $since = \App\Support\BusinessDate::now()->subDays($lookback)->toDateString();
        $nowTs = now()->toDateTimeString();

        // المرشّحون فقط (§51): حضورٌ فات موعدُ تقريرِه ولم يُقفَل — نافذةٌ خلفيّةٌ محدودة
        $candidates = Attendance::whereNull('deleted_at')
            ->whereNotNull('time_in')
            ->whereNull('compliance_finalized_at')
            ->whereNotNull('report_deadline_at')
            ->where('report_deadline_at', '<', $nowTs)
            ->whereDate('date', '>=', $since)
            ->orderBy('id')->limit(2000)->get();

        $scanned = 0; $missing = 0; $notified = 0;
        // الموظّفون دفعةً (§99 · لا استعلامَ لكلِّ صف)
        $emps = Employee::whereNull('deleted_at')
            ->whereIn('id', $candidates->pluck('emp_id')->unique()->filter()->all())
            ->get()->keyBy('id');

        foreach ($candidates as $row) {
            $scanned++;
            $emp = $emps->get($row->emp_id);
            if (! $emp) continue;

            $date = (string) ($row->date instanceof \DateTimeInterface ? $row->date->format('Y-m-d') : $row->date);
            $c = DailyWorkCompliance::resolve($emp, $date);

            if ($c['state'] !== DailyWorkCompliance::PRESENT_WITHOUT_REPORT) continue;   // قُدِّم/إجازة/… — لا مخالفة
            $missing++;
            if (! $notify || $dry || ! $emp->user_id) continue;

            // §39 مسنَدُ الوجود: إشعارٌ واحدٌ لكلِّ (موظّف/يوم) — record_id = صفُّ الحضور
            $exists = DB::table('notifications_hub')->where('user_id', $emp->user_id)
                ->where('kind', 'daily_report_missing')->where('record_id', $row->id)->exists();
            if ($exists) continue;

            hub_notify($emp->user_id, 'daily_report_missing',
                '⚠️ يوم ' . $date . ': حضورٌ فعليٌّ بلا تقرير، ويُحتسب '
                    . ($c['effective'] === 'absent_due_to_missing_report' ? 'غيابًا لعدم تقديم التقرير' : 'مخالفةَ امتثال')
                    . '. قدّم تقريرَك من «تقرير اليوم» في مساحة عملك — وستُراجَع حالتُك.',
                'attend', $row->id);
            $notified++;
        }

        $this->info("مصالحةُ التقارير: {$scanned} مرشَّحاً · {$missing} حضورٌ بلا تقريرٍ بعد المهلة · {$notified} تنبيهٌ جديد"
            . ($dry ? ' (معاينة)' : ''));

        return self::SUCCESS;
    }
}
