<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractApprovalStep;
use App\Models\ContractEvent;
use App\Models\ContractSigner;
use App\Models\HubNotification;
use App\Models\OutboxMessage;
use App\Models\SignRequest;
use Tests\TestCase;

/** CLM م5 (v2.121): موافقات العقود المرحلية بعتبات قيمة — معزولة عن المحرك العام */
class ContractApprovalsTest extends TestCase
{
    /** قواعد مرحلتين: مالية لموظفة ثم قانونية للمالكين — عتبة ١٠٠٠ */
    protected function seedRules(float $min = 1000): void
    {
        $this->hubSetting('contracts.approval_steps', json_encode([
            ['kind' => 'مالية', 'min_value' => $min, 'approver_id' => $this->employee->id, 'label' => 'اعتماد مالي'],
            ['kind' => 'قانونية', 'min_value' => $min, 'approver_id' => ''],
        ], JSON_UNESCAPED_UNICODE));
    }

    protected function makeHeld(float $value = 5000): array
    {
        $c = Contract::create(['title' => 'عقد كبير', 'type' => 'عقد عميل', 'status' => 'مسودة', 'value' => $value]);
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'توقيع العقد الكبير', 'free_body' => 'نص العقد الكبير',
            'contract_id' => $c->id,
            'signers' => json_encode([
                ['name' => 'موقّع خارجي', 'email' => 'ext@example.com', 'role' => 'موقّع'],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return [SignRequest::where('title', 'توقيع العقد الكبير')->firstOrFail(), $c];
    }

    /** الافتراضي بلا قواعد: صفر أثر — الطلب يُرسل فوراً كما كان حرفياً */
    public function test_no_rules_means_zero_impact(): void
    {
        $this->seedCore();
        [$req, $c] = $this->makeHeld();

        $this->assertSame('بانتظار التوقيع', $req->status);
        $this->assertNotNull($req->sent_at);
        $this->assertSame(0, ContractApprovalStep::count());
        $this->assertSame(1, OutboxMessage::where('kind', 'sign_link')->count());
        $this->assertSame('قيد التوقيع', $c->fresh()->status);
    }

    /** عقد دون العتبة يمضي بلا موافقات حتى مع وجود القواعد */
    public function test_below_threshold_skips_entirely(): void
    {
        $this->seedCore();
        $this->seedRules(10000);
        [$req] = $this->makeHeld(500);

        $this->assertSame('بانتظار التوقيع', $req->status);
        $this->assertSame(0, ContractApprovalStep::count());
    }

    /** فوق العتبة: يُحجز الطلب، لا بريد ولا انقلاب عقد، والرابط العام محجوب */
    public function test_above_threshold_holds_request_and_blocks_link(): void
    {
        $this->seedCore();
        $this->seedRules();
        [$req, $c] = $this->makeHeld();

        $this->assertSame('بانتظار الموافقة', $req->status);
        $this->assertNull($req->sent_at);
        $this->assertSame('مسودة', $c->fresh()->status, 'العقد لا ينقلب قبل اكتمال الموافقات');
        $this->assertSame(0, OutboxMessage::where('kind', 'sign_link')->count(), 'لا تسليم قبل الاعتماد');

        $steps = ContractApprovalStep::where('request_id', $req->id)->orderBy('stage')->get();
        $this->assertCount(2, $steps);
        $this->assertSame(['مالية', 'قانونية'], $steps->pluck('kind')->all());

        // الموافِقة المالية أُخطرت
        $this->assertTrue(HubNotification::where('user_id', $this->employee->id)
            ->where('text', 'LIKE', '%بانتظار اعتمادك%')->exists());

        // الرابط العام محجوب بصفحة مراجعة — لا نص ولا OTP ولا توقيع
        $s = ContractSigner::where('request_id', $req->id)->first();
        $this->get("/sign/{$s->token}")->assertStatus(403)->assertSee('قيد المراجعة الداخلية')
            ->assertDontSee('نص العقد الكبير');
        $this->post("/sign/{$s->token}/otp")->assertStatus(403);
    }

    /** الترتيب يُفرض: القانونية لا تُعتمد قبل المالية، والغريب لا قرار له */
    public function test_stage_order_and_approver_gating(): void
    {
        $this->seedCore();
        $this->seedRules();
        [$req] = $this->makeHeld();

        // المشاهد ليس موافِق المرحلة المالية ولا مالكاً → 403
        $this->actingAs($this->viewer)->post("/esign/{$req->id}/approve")->assertForbidden();

        // الموافِقة المالية تعتمد → المرحلة ١ موافق والطلب ما زال محجوزاً للمرحلة ٢
        $this->actingAs($this->employee)->post("/esign/{$req->id}/approve", ['note' => 'الأرقام سليمة'])
            ->assertRedirect();
        $s1 = ContractApprovalStep::where('request_id', $req->id)->where('stage', 1)->first();
        $this->assertSame('موافق', $s1->status);
        $this->assertSame($this->employee->id, $s1->decided_by);
        $this->assertSame('بانتظار الموافقة', $req->fresh()->status);

        // المرحلة ٢ بلا موافِق مسمى = قرار المالكين — الموظفة الآن 403
        $this->actingAs($this->employee)->post("/esign/{$req->id}/approve")->assertForbidden();
    }

    /** اكتمال السلسلة: الطلب يتحرر ويُسلَّم والعقد قيد التوقيع وcontract.approved ينطلق */
    public function test_full_chain_releases_and_delivers(): void
    {
        $this->seedCore();
        $this->seedRules();
        $captured = [];
        \App\Support\HubEvents::listen(function ($event) use (&$captured) { $captured[] = $event; });
        [$req, $c] = $this->makeHeld();

        $this->actingAs($this->employee)->post("/esign/{$req->id}/approve");
        $this->actingAs($this->owner)->post("/esign/{$req->id}/approve", ['note' => 'الصياغة سليمة'])
            ->assertRedirect();

        $fresh = $req->fresh();
        $this->assertSame('بانتظار التوقيع', $fresh->status);
        $this->assertNotNull($fresh->sent_at);
        $this->assertSame('قيد التوقيع', $c->fresh()->status);
        $this->assertSame(1, OutboxMessage::where('kind', 'sign_link')->where('target', 'ext@example.com')->count());
        $this->assertContains('contract.approved', $captured);

        // الرابط يفتح الآن ويصل حتى بوابة الموقّع
        $s = ContractSigner::where('request_id', $req->id)->first();
        $this->get("/sign/{$s->token}")->assertOk();

        // قرار كل مرحلة مدقق
        $this->assertSame(2, \App\Models\AuditEntry::where('action', 'اعتماد مرحلة موافقة عقد')->count());
    }

    /** الرفض يوقف الطلب: أُلغي وروابطه ماتت والمنشئ أُبلغ وcontract.approval_rejected انطلق */
    public function test_rejection_stops_request_and_notifies(): void
    {
        $this->seedCore();
        $this->seedRules();
        $captured = [];
        \App\Support\HubEvents::listen(function ($event) use (&$captured) { $captured[] = $event; });
        [$req, $c] = $this->makeHeld();

        // بلا سبب → يُرد بإرشاد
        $this->actingAs($this->employee)->post("/esign/{$req->id}/reject")->assertSessionHas('err');

        $this->actingAs($this->employee)->post("/esign/{$req->id}/reject", ['note' => 'القيمة فوق الميزانية'])
            ->assertSessionHas('ok');

        $fresh = $req->fresh();
        $this->assertSame('أُلغي', $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame('مرفوض', ContractApprovalStep::where('request_id', $req->id)->where('stage', 1)->value('status'));
        $this->assertSame('مسودة', $c->fresh()->status);
        $this->assertTrue(ContractEvent::where('request_id', $req->id)->where('event', 'voided')->exists());
        $this->assertContains('contract.approval_rejected', $captured);
        $this->assertTrue(HubNotification::where('user_id', $this->owner->id)
            ->where('text', 'LIKE', '%رُفضت موافقة%')->exists());

        // الروابط ماتت 410
        $s = ContractSigner::where('request_id', $req->id)->first();
        $this->get("/sign/{$s->token}")->assertStatus(410);
        // ولا قرار بعد الإغلاق
        $this->actingAs($this->owner)->post("/esign/{$req->id}/approve")->assertStatus(410);
    }

    /** الموافِق المسمى غير المالك يرى الطلب المحجوز في قائمته ليقرر */
    public function test_assigned_approver_sees_held_request_in_list(): void
    {
        $this->seedCore();
        $this->seedRules();
        [$req] = $this->makeHeld();

        $this->actingAs($this->employee)->get('/esign')
            ->assertOk()->assertSee('توقيع العقد الكبير')->assertSee('قرار المرحلة');
    }
}
