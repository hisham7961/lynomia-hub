<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\SignRequest;
use App\Models\SignTemplate;
use Tests\TestCase;

/**
 * الربط الذكي: عقدٌ غير موقّع عند إضافته → «قيد التوقيع» ويُحوَّل لمركز التوقيع؛
 * وإنشاء طلبٍ لعقد يضبطه «قيد التوقيع»؛ والتوقيع يرفعه تلقائياً إلى «ساري».
 */
class ContractEsignFlowTest extends TestCase
{
    protected string $sig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sig = 'data:image/png;base64,' . base64_encode(str_repeat('توقيع', 40));
    }

    /** إضافة عقد مع «غير موقّع»: يُحفظ «قيد التوقيع» ويُحوَّل لمركز التوقيع مُهيّأً */
    public function test_new_unsigned_contract_routes_to_esign(): void
    {
        $this->seedCore();
        $res = $this->actingAs($this->owner)->post('/m/contracts', [
            'title' => 'عقد صيانة سنوي', 'type' => 'عقد عميل', 'status' => 'مسودة', 'to_esign' => '1',
        ]);
        $res->assertRedirect();
        $this->assertStringContainsString('esign', $res->headers->get('Location'));

        $c = Contract::where('title', 'عقد صيانة سنوي')->first();
        $this->assertSame('قيد التوقيع', $c->status);

        // المركز يفتح مُهيّأً بالعقد والعنوان
        $html = $this->actingAs($this->owner)->get("/esign?contract={$c->id}&title=توقيع: عقد صيانة سنوي")
            ->assertOk()->getContent();
        $this->assertStringContainsString('عقد صيانة سنوي', $html);
    }

    /** عقدٌ موقّع أصلاً (بلا الخيار): لا تحويل — يمضي كسجلٍ عادي */
    public function test_signed_contract_stays_normal(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/m/contracts', [
            'title' => 'عقد موقّع ورقياً', 'type' => 'عقد عميل', 'status' => 'ساري',
        ])->assertRedirect(route('m.index', 'contracts'));

        $this->assertSame('ساري', Contract::where('title', 'عقد موقّع ورقياً')->value('status'));
    }

    /** دورة كاملة: عقد → طلب (قيد التوقيع) → توقيع → «ساري» تلقائياً */
    public function test_signing_flips_contract_to_active(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/esign');   // بذر القوالب
        $tpl = SignTemplate::first();
        $c = Contract::create(['title' => 'عقد التوريد', 'type' => 'عقد مورد', 'status' => 'مسودة']);

        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'توقيع عقد التوريد', 'template_id' => $tpl->id, 'pass' => 'pass12',
            'contract_id' => $c->id,
            'vars' => ['اسم_الطرف_الثاني' => 'مورد التوريد'],   // v2.119: الإلزامي لا بد منه
        ])->assertSessionHas('sign_link');

        // إنشاء الطلب ضبط العقد على «قيد التوقيع»
        $this->assertSame('قيد التوقيع', $c->fresh()->status);

        $req = SignRequest::first();
        auth()->logout();
        $this->post("/sign/{$req->token}/unlock", ['pass' => 'pass12']);
        $this->get("/sign/{$req->token}");
        $this->post("/sign/{$req->token}", ['signer_name' => 'المورد', 'signature' => $this->sig])->assertOk();

        // التوقيع رفعه تلقائياً إلى «ساري»
        $this->assertSame('ساري', $c->fresh()->status);
    }
}
