<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\OutboxMessage;
use App\Models\SignRequest;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** CLM م6 (v2.122): حزمة الأدلة — سلسلة التجزئة والشهادة وQR وPDF والنسخة المؤرشفة */
class EsignEvidenceTest extends TestCase
{
    protected string $png;

    protected function setUp(): void
    {
        parent::setUp();
        $this->png = 'data:image/png;base64,' . base64_encode(str_repeat('x', 120));
    }

    /** طلب قديم النمط موقّع بالكامل — عبر الرحلة العامة الحقيقية */
    protected function signedLegacy(?string $contractId = null): SignRequest
    {
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'وثيقة أدلة', 'free_body' => 'نص وثيقة الأدلة',
            'pass' => 'Pass1234', 'contract_id' => $contractId,
        ]);
        $req = SignRequest::where('title', 'وثيقة أدلة')->firstOrFail();
        $this->post("/sign/{$req->token}/unlock", ['pass' => 'Pass1234']);
        $this->get("/sign/{$req->token}");
        $this->post("/sign/{$req->token}", ['signer_name' => 'موقّع الأدلة', 'signature' => $this->png]);

        return $req->fresh();
    }

    public function test_completion_freezes_evidence_hash_and_recompute_matches(): void
    {
        $this->seedCore();
        $req = $this->signedLegacy();

        $this->assertNotNull($req->evidence_hash, 'رأس السلسلة يُجمَّد لحظة الاكتمال');
        [, $head] = \App\Support\Evidence::chain($req);
        $this->assertSame($head, $req->evidence_hash, 'إعادة الحساب تطابق المجمّد — السجل سليم');

        // السلسلة تكسر عند العبث: تغيير doc_hash يغيّر كل الحلقات
        $req->forceFill(['doc_hash' => hash('sha256', 'نص مزوّر')])->saveQuietly();
        [, $tampered] = \App\Support\Evidence::chain($req->fresh());
        $this->assertNotSame($req->evidence_hash, $tampered, 'العبث بالبصمة يكسر السلسلة');
    }

    public function test_certificate_internal_signed_only(): void
    {
        $this->seedCore();
        $req = $this->signedLegacy();

        $this->actingAs($this->owner)->get("/esign/{$req->id}/certificate")
            ->assertOk()->assertSee('شهادة إتمام التوقيع')
            ->assertSee($req->verify_code)->assertSee('موقّع الأدلة')
            ->assertSee(substr((string) $req->evidence_hash, 0, 20), false)
            ->assertSee('وُقّعت الوثيقة');

        // غير الموقعة لا شهادة لها
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'مسودة بلا شهادة', 'free_body' => 'نص', 'pass' => 'Pass1234',
        ]);
        $draft = SignRequest::where('title', 'مسودة بلا شهادة')->firstOrFail();
        $this->actingAs($this->owner)->get("/esign/{$draft->id}/certificate")->assertStatus(410);
    }

    public function test_certificate_via_signer_token_needs_unlock(): void
    {
        $this->seedCore();
        $req = $this->signedLegacy();

        // جلسة جديدة بلا فتح: 403
        $this->flushSession();
        $this->get("/sign/{$req->token}/certificate")->assertStatus(403);

        // بعد الفتح بكلمة السر: الشهادة تظهر
        $this->post("/sign/{$req->token}/unlock", ['pass' => 'Pass1234']);
        $this->get("/sign/{$req->token}/certificate")->assertOk()->assertSee('شهادة إتمام التوقيع');
    }

    public function test_pdf_download_real_binary(): void
    {
        $this->seedCore();
        $req = $this->signedLegacy();

        $res = $this->actingAs($this->owner)->get("/esign/{$req->id}/pdf");
        $res->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $res->getContent());
    }

    public function test_signed_copy_attached_to_contract_and_copies_mailed(): void
    {
        $this->seedCore();
        $c = Contract::create(['title' => 'عقد للأرشفة', 'type' => 'عقد عميل', 'status' => 'مسودة']);
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'وثيقة مؤرشفة', 'free_body' => 'نص الأرشفة', 'contract_id' => $c->id,
            'signers' => json_encode([
                ['name' => 'الموقّع', 'email' => 'signer@example.com', 'role' => 'موقّع'],
                ['name' => 'مطّلع', 'email' => 'cc@example.com', 'role' => 'مستلم نسخة'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $req = SignRequest::where('title', 'وثيقة مؤرشفة')->firstOrFail();
        $s = ContractSigner::where('request_id', $req->id)->where('role', 'موقّع')->firstOrFail();

        $s->forceFill(['otp_code' => Hash::make('123456'), 'otp_expires_at' => now()->addMinutes(10)])->save();
        $this->post("/sign/{$s->token}/unlock", ['otp' => '123456']);
        $this->get("/sign/{$s->token}");
        $this->post("/sign/{$s->token}", ['signer_name' => 'الموقّع', 'signature' => $this->png])->assertOk();

        // نسخة PDF موقعة على سجل العقد
        $att = Attachment::where('module', 'contracts')->where('record_id', $c->id)->first();
        $this->assertNotNull($att, 'النسخة الموقعة تُرفق على العقد تلقائياً');
        $this->assertSame('application/pdf', $att->mime);
        $pdf = \Illuminate\Support\Facades\Storage::disk('local')->get($att->path);
        $this->assertStringStartsWith('%PDF', $pdf);

        // بريد النسخة النهائية: للموقّع ولمستلم النسخة
        $this->assertSame(2, OutboxMessage::where('kind', 'sign_copy')->count());
        $this->assertTrue(OutboxMessage::where('kind', 'sign_copy')->where('target', 'cc@example.com')->exists());
    }

    public function test_verify_page_shows_signers_and_qr_for_multi(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'وثيقة تحقق علني', 'free_body' => 'نص التحقق',
            'signers' => json_encode([
                ['name' => 'الموقّع أ', 'email' => 'a@example.com', 'role' => 'موقّع'],
                ['name' => 'الموقّع ب', 'email' => 'b@example.com', 'role' => 'موقّع'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $req = SignRequest::where('title', 'وثيقة تحقق علني')->firstOrFail();
        foreach (ContractSigner::where('request_id', $req->id)->orderBy('order')->get() as $s) {
            $s->forceFill(['otp_code' => Hash::make('123456'), 'otp_expires_at' => now()->addMinutes(10)])->save();
            $this->post("/sign/{$s->token}/unlock", ['otp' => '123456']);
            $this->get("/sign/{$s->token}");
            $this->post("/sign/{$s->token}", ['signer_name' => $s->name, 'signature' => $this->png]);
        }
        $this->assertSame('وُقّع', $req->fresh()->status);

        $res = $this->post('/verify', ['code' => $req->verify_code]);
        $res->assertOk()->assertSee('مستند أصلي')
            ->assertSee('الموقّع أ')->assertSee('الموقّع ب')
            ->assertSee('<svg', false)
            ->assertSee('رأس سلسلة الأدلة');
    }
}
