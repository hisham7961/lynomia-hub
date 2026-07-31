<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Contract;
use App\Models\ContractObligation;
use Tests\TestCase;

/** CLM م7 (v2.123): وحدة الالتزامات + سلسلة الملحق/التجديد + مساحة العمل + حل التعليقات + مكتبة البنود */
class ClmWorkspaceTest extends TestCase
{
    /** وحدة الالتزامات تعمل من السجل وحده: إنشاء وقائمة وعرض عبر المسارات العامة */
    public function test_obligations_module_end_to_end(): void
    {
        $this->seedCore();
        $c = Contract::create(['title' => 'عقد له التزام', 'type' => 'عقد عميل', 'status' => 'ساري']);

        $this->actingAs($this->owner)->post('/m/obligations', [
            'title' => 'دفعة الصيانة الربعية', 'contractId' => $c->id,
            'due' => now()->addDays(20)->toDateString(), 'amount' => '750',
            'currency' => 'د.ك', 'status' => 'قائم',
        ])->assertRedirect();

        $o = ContractObligation::where('title', 'دفعة الصيانة الربعية')->firstOrFail();
        $this->assertSame($c->id, $o->contract_id);
        $this->assertSame(750.0, $o->amount);

        $this->actingAs($this->owner)->get('/m/obligations')->assertOk()->assertSee('دفعة الصيانة الربعية');
        $this->actingAs($this->owner)->get('/m/obligations/' . $o->id)->assertOk()->assertSee('دفعة الصيانة الربعية');
    }

    /** ترحيل نص «الالتزامات الرئيسية» القديم لصفوف — idempotent لا يكرر */
    public function test_legacy_obligations_text_backfills_once(): void
    {
        $this->seedCore();
        $c = Contract::create(['title' => 'عقد قديم النص', 'type' => 'عقد عميل', 'status' => 'ساري',
            'obligations' => "تسليم التقرير الشهري\nصيانة دورية كل ربع\n\nتدريب الفريق"]);

        $mig = include database_path('migrations/2026_02_19_000001_clm_workspace.php');
        $mig->up();
        $this->assertSame(3, ContractObligation::where('contract_id', $c->id)->count());

        $mig->up();   // إعادة تشغيل: لا تكرار
        $this->assertSame(3, ContractObligation::where('contract_id', $c->id)->count());
        $this->assertNotNull($c->fresh()->obligations, 'العمود النصي يبقى كما وعدنا');
    }

    /** الملحق: مسودة مرتبطة بالأصل برقم جديد — والتجديد يقلب الأصل ويطلق contract.renewed */
    public function test_amend_and_renew_chain(): void
    {
        $this->seedCore();
        $captured = [];
        \App\Support\HubEvents::listen(function ($event) use (&$captured) { $captured[] = $event; });
        $c = Contract::create(['title' => 'عقد أصلي', 'type' => 'عقد عميل', 'status' => 'ساري',
            'value' => 1200, 'party' => 'شركة الطرف']);

        $this->actingAs($this->owner)->post("/contract/{$c->id}/amend")->assertRedirect();
        $amend = Contract::where('parent_id', $c->id)->where('kind', 'ملحق')->firstOrFail();
        $this->assertSame('مسودة', $amend->status);
        $this->assertNotSame($c->doc_no, $amend->doc_no);
        $this->assertSame(1200.0, (float) $amend->value, 'الملحق يرث حقول الأصل');
        $this->assertSame('ساري', $c->fresh()->status, 'الملحق لا يمس حالة الأصل');

        $this->actingAs($this->owner)->post("/contract/{$c->id}/renew")->assertRedirect();
        $renew = Contract::where('parent_id', $c->id)->where('kind', 'تجديد')->firstOrFail();
        $this->assertSame('مسودة', $renew->status);
        $this->assertSame('قيد التجديد', $c->fresh()->status);
        $this->assertContains('contract.renewed', $captured);

        // idempotent: نقرة تجديد ثانية لا تولد مسودة ثانية
        $this->actingAs($this->owner)->post("/contract/{$c->id}/renew")->assertRedirect();
        $this->assertSame(1, Contract::where('parent_id', $c->id)->where('kind', 'تجديد')->count());
    }

    /** صفحة العقد تحمل مساحة العمل بتبويباتها — وبقية الوحدات بلا أثر */
    public function test_contract_page_shows_workspace_tabs(): void
    {
        $this->seedCore();
        $c = Contract::create(['title' => 'عقد المساحة', 'type' => 'عقد عميل', 'status' => 'ساري']);
        ContractObligation::create(['title' => 'التزام ظاهر', 'contract_id' => $c->id, 'status' => 'قائم']);

        $this->actingAs($this->owner)->get('/m/contracts/' . $c->id)
            ->assertOk()->assertSee('مساحة عمل العقد')
            ->assertSee('الأطراف والتواقيع')->assertSee('سلسلة العقد')
            ->assertSee('التزام ظاهر')->assertSee('ملحق تعديل');

        // وحدة أخرى: الخطاف no-op
        $cl = \App\Models\Client::create(['name' => 'عميل عادي']);
        $this->actingAs($this->owner)->get('/m/clients/' . $cl->id)
            ->assertOk()->assertDontSee('مساحة عمل العقد');
    }

    /** حل التعليقات: يُعلَّم ويُفكّ، والصلاحية لصاحبه أو محرر الوحدة */
    public function test_comment_resolve_cycle(): void
    {
        $this->seedCore();
        $c = Contract::create(['title' => 'عقد نقاش', 'type' => 'عقد عميل', 'status' => 'مسودة']);
        $this->actingAs($this->employee)->post('/comments', [
            'module' => 'contracts', 'record_id' => $c->id, 'body' => 'هل بند السرية نهائي؟',
        ]);
        $cm = Comment::where('body', 'هل بند السرية نهائي؟')->firstOrFail();

        $this->actingAs($this->viewer)->post("/comments/{$cm->id}/resolve")->assertForbidden();

        $this->actingAs($this->employee)->post("/comments/{$cm->id}/resolve")->assertRedirect();
        $this->assertNotNull($cm->fresh()->resolved_at);
        $this->assertSame($this->employee->id, $cm->fresh()->resolved_by);
        $this->actingAs($this->owner)->get('/m/contracts/' . $c->id)->assertSee('محلول');

        $this->actingAs($this->employee)->post("/comments/{$cm->id}/resolve");
        $this->assertNull($cm->fresh()->resolved_at, 'إعادة الفتح تمسح الحل');
    }

    /** مكتبة البنود: إضافة وحذف في الإعدادات، والإدراج نسخٌ بالقيمة (بيانات في الصفحة) */
    public function test_clause_library_store_and_delete(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/esign/clauses', [
            'name' => 'بند السرية', 'body' => 'يلتزم {اسم_الطرف_الثاني} بالسرية التامة.',
        ])->assertRedirect();

        $tpl = \App\Models\SignTemplate::create(['name' => 'قالب البنود', 'body' => 'نص']);
        $this->actingAs($this->owner)->get('/esign/templates/' . $tpl->id . '/edit')
            ->assertOk()->assertSee('مكتبة البنود')->assertSee('بند السرية')
            ->assertSee('clausedata', false);

        $this->actingAs($this->owner)->delete('/esign/clauses', ['i' => 0])->assertRedirect();
        $this->assertSame([], \App\Http\Controllers\Web\ContractActionsController::clauses());

        // غير المحرر لا يعبث بالمكتبة
        $this->actingAs($this->viewer)->post('/esign/clauses', ['name' => 'خبيث', 'body' => 'نص'])->assertForbidden();
    }

    /** الالتزام يظهر في التقويم برادار الاستحقاق (قيد السجل يشتري كل شيء) */
    public function test_obligation_appears_on_calendar(): void
    {
        $this->seedCore();
        ContractObligation::create(['title' => 'التزام مؤرخ', 'due' => now()->toDateString(), 'status' => 'قائم']);

        $this->actingAs($this->owner)->get('/calendar')->assertOk()->assertSee('التزام مؤرخ');
    }
}
