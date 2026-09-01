<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\ActionCenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * طبقةُ الذكاء — إنهاءُ العالق الموثوقِ بياناتُه (v2.397):
 *  • خرقُ SLA من `hub_sla` القائمة (لا محرّكَ SLA ثانٍ).
 *  • انحرافُ النطاق من `meta.baseline` القائم (الأصل + أوامرُ التغيير المطبَّقة).
 *  • تصرّفٌ لكلِّ مستخدمٍ على إشارة «تقريرٌ ناقص» التجميعيّة.
 *
 * كلٌّ يُشتقّ من بياناتٍ موجودةٍ فعلاً — لا اختلاقَ ولا مؤقّتاتٍ جديدة.
 */
class IntelligencePendingSignalsTest extends TestCase
{
    private function ticket(array $a): string
    {
        $id = (string) Str::uuid();
        DB::table('tickets')->insert(array_merge([
            'id' => $id, 'subject' => 'تذكرة', 'priority' => 'متوسطة', 'status' => 'مفتوحة',
            'created_at' => now(), 'updated_at' => now(),
        ], $a));

        return $id;
    }

    // خرقُ SLA
    public function test_sla_breach_signal_from_existing_calculator(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        // متأخرةٌ: أُنشئت قبل ١٠ أيام، سياسةُ «متوسطة» تحلّ خلال ٧٢ ساعة → تجاوزت
        $late  = $this->ticket(['subject' => 'خرقٌ واضح', 'created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)]);
        // حديثةٌ: لم تتجاوز موعدها
        $fresh = $this->ticket(['subject' => 'حديثة']);
        // متأخرةٌ لكنّها حُلّت → لا خرقَ مفتوح
        $done  = $this->ticket(['subject' => 'محلولة', 'status' => 'تم الحل',
            'created_at' => now()->subDays(10), 'updated_at' => now()->subDay()]);

        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertContains('sla.breach:' . $late, $keys, 'الخرقُ الواضحُ لم يُرصَد');
        $this->assertNotContains('sla.breach:' . $fresh, $keys, 'رُصدت تذكرةٌ لم تتجاوز موعدها');
        $this->assertNotContains('sla.breach:' . $done, $keys, 'رُصدت تذكرةٌ محلولة');
    }

    public function test_sla_breach_clears_on_reply_within_window(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        // «عاجلة» تحلّ خلال ٨ ساعات — أُنشئت قبل يومين بلا حلّ → خرقٌ
        $t = $this->ticket(['subject' => 'عاجلةٌ متأخرة', 'priority' => 'عاجلة',
            'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->all();
        $this->assertContains('sla.breach:' . $t, $keys);

        // تُحَلّ → تزول الإشارة (حلٌّ تلقائيّ، لا حاجةَ لتصرّفٍ يدويّ)
        DB::table('tickets')->where('id', $t)->update(['status' => 'تم الحل', 'updated_at' => now()]);
        $keys2 = collect(hub_recommendations(true)['items'])->pluck('key')->all();
        $this->assertNotContains('sla.breach:' . $t, $keys2, 'الخرقُ لم يُحَلّ بعد حلِّ التذكرة');
    }

    // انحرافُ النطاق
    public function test_scope_drift_signal_from_baseline(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $drifted = Project::create(['name' => 'مشروعٌ منحرف', 'status' => 'قيد التنفيذ',
            'meta' => ['baseline' => ['amount' => 10000,
                'change_orders' => [['value_delta' => 3000], ['value_delta' => 500]]]]]);   // ٣٥٪
        $minor   = Project::create(['name' => 'تغييرٌ طفيف', 'status' => 'قيد التنفيذ',
            'meta' => ['baseline' => ['amount' => 10000, 'change_orders' => [['value_delta' => 1000]]]]]);   // ١٠٪
        $nobase  = Project::create(['name' => 'بلا خطِّ أساس', 'status' => 'قيد التنفيذ']);

        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertContains('scope.drift:' . $drifted->id, $keys, 'الانحرافُ الجوهريّ (٣٥٪) لم يُرصَد');
        $this->assertNotContains('scope.drift:' . $minor->id, $keys, 'رُصد تغييرٌ طفيفٌ دون العتبة');
        $this->assertNotContains('scope.drift:' . $nobase->id, $keys, 'رُصد مشروعٌ بلا خطِّ أساس');
    }

    // تصرّفٌ لكلِّ مستخدمٍ على «تقريرٌ ناقص»
    public function test_missing_report_disposition_is_per_user(): void
    {
        $this->seedCore();

        $secRole = Role::create(['name' => 'مدير HR ثانٍ', 'scope' => 'all',
            'flags' => ['monitor' => 1], 'matrix' => Role::first()->matrix]);
        $sec = User::create(['name' => 'مديرٌ ثانٍ', 'email' => 'hr2@test.local',
            'password' => 'Secret!2026x', 'role_id' => $secRole->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $emp = Employee::create(['name' => 'حاضرٌ بلا تقرير', 'status' => 'نشط', 'user_id' => $this->employee->id]);
        Attendance::create(['emp_id' => $emp->id, 'date' => now()->toDateString(),
            'time_in' => '09:00', 'status' => 'حاضر']);

        $day = now()->toDateString();
        $ownerKey = 'report.missing:' . $day . ':u' . $this->owner->id;
        $secKey   = 'report.missing:' . $day . ':u' . $sec->id;

        // المالكُ يُخفي إشارته
        $this->actingAs($this->owner);
        $this->assertContains($ownerKey, collect(ActionCenter::signals(true)['visible'])->pluck('key')->all());
        $this->assertTrue(ActionCenter::disposition($ownerKey, 'dismiss'));
        $this->assertNotContains($ownerKey, collect(ActionCenter::signals(true)['visible'])->pluck('key')->all(),
            'لم تُخفَ للمالك بعد إخفائه');

        // المديرُ الثاني لا يزال يراها — التصرّفُ مستقلٌّ لكلِّ مستخدم
        $this->actingAs($sec);
        $this->assertContains($secKey, collect(ActionCenter::signals(true)['visible'])->pluck('key')->all(),
            'إخفاءُ المالك أخفاها عن مديرٍ آخر (تصرّفٌ مشترَكٌ خاطئ)');
    }
}
