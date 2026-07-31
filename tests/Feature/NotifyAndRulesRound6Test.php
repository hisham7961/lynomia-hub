<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\BankAccount;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * جولة تدقيق ٦ — إحياء محرك الإشعارات والقواعد:
 *  1) إشعار الإسناد كان يشترط المفتاح assigneeId حرفياً — منفّذ القرار ومدير
 *     الإجازة ومقابِل المرشح لا يُبلَّغون أبداً رغم إسنادهم صراحةً.
 *  2) عامل «أصغر من عمود» الجديد: مقارنة عمودٍ بعمودٍ من نفس السجل — بها يحيا
 *     «حد إعادة الطلب» لكل صنف و«حد التنبيه» لكل صندوق (كانا حقلين ديكوريين).
 *  3) قائمة وحدات القواعد كانت مبتورة على ١٣ — لا قاعدة ممكنة على المشاكل
 *     والقرارات والامتثال والموافقات وغيرها.
 */
class NotifyAndRulesRound6Test extends TestCase
{
    public function test_decision_executor_gets_assignment_notification(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/m/decisions', [
            'title' => 'قرار بتكليف', 'execId' => $this->employee->id,
        ]);

        $this->assertTrue(
            DB::table('notifications_hub')->where('user_id', $this->employee->id)->where('kind', 'assign')->exists(),
            'منفّذ القرار (execId) يستحق إشعار إسناد — كان لا يُبلَّغ أبداً');
    }

    public function test_leave_manager_gets_assignment_notification(): void
    {
        $this->seedCore();
        $emp = \App\Models\Employee::create(['name' => 'موظف الإجازة', 'status' => 'نشط']);
        $this->actingAs($this->owner)->post('/m/leaves', [
            'empId' => $emp->id, 'type' => 'إجازة سنوية', 'from' => now()->toDateString(),
            'mgrId' => $this->employee->id,
        ]);

        $this->assertTrue(
            DB::table('notifications_hub')->where('user_id', $this->employee->id)->where('kind', 'assign')->exists(),
            'مدير الإجازة (mgrId) يستحق إشعار طلبٍ ينتظره');
    }

    public function test_column_compare_operator_fires_per_record_threshold(): void
    {
        $this->seedCore();
        BankAccount::create(['name' => 'صندوق مكشوف', 'balance' => 100, 'min_bal' => 500]);
        BankAccount::create(['name' => 'صندوق مرتاح', 'balance' => 900, 'min_bal' => 500]);

        AlertRule::create(['name' => 'رصيد تحت الحد', 'mod' => 'banks', 'field' => 'balance',
            'op' => 'أصغر من عمود', 'val' => 'minBal', 'chan' => 'نظام', 'every' => 7, 'status' => 'مفعّلة']);

        $this->artisan('hub:automation')->assertExitCode(0);

        $texts = DB::table('notifications_hub')->pluck('text')->implode(' | ');
        $this->assertStringContainsString('صندوق مكشوف', $texts, 'الرصيد تحت حدّ سجله يجب أن ينبّه');
        $this->assertStringNotContainsString('صندوق مرتاح', $texts, 'الرصيد فوق حدّه لا ينبّه');
    }

    public function test_rules_module_list_covers_governance_modules(): void
    {
        $opts = collect(hub_mod('rules')['fields'])->firstWhere('key', 'mod')['options'];
        foreach (['issues', 'decisions', 'compliance', 'approvals', 'okrs', 'meetings', 'leaves', 'incidents'] as $m) {
            $this->assertContains($m, $opts, "وحدة {$m} كانت محرومة من قواعد التنبيه رغم عمومية المحرك");
        }
    }

    public function test_starter_pack_includes_gulf_documents_and_column_rules(): void
    {
        $this->seedCore();
        $this->artisan('hub:alerts-starter')->assertExitCode(0);

        foreach (['🪪 إقامة تنتهي خلال ٩٠ يوماً', '🏦 رصيد تحت حد التنبيه', '📦 صنف بلغ حد إعادة الطلب'] as $name) {
            $this->assertTrue(DB::table('alert_rules')->where('name', $name)->exists(), "قاعدة «{$name}» غائبة عن العدّة");
        }
        // قاعدة المخزون صارت مقارنة عمودٍ بعمود لا ثابتاً أعمى
        $this->assertSame('أصغر من عمود',
            DB::table('alert_rules')->where('name', '📦 صنف بلغ حد إعادة الطلب')->value('op'));
    }
}
