<?php

namespace Tests\Feature;

use App\Models\HubNotification;
use App\Models\SignRequest;
use App\Models\SignTemplate;
use Tests\TestCase;

/**
 * التوقيع الإلكتروني: قالب → طلب برابط خاص وكلمة سر → فتح (إشعار) → توقيع
 * (إشعار + IP + جهاز + وقت) → وثيقة نهائية — والترحيل لصفحة العقد.
 */
class EsignTest extends TestCase
{
    protected string $sig;

    protected function setUp(): void
    {
        parent::setUp();
        // توقيع PNG صغير حقيقي (dataURL) يجتاز التحقق
        $this->sig = 'data:image/png;base64,' . base64_encode(str_repeat('لوحة توقيع', 20));
    }

    /** الرحلة كاملة كما يعيشها الطرفان */
    public function test_full_signing_journey(): void
    {
        $this->seedCore();
        $contract = \App\Models\Contract::create(['title' => 'عقد الذواقة', 'type' => 'عقد عميل']);

        // ١) الداخل: أول زيارة تبذر القوالب الافتراضية، ثم إنشاء طلب من قالب بمتغيرات
        $this->actingAs($this->owner)->get('/esign')->assertOk()->assertSee('عقد تقديم خدمات');
        $tpl = SignTemplate::where('name', 'عقد تقديم خدمات')->first();

        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'عقد خدمات الذواقة', 'template_id' => $tpl->id, 'pass' => 'sign1234',
            'contract_id' => $contract->id,
            'vars' => ['اسم_العميل' => 'مطاعم الذواقة', 'اسم_شركتنا' => 'لينوميا',
                       'المبلغ' => '1500', 'العملة' => 'د.ك'],
        ])->assertSessionHas('sign_link');

        $req = SignRequest::first();
        $this->assertStringContainsString('مطاعم الذواقة', $req->body, 'المتغير لم يُملأ');
        $this->assertStringContainsString('1500', $req->body);
        $this->assertDatabaseHas('audits', ['action' => 'إنشاء طلب توقيع']);

        // ٢) العميل (بلا حساب): البوابة أولاً — كلمة سر خاطئة تُرد، والصحيحة تفتح
        auth()->logout();
        $this->get("/sign/{$req->token}")->assertOk()->assertSee('كلمة السر')->assertDontSee('1500');
        $this->post("/sign/{$req->token}/unlock", ['pass' => 'خطأ'])->assertSessionHas('err');
        $this->post("/sign/{$req->token}/unlock", ['pass' => 'sign1234'])->assertRedirect();

        // ٣) أول فتح: يُسجَّل ويُنبَّه المالكون
        $this->get("/sign/{$req->token}")->assertOk()->assertSee('مطاعم الذواقة')->assertSee('أوقّع وأوافق');
        $this->assertNotNull($req->fresh()->opened_at);
        $this->assertTrue(HubNotification::where('kind', 'sign')->where('text', 'LIKE', '%فُتح%')->exists());

        // ٤) التوقيع: يحفظ الاسم والرسم وIP والجهاز والوقت، ويُنبَّه المالكون
        $this->post("/sign/{$req->token}", ['signer_name' => 'أحمد الذواقة', 'signature' => $this->sig])
            ->assertOk()->assertSee('تم التوقيع بنجاح');

        $req->refresh();
        $this->assertSame('وُقّع', $req->status);
        $this->assertSame('أحمد الذواقة', $req->signer_name);
        $this->assertNotEmpty($req->signed_ip);
        $this->assertNotNull($req->signed_at);
        $this->assertTrue(HubNotification::where('kind', 'sign')->where('text', 'LIKE', '%وُقّع%')->exists());
        $this->assertDatabaseHas('audits', ['action' => 'توقيع عقد إلكترونياً']);

        // ٥) التوقيع الثاني مرفوض — الوثيقة أُغلقت
        $this->post("/sign/{$req->token}", ['signer_name' => 'منتحل', 'signature' => $this->sig])
            ->assertStatus(410);

        // ٦) الترحيل: بطاقة التواقيع على صفحة العقد نفسه + الوثيقة النهائية بأثرها
        $this->actingAs($this->owner)->get("/m/contracts/{$contract->id}")
            ->assertOk()->assertSee('التوقيع الإلكتروني')->assertSee('أحمد الذواقة');
        $this->actingAs($this->owner)->get("/esign/{$req->id}/doc")
            ->assertOk()->assertSee('أحمد الذواقة')->assertSee($req->signed_ip);
    }

    /** التوقيع بلا فتح كلمة السر ممنوع — لا التفاف على البوابة */
    public function test_signing_requires_unlocked_session(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/esign');
        $tpl = SignTemplate::first();
        $r = $this->actingAs($this->owner)->post('/esign', [
            'title' => 'ع', 'template_id' => $tpl->id, 'pass' => 'x1234',
        ]);
        $r->assertSessionHasNoErrors();
        auth()->logout();

        $req = SignRequest::first();
        $this->post("/sign/{$req->token}", ['signer_name' => 'متسلل', 'signature' => $this->sig])
            ->assertForbidden();
    }

    /** صلاحية العقود تحكم المركز: من لا يضيف عقوداً لا ينشئ طلبات */
    public function test_center_follows_contracts_permission(): void
    {
        $this->seedCore();
        $this->actingAs($this->viewer)->get('/esign')->assertOk();               // يرى (v)
        $this->actingAs($this->viewer)->post('/esign', ['title' => 'ع'])->assertForbidden();  // لا يضيف (a)
    }
}
