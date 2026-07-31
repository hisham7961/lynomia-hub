<?php

namespace Tests\Feature;

use App\Models\CostCenter;
use App\Models\FinDocument;
use App\Models\RecurringDoc;
use Tests\TestCase;

/**
 * المرحلة ٢ — محرك المتكررات كان الكاتب الآلي الوحيد في المالية وبلا اختبار
 * واحد، وكان يفقد الأبعاد (مركز التكلفة، البند، الطريقة) عند التوليد فتعمى
 * تقارير البنود ومراكز التكلفة عن كل المولَّد آلياً.
 */
class RecurDimensionsRound6Test extends TestCase
{
    public function test_generated_document_carries_all_dimensions(): void
    {
        $this->seedCore();
        $cc = CostCenter::create(['name' => 'مركز التشغيل', 'code' => 'CC-OPS']);
        RecurringDoc::create(['name' => 'إيجار المكتب', 'kind' => 'مصروف', 'amount' => 500,
            'cycle' => 'شهري', 'next' => now()->toDateString(), 'auto_post' => true,
            'status' => 'مفعّل', 'cc_id' => $cc->id, 'cat' => 'استضافة وسيرفرات', 'method' => 'تحويل بنكي']);

        $this->artisan('hub:automation')->assertExitCode(0);

        $doc = FinDocument::where('description', 'LIKE', '%إيجار المكتب%')->first();
        $this->assertNotNull($doc, 'المتكرر المستحق يولّد مستنداً');
        $this->assertSame($cc->id, $doc->cc_id, 'مركز التكلفة كان يضيع عند التوليد');
        $this->assertSame('استضافة وسيرفرات', $doc->cat, 'البند كان يُحشر نصاً في الوصف لا عموداً');
        $this->assertSame('تحويل بنكي', $doc->method);
    }

    public function test_missed_cycles_are_backfilled_and_paused_stays_silent(): void
    {
        $this->seedCore();
        // فائت ثلاث دورات شهرية — يعوَّض ثلاثة مستندات ويتقدم الموعد للمستقبل
        RecurringDoc::create(['name' => 'اشتراك فائت', 'kind' => 'مصروف', 'amount' => 100,
            'cycle' => 'شهري', 'next' => now()->startOfMonth()->subMonths(2)->toDateString(), 'auto_post' => true,
            'status' => 'مفعّل']);
        RecurringDoc::create(['name' => 'متكرر موقوف', 'kind' => 'مصروف', 'amount' => 999,
            'cycle' => 'شهري', 'next' => now()->toDateString(), 'auto_post' => true,
            'status' => 'موقوف']);

        $this->artisan('hub:automation')->assertExitCode(0);

        $this->assertSame(3, FinDocument::where('description', 'LIKE', '%اشتراك فائت%')->count(),
            'التعويض عن المواعيد الفائتة دورةً دورة');
        $this->assertSame(0, FinDocument::where('description', 'LIKE', '%متكرر موقوف%')->count());
    }

    public function test_dry_mode_writes_nothing(): void
    {
        $this->seedCore();
        RecurringDoc::create(['name' => 'متكرر معاينة', 'kind' => 'مصروف', 'amount' => 50,
            'cycle' => 'شهري', 'next' => now()->toDateString(), 'auto_post' => true, 'status' => 'مفعّل']);

        $this->artisan('hub:automation --dry')->assertExitCode(0);

        $this->assertSame(0, FinDocument::count(), 'وضع المعاينة لا يكتب مستنداً');
        $this->assertSame(now()->toDateString(),
            substr((string) RecurringDoc::first()->next, 0, 10), 'ولا يقدّم الموعد');
    }

    public function test_finance_report_groups_expenses_by_cost_center(): void
    {
        $this->seedCore();
        $cc = CostCenter::create(['name' => 'مركز التسويق', 'code' => 'CC-MKT']);
        FinDocument::create(['doc_no' => 'CC-1', 'kind' => 'مصروف', 'date' => now()->toDateString(),
            'total' => 300, 'state' => 'مدفوعة', 'cc_id' => $cc->id]);

        $this->actingAs($this->owner)->get('/reports/finance')
            ->assertOk()->assertSee('مصروف السنة حسب مركز التكلفة')->assertSee('مركز التسويق');
    }
}
