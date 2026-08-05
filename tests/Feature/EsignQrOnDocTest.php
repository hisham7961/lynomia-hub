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

    /** العرضُ الداخليّ (بحساب) يُبقي بيانات الموقّع كاملة — الحجبُ للخارج فقط */
    public function test_internal_doc_keeps_full_signer_detail(): void
    {
        $this->seedCore();
        $req = $this->makeSigned();

        $this->actingAs($this->owner)->get("/esign/{$req->id}/doc")
            ->assertOk()
            ->assertSee('عنوان الشبكة IP');              // ظاهرٌ للمخوَّل داخليّاً
    }

    /**
     * نسخةُ العميل ($client) — تصل الموقّعين الآخرين ومستلمي النسخ عبر sign.doc —
     * لا يجوز أن تكشف بيانات الموقّع الحسّاسة (IP/هوية/جهاز/سيلفي). كان الحجبُ
     * مبنيّاً على $public وحده فيتسرّب على مسار client.
     */
    public function test_client_doc_hides_signer_pii_from_other_parties(): void
    {
        $this->seedCore();
        $req = $this->makeSigned();   // يوقّع ويضبط signed_ip، وجلسة sign.ok مفتوحة

        $this->get("/sign/{$req->token}/doc")
            ->assertOk()
            ->assertSee('اتفاقية موقّعة')                 // الوثيقة تُعرض
            ->assertDontSee('عنوان الشبكة IP')            // بيانات الموقّع محجوبة عن الأطراف
            ->assertDontSee('الجهاز');
    }

    /**
     * الفتحُ العامّ عبر QR لا يلوّث سجل الأدلة القانوني (append-only) بأحداثٍ
     * يتحكّم بها مجهول: كان verifyDoc يكتب حدثَ verified على كل طلبٍ غير مُصادَق.
     */
    public function test_public_open_does_not_pollute_the_evidence_log(): void
    {
        $this->seedCore();
        $req = $this->makeSigned();
        auth()->logout();

        $this->get(route('sign.verify.doc', $req->verify_code))->assertOk();
        $this->get(route('sign.verify.doc', $req->verify_code))->assertOk();

        $this->assertSame(0, \App\Models\ContractEvent::where('request_id', $req->id)
            ->where('event', 'verified')->count(),
            'الفتحُ العامّ يكتب أحداثاً في سجل الأدلة يتحكّم بها مجهول');
    }

    /**
     * الوثيقةُ المنزَّلة/المرسَلة PDF تحمل QR أيضاً — لا الويب وحده. `docHtml`
     * (مصدرُ الـPDF عبر mPDF) كان يذكر رمز التحقّق نصّاً بلا صورةِ رمز، فالوثيقةُ
     * الرسمية التي تُطبَع وتُشارَك بلا QR. الآن الرمزُ مضمَّنٌ كصورةٍ (data-URI).
     */
    public function test_signed_pdf_document_embeds_the_qr(): void
    {
        $this->seedCore();
        $req = \App\Models\SignRequest::create([
            'title' => 'اتفاقية موقّعة', 'body' => 'نصُّ الاتفاقية الكامل.',
            'pass' => bcrypt('p1234'), 'token' => \Illuminate\Support\Str::random(48),
            'status' => 'وُقّع', 'verify_code' => 'LYN-TEST-0001',
            'signer_name' => 'الموقّع', 'signature' => 'data:image/png;base64,AAA',
            'signed_at' => now(), 'signed_ip' => '1.2.3.4',
        ]);

        $html = \App\Support\DocRenderer::docHtml($req->fresh());
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html,
            'وثيقةُ PDF الموقّعة بلا رمز QR — كان على الويب فقط لا على المستند المنزَّل');
        $this->assertStringContainsString($req->verify_code, $html);   // النصُّ يبقى للتحقّق اليدوي
    }

    /** غيرُ الموقّعة لا تحمل QR في الـPDF (كالويب) — الرمزُ للموقّع الأصيل فقط */
    public function test_unsigned_pdf_document_has_no_qr(): void
    {
        $this->seedCore();
        $req = \App\Models\SignRequest::create([
            'title' => 'مسودة', 'body' => 'نصٌّ لم يُوقَّع.',
            'pass' => bcrypt('p1234'), 'token' => \Illuminate\Support\Str::random(48),
            'status' => 'بانتظار التوقيع', 'verify_code' => 'LYN-TEST-0002',
        ]);

        $this->assertStringNotContainsString('data:image/svg+xml;base64,',
            \App\Support\DocRenderer::docHtml($req->fresh()));
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
