<?php

namespace Tests\Feature;

use App\Models\HubNotification;
use App\Models\SignRequest;
use App\Models\SignTemplate;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * الترقية العالمية: نص حر + تحرير، رمز تحقق وبصمة، خيارات لكل طلب
 * (سيلفي/هوية/رفض/صلاحية)، نسخة العميل، وصفحة التحقق العلنية.
 */
class EsignProTest extends TestCase
{
    protected string $sig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sig = 'data:image/png;base64,' . base64_encode(str_repeat('توقيع', 40));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function makeReq(array $extra = []): SignRequest
    {
        $this->actingAs($this->owner)->get('/esign');
        $this->actingAs($this->owner)->post('/esign', $extra + [
            'title' => 'وثيقة برو', 'free_body' => 'نص عقدٍ حرٍّ كامل للاختبار.',
            'pass' => 'p1234',
        ]);

        return SignRequest::latest('created_at')->first();
    }

    /** المركز القانوني يعمل — والاستعلام على العمود الفعلي date_end */
    public function test_legal_center_works_with_real_dates(): void
    {
        $this->seedCore();
        \App\Models\Contract::create(['title' => 'ينتهي قريباً', 'type' => 'عقد عميل',
            'status' => 'ساري', 'date_end' => now()->addDays(15)->toDateString()]);

        $this->actingAs($this->owner)->get('/legal')->assertOk()->assertSee('ينتهي قريباً');
    }

    /** نص حر بلا قالب + رمز تحقق وبصمة يولدان تلقائياً + التحويل لصفحة التحرير */
    public function test_free_body_creates_with_code_and_hash(): void
    {
        $this->seedCore();
        $req = $this->makeReq();

        $this->assertMatchesRegularExpression('/^LYN-[A-Z0-9]{4}-[A-Z0-9]{4}$/i', $req->verify_code);
        $this->assertSame(hash('sha256', $req->body), $req->doc_hash);

        // صفحة التحرير: تعديل النص يجدد البصمة
        $this->actingAs($this->owner)->get("/esign/{$req->id}/edit")->assertOk()->assertSee('تحرير العقد');
        $this->actingAs($this->owner)->put("/esign/{$req->id}",
            ['title' => 'وثيقة برو', 'body' => 'نصٌّ محرَّر جديد.'])->assertRedirect();
        $req->refresh();
        $this->assertSame('نصٌّ محرَّر جديد.', $req->body);
        $this->assertSame(hash('sha256', 'نصٌّ محرَّر جديد.'), $req->doc_hash);
    }

    /** الخيارات إلزامية عند تفعيلها: هوية وسيلفي — والتوقيع بلاها يُرفض */
    public function test_idno_and_selfie_required_when_enabled(): void
    {
        $this->seedCore();
        $req = $this->makeReq(['opt_idno' => '1', 'opt_selfie' => '1']);
        auth()->logout();
        $this->post("/sign/{$req->token}/unlock", ['pass' => 'p1234']);

        // بلا هوية وسيلفي → أخطاء تحقق
        $this->post("/sign/{$req->token}", ['signer_name' => 'م', 'signature' => $this->sig])
            ->assertSessionHasErrors(['signer_id_no', 'selfie']);

        // بهما → ينجح ويُخزنان
        $selfie = 'data:image/jpeg;base64,' . base64_encode(str_repeat('صورة', 40));
        $this->post("/sign/{$req->token}", ['signer_name' => 'موقّع', 'signature' => $this->sig,
            'signer_id_no' => '299001234567', 'selfie' => $selfie])->assertOk();
        $req->refresh();
        $this->assertSame('299001234567', $req->signer_id_no);
        $this->assertNotEmpty($req->selfie);
    }

    /** الرفض: مسموحٌ افتراضاً، يسجل السبب ويُنبِّه، ويقفل الوثيقة */
    public function test_decline_flow(): void
    {
        $this->seedCore();
        $req = $this->makeReq();
        auth()->logout();
        $this->post("/sign/{$req->token}/unlock", ['pass' => 'p1234']);

        $this->post("/sign/{$req->token}/decline", ['reason' => 'البند الثالث غير مقبول'])
            ->assertOk()->assertSee('تم تسجيل رفضك');
        $req->refresh();
        $this->assertSame('رُفض', $req->status);
        $this->assertTrue(HubNotification::where('text', 'LIKE', '%رُفض%البند الثالث%')->exists());
        // بعد الرفض لا توقيع
        $this->post("/sign/{$req->token}", ['signer_name' => 'م', 'signature' => $this->sig])->assertStatus(410);
    }

    /** الصلاحية الزمنية: الرابط المنتهي يعرض صفحة انتهاء 410 */
    public function test_link_expiry(): void
    {
        $this->seedCore();
        $req = $this->makeReq(['expire_days' => 2]);
        auth()->logout();

        Carbon::setTestNow(now()->addDays(3));
        $this->get("/sign/{$req->token}")->assertStatus(410)->assertSee('انتهت صلاحية');
    }

    /** نسخة العميل النهائية عبر رابطه + رمز التحقق فيها */
    public function test_client_final_copy(): void
    {
        $this->seedCore();
        $req = $this->makeReq();
        auth()->logout();
        $this->post("/sign/{$req->token}/unlock", ['pass' => 'p1234']);
        $this->get("/sign/{$req->token}");
        $this->post("/sign/{$req->token}", ['signer_name' => 'العميل', 'signature' => $this->sig])
            ->assertOk()->assertSee($req->verify_code);

        $this->get("/sign/{$req->token}/doc")->assertOk()
            ->assertSee($req->verify_code)->assertSee('بصمة الوثيقة');
    }

    /** صفحة التحقق العلنية: الرمز الصحيح يطابق، والخاطئ يرفض، بلا كشف النص */
    public function test_public_verify_page(): void
    {
        $this->seedCore();
        $req = $this->makeReq();
        auth()->logout();

        $this->post('/verify', ['code' => $req->verify_code])
            ->assertOk()->assertSee('مستند أصلي')->assertSee($req->doc_hash)
            ->assertDontSee('نص عقدٍ حرٍّ');   // لا يكشف المحتوى

        $this->post('/verify', ['code' => 'LYN-0000-0000'])->assertOk()->assertSee('لا مستند بهذا الرمز');
    }

    /** الإشعارات تحمل رمز التحقق للمطابقة معنا */
    public function test_notifications_carry_verify_code(): void
    {
        $this->seedCore();
        $req = $this->makeReq();
        auth()->logout();
        $this->post("/sign/{$req->token}/unlock", ['pass' => 'p1234']);
        $this->get("/sign/{$req->token}");
        $this->post("/sign/{$req->token}", ['signer_name' => 'م', 'signature' => $this->sig]);

        $this->assertTrue(HubNotification::where('text', 'LIKE', "%{$req->verify_code}%")
            ->where('text', 'LIKE', '%وُقّع%')->exists(), 'إشعار التوقيع بلا رمز التحقق');
    }
}
