<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Project;
use App\Models\WorkUpdate;
use Tests\TestCase;

/**
 * طبقةُ الذكاء — المرحلة ٣: إشارةُ «تقريرٌ يوميٌّ ناقص».
 *
 * تُشتقّ من `Workday::teamToday` (لا محرّكَ حضورٍ ثانٍ)، منطَّقةٌ بمفتاح `hub_screen`
 * ومحروسةٌ بصلاحية hr. القاعدةُ الذهبية محفوظة: تقريرٌ ناقصٌ ≠ غياب.
 */
class IntelligenceReportComplianceTest extends TestCase
{
    public function test_missing_daily_report_surfaces_and_clears_when_report_filed(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        // موظفٌ حاضرٌ اليوم (time_in) بلا تقريرِ عمل → «حاضر بلا تقرير»
        $emp = Employee::create(['name' => 'حاضرٌ بلا تقرير', 'status' => 'نشط', 'user_id' => $this->employee->id]);
        Attendance::create(['emp_id' => $emp->id, 'date' => now()->toDateString(),
            'time_in' => '09:00', 'status' => 'حاضر']);

        // المفتاحُ صار لكلِّ مستخدمٍ على حِدة (تصرّفٌ مستقلٌّ لكلِّ مدير HR)
        $skey = 'report.missing:' . now()->toDateString() . ':u' . $this->owner->id;
        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertContains($skey, $keys, 'التقريرُ الناقصُ لم يُرصَد');

        // يقدّم الموظفُ تقريرَه → تزول الإشارة
        $p = Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $this->actingAs($this->employee);
        WorkUpdate::create(['project_id' => $p->id, 'done' => 'أنجزتُ المهمّة', 'hours' => 3]);

        $this->actingAs($this->owner);
        $keys2 = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertNotContains($skey, $keys2, 'الإشارةُ لم تُحَلّ بعد تقديم التقرير');
    }

    public function test_no_signal_when_everyone_reported(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        // لا حضورَ اليوم إطلاقاً → لا تقريرَ ناقص (الغيابُ شأنٌ آخر)
        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertNotContains('report.missing:' . now()->toDateString() . ':u' . $this->owner->id, $keys);
    }
}
