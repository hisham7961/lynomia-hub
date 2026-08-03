<?php

namespace Tests\Feature;

use App\Models\FinDocument;
use App\Models\HubNotification;
use App\Models\RecurringDoc;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * صحّة توليد الأتمتة — الموجة الثالثة:
 * (١) لا حماية تزامن على الأوامر المجدولة — تشغيلان يولّدان مكرّراً؛
 * (٢) recurring() يحفظ مؤشّر next مرةً واحدة بعد الحلقة وبلا try/catch —
 *     عطلٌ في المنتصف يُعيد توليد الفواتير المُنشأة؛
 * (٣) addMonths يفيض فيقفز فبراير ويُزيح مرساة نهاية الشهر؛
 * (٤) نافذة تكرار التنبيه ٢٤س بالضبط تُعيد إطلاق قواعد every=1 على انزياح الكرون.
 */
class AutomationIdempotencyTest extends TestCase
{
    protected function recur(array $over = []): RecurringDoc
    {
        return RecurringDoc::create($over + [
            'name' => 'اشتراك شهري', 'kind' => 'مصروف', 'amount' => 500,
            'currency' => 'د.ك', 'cycle' => 'شهري', 'auto_post' => true,
            'status' => 'مفعّل', 'next' => now()->subDay()->toDateString(),
        ]);
    }

    /** (١)+(٢) تشغيلٌ مرتين متتاليتين لا يُكرّر الفاتورة المولّدة */
    public function test_running_automation_twice_does_not_duplicate(): void
    {
        $this->seedCore();
        $this->recur();

        $this->artisan('hub:automation')->assertExitCode(0);
        $this->artisan('hub:automation')->assertExitCode(0);

        $this->assertSame(1, FinDocument::where('description', 'LIKE', '%اشتراك شهري%')->count(),
            'تشغيلُ الأتمتة مرتين ولّد فاتورتين — مؤشّر next لا يُحفظ ذرّيّاً مع الإنشاء');
    }

    /** (٣) مرساة ٣١ يناير تولّد فبراير ولا تقفزه (addMonthsNoOverflow) */
    public function test_month_end_recurring_does_not_skip_february(): void
    {
        $this->seedCore();
        $this->travelTo(now()->setDate(2026, 1, 31)->setTime(9, 0));
        $rec = $this->recur(['name' => 'إيجار', 'next' => '2026-01-31']);

        // من 15 فبراير: يجب أن تكون فاتورة يناير وُلّدت، والموعد التالي في فبراير لا مارس
        $this->travelTo(now()->setDate(2026, 2, 15)->setTime(9, 0));
        $this->artisan('hub:automation')->assertExitCode(0);

        $next = RecurringDoc::find($rec->id)->next;
        $this->assertStringStartsWith('2026-02', (string) $next,
            'addMonths فاض من 31 يناير إلى 3 مارس فقفز فبراير — والمرساة تنجرف للأبد');
        $this->travelBack();
    }

    /** (٤) قاعدة every=1 لا تُعيد الإطلاق على انزياح الكرون ثوانٍ */
    public function test_daily_alert_does_not_refire_on_cron_drift(): void
    {
        $this->seedCore();
        // إشعار الأمس لقاعدةٍ يومية، خُتم قبل لحظة تشغيل اليوم بثوانٍ
        $ruleId = (string) Str::uuid();
        HubNotification::create(['user_id' => $this->owner->id, 'kind' => 'rule:' . $ruleId,
            'text' => 'تنبيه', 'module' => 'contracts', 'record_id' => 'rec-1', 'read' => false,
            'created_at' => now()->subDay()->subSeconds(3)]);

        // الدالة نفسها: هل يُعدّ إشعار الأمس تكراراً اليوم؟ (النافذة اليومية)
        $dupDatetime = HubNotification::where('kind', 'rule:' . $ruleId)->where('record_id', 'rec-1')
            ->where('created_at', '>=', now()->subDays(1))->exists();          // القديم: نافذة datetime
        $dupDate = HubNotification::where('kind', 'rule:' . $ruleId)->where('record_id', 'rec-1')
            ->whereDate('created_at', '>=', today()->subDays(1))->exists();     // المُصلَح: نافذة يوم

        $this->assertFalse($dupDatetime, 'نافذة datetime تُخرج إشعار الأمس فيُعاد الإطلاق');
        $this->assertTrue($dupDate, 'نافذة اليوم تُبقي إشعار الأمس فلا تكرار');

        // حارس مصدر: الكود يستعمل whereDate لا نافذة datetime
        $src = file_get_contents(app_path('Console/Commands/HubAutomation.php'));
        $this->assertStringContainsString("whereDate('created_at'", $src,
            'دلو منع التكرار يقارن بالطابع الزمني لا باليوم — انزياح الكرون يُعيد الإطلاق');
    }

    /** (١) الأوامر المجدولة تحمل حماية التزامن — حارس مصدر */
    public function test_scheduled_commands_have_overlap_protection(): void
    {
        $src = file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString('withoutOverlapping', $src,
            'الأوامر المجدولة بلا withoutOverlapping — تشغيلان متزامنان يولّدان مكرّراً');
    }
}
