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

        $client = \App\Models\Client::create(['name' => 'مطاعم الذواقة', 'stage' => 'عميل']);
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'عقد خدمات الذواقة', 'template_id' => $tpl->id, 'pass' => 'sign1234',
            'contract_id' => $contract->id,
            'link_module' => 'clients', 'link_id' => $client->id,
            'vars' => ['اسم_الطرف_الثاني' => 'مطاعم الذواقة', 'اسم_شركتنا' => 'لينوميا',
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

    /** الربط بجهة: يظهر الطلب على صفحة العميل نفسه، وربطٌ خارج النطاق يُتجاهل */
    public function test_request_links_to_entity_and_shows_on_its_page(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/esign');
        $tpl = SignTemplate::first();
        $client = \App\Models\Client::create(['name' => 'شركة النور', 'stage' => 'عميل محتمل']);

        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'عقد النور', 'template_id' => $tpl->id, 'pass' => 'p1234',
            'link_module' => 'clients', 'link_id' => $client->id,
        ])->assertSessionHas('sign_link');

        $req = SignRequest::first();
        $this->assertSame('clients', $req->link_module);
        $this->assertSame($client->id, $req->link_id);
        $this->assertStringContainsString('شركة النور', (string) $req->linkLabel());

        // البطاقة العكسية على صفحة العميل
        $this->actingAs($this->owner)->get("/m/clients/{$client->id}")
            ->assertOk()->assertSee('التواقيع المرتبطة')->assertSee('عقد النور');
    }

    /** ربطٌ بجهة لا يملكها/غير مسموحة يُتجاهل بأمان دون كسر الإنشاء */
    public function test_invalid_link_is_ignored(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/esign');
        $tpl = SignTemplate::first();

        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'ع', 'template_id' => $tpl->id, 'pass' => 'p1234',
            'link_module' => 'tasks', 'link_id' => 'x',   // tasks ليست ضمن الجهات المسموحة
        ])->assertSessionHas('sign_link');

        $this->assertNull(SignRequest::first()->link_module);
    }

    /** المكتبة غنية: عشرة قوالب متنوّعة بأنواعها */
    public function test_rich_template_library_is_seeded(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/esign')->assertOk();
        $this->assertGreaterThanOrEqual(10, SignTemplate::count());
        foreach (['عقد عمل (توظيف)', 'عقد شراكة', 'اتفاقية مستوى خدمة (SLA)', 'خطاب عرض وظيفي'] as $n) {
            $this->assertTrue(SignTemplate::where('name', $n)->exists(), "قالب «{$n}» غائب");
        }
    }

    /** صلاحية العقود تحكم المركز: من لا يضيف عقوداً لا ينشئ طلبات */
    public function test_center_follows_contracts_permission(): void
    {
        $this->seedCore();
        $this->actingAs($this->viewer)->get('/esign')->assertOk();               // يرى (v)
        $this->actingAs($this->viewer)->post('/esign', ['title' => 'ع'])->assertForbidden();  // لا يضيف (a)
    }
}
