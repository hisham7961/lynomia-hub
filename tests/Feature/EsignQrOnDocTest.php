<?php

namespace Tests\Feature;

use App\Models\SignRequest;
use Tests\TestCase;

/**
 * بند ٩ — QR على متن الوثيقة يفتحها مباشرةً.
 *
 * الرمزُ كان على الشهادة وبطاقة العقد وصفحة التحقّق — لا على **جسم الوثيقة**،
 * وصفحةُ التحقّق تعرض «أصليّة» دون أن تفتح الوثيقة. الآن: QR مطبوعٌ على الوثيقة
 * الموقّعة يشير إلى مسارٍ عامٍّ يفتح الوثيقة نفسها بمسحةٍ واحدة — بلا حساب.
 */
class EsignQrOnDocTest extends TestCase
{
    protected string $sig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sig = 'data:image/png;base64,' . base64_encode(str_repeat('توقيع', 40));
    }

    /** يوقّع الطلب عبر مسار العميل العام فيصبح status='وُقّع' */
    protected function makeSigned(): SignRequest
    {
        $this->actingAs($this->owner)->get('/esign');
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'اتفاقية موقّعة', 'free_body' => 'نصُّ الاتفاقية الكامل للاختبار.', 'pass' => 'p1234',
        ]);
        $req = SignRequest::latest('created_at')->first();

        $this->post("/sign/{$req->token}/unlock", ['pass' => 'p1234']);
        $this->get("/sign/{$req->token}");
        $this->post("/sign/{$req->token}", ['signer_name' => 'الموقّع', 'signature' => $this->sig])->assertOk();

        return $req->fresh();
    }

    /** جسمُ الوثيقة الموقّعة يحمل QR ورابطَ الفتح العامّ */
    public function test_signed_document_body_carries_qr_and_open_link(): void
    {
        $this->seedCore();
        $req = $this->makeSigned();
        $this->assertSame('وُقّع', $req->status);

        $this->actingAs($this->owner)->get("/esign/{$req->id}/doc")
            ->assertOk()
            ->assertSee(route('sign.verify.doc', $req->verify_code))   // رابط الفتح المباشر
            ->assertSee('<svg', false);                                 // رمز QR مطبوع
    }

    /** مسحُ QR يفتح الوثيقة الموقّعة مباشرةً بلا حساب — نصّها وبانر الأصالة */
    public function test_public_open_by_code_shows_the_document(): void
    {
        $this->seedCore();
        $req = $this->makeSigned();
        auth()->logout();

        $this->get(route('sign.verify.doc', $req->verify_code))
            ->assertOk()
            ->assertSee('اتفاقية موقّعة')
            ->assertSee('نصُّ الاتفاقية الكامل')       // الوثيقة نفسها تُفتح
            ->assertSee('أصلية')                         // بانر الأصالة
            ->assertDontSee('عنوان الشبكة IP');          // بيانات الموقّع الحسّاسة محجوبة علناً
    }

    /** العرضُ الداخليّ (بحساب) يُبقي بيانات الموقّع كاملة — الحجبُ للعامّ فقط */
    public function test_internal_doc_keeps_full_signer_detail(): void
    {
        $this->seedCore();
        $req = $this->makeSigned();

        $this->actingAs($this->owner)->get("/esign/{$req->id}/doc")
            ->assertOk()
            ->assertSee('عنوان الشبكة IP');              // ظاهرٌ للمخوَّل داخليّاً
    }

    /** لا يُفتَح إلا الموقّع: المسودة أو الرمز الخاطئ → ٤٠٤ (لا يكشف غير الموقّع) */
    public function test_public_open_rejects_unsigned_and_bad_code(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/esign');
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'مسودة', 'free_body' => 'نصٌّ لم يُوقَّع بعد.', 'pass' => 'p1234',
        ]);
        $draft = SignRequest::latest('created_at')->first();
        auth()->logout();

        $this->get(route('sign.verify.doc', $draft->verify_code))->assertNotFound();
        $this->get(route('sign.verify.doc', 'LYN-0000-0000'))->assertNotFound();
    }
}
