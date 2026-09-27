<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AuditEntry;
use App\Models\Client;
use App\Models\HubNotification;
use App\Models\Quote;
use App\Models\Role;
use App\Models\SignRequest;
use App\Models\User;
use App\Support\Finance\QuoteAcceptance;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **قبولُ العرض الموحَّد** (TECH_DEBT #29 · ARCH-06..09): بابا القبول — زرُّ «قبول العميل»
 * في الويب، وتوقيعُ العميل الإلكترونيّ على طلبٍ مربوطٍ بالعرض — يمرّان بمحرّكٍ واحد
 * (`QuoteAcceptance::accept`) فيُخرجان **الأثرَ نفسَه**: أرشفةُ النسخة المقبولة، وسجلُّ
 * «كيف قُبل» في meta، وإشعارُ المعتمدين، وقيدُ تدقيقٍ واحد — مرّةً واحدةً مهما تكرّر.
 */
class QuoteAcceptanceUnifiedTest extends TestCase
{
    protected string $sig;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedCore();
        $this->sig = 'data:image/png;base64,' . base64_encode(str_repeat('لوحة توقيع', 20));
    }

    protected function quote(string $docNo, string $status = 'مُرسل'): Quote
    {
        $c = Client::create(['name' => 'عميل ' . $docNo]);

        return Quote::create(['doc_no' => $docNo, 'client_id' => $c->id, 'title' => 'عرض ' . $docNo,
            'total' => 1500, 'currency' => 'د.ك', 'status' => $status]);
    }

    protected function sales(string $email, array $fieldRules = []): User
    {
        $role = Role::create(['name' => 'مبيعات ' . $email, 'scope' => 'all', 'flags' => [],
            'matrix' => ['quotes' => ['v' => 1, 'a' => 1, 'e' => 1], 'clients' => ['v' => 1]],
            'field_rules' => $fieldRules]);

        return User::create(['name' => 'مندوب ' . $email, 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /** طلبُ توقيعٍ مربوطٌ بالعرض يُصدره المالك، ويُنسب إصدارُه لـ$issuer إن سُمّي */
    protected function envelope(Quote $q, ?User $issuer = null): SignRequest
    {
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'قبول ' . $q->doc_no, 'free_body' => 'يرجى مراجعة العرض وقبوله.',
            'pass' => 'sign1234', 'link_module' => 'quotes', 'link_id' => $q->id,
        ])->assertSessionHas('sign_link');
        $req = SignRequest::where('link_id', $q->id)->orderByDesc('id')->firstOrFail();
        if ($issuer) $req->forceFill(['created_by' => $issuer->id])->save();
        auth()->logout();

        return $req;
    }

    protected function signAsClient(SignRequest $req, string $name = 'ممثّل العميل'): void
    {
        $this->post("/sign/{$req->token}/unlock", ['pass' => 'sign1234'])->assertRedirect();
        $this->post("/sign/{$req->token}", ['signer_name' => $name, 'signature' => $this->sig])->assertOk();
    }

    protected function acceptedArchives(Quote $q): int
    {
        return Attachment::where('module', 'quotes')->where('record_id', $q->id)
            ->where('field', 'like', '%مقبول%')->count();
    }

    protected function acceptNotices(Quote $q): int
    {
        return HubNotification::where('record_id', $q->id)->where('kind', QuoteAcceptance::NOTIFY_KIND)->count();
    }

    protected function acceptAudits(Quote $q): int
    {
        return AuditEntry::where('action', QuoteAcceptance::AUDIT_ACTION)
            ->where('module', 'quotes')->where('record_id', $q->id)->count();
    }

    /** البابان يُخرجان الأثرَ نفسَه: أرشيف + meta + إشعار + تدقيق — مرّةً لكلّ منهما */
    public function test_web_and_esign_acceptance_produce_the_same_effects(): void
    {
        // (١) الويب — مندوبٌ يملك تعديلَ العروض
        $web = $this->quote('Q-UNI-WEB');
        $sales = $this->sales('uni-web@test.local');
        $this->actingAs($sales)->post("/quote/{$web->id}/act", ['do' => 'accept'])->assertRedirect();
        auth()->logout();

        // (٢) التوقيع — العميلُ بلا حساب على طلبٍ أصدره المندوبُ نفسُه
        $sig = $this->quote('Q-UNI-SIG');
        $req = $this->envelope($sig, $sales);
        $this->signAsClient($req);

        foreach ([$web, $sig] as $q) {
            $q->refresh();
            $this->assertSame('مقبول', $q->status, $q->doc_no);
            $this->assertNotNull($q->accepted_at, $q->doc_no);
            $this->assertSame(1, $this->acceptedArchives($q), 'النسخةُ المقبولة لم تُؤرشَف مرّةً واحدة: ' . $q->doc_no);
            $this->assertSame(1, $this->acceptAudits($q), 'قيدُ التدقيق ليس واحداً: ' . $q->doc_no);
            $this->assertGreaterThanOrEqual(1, $this->acceptNotices($q), 'لم يُشعَر المعتمدون: ' . $q->doc_no);
            $this->assertSame(1, HubNotification::where('record_id', $q->id)
                ->where('kind', QuoteAcceptance::NOTIFY_KIND)->where('user_id', $this->owner->id)->count(),
                'المالكُ لم يُشعَر مرّةً واحدة: ' . $q->doc_no);

            $acc = (array) (((array) $q->meta)['acceptance'] ?? []);
            $this->assertNotEmpty($acc['at'] ?? null, $q->doc_no);
            $archive = Attachment::whereKey($acc['archive_id'] ?? '')->first();
            $this->assertNotNull($archive, 'meta لا يشير إلى الأرشيف: ' . $q->doc_no);
            Storage::disk('local')->assertExists($archive->path);
        }

        $wa = (array) ((array) $web->meta)['acceptance'];
        $this->assertSame('web', $wa['via']);
        $this->assertSame($sales->id, $wa['user_id']);

        $sa = (array) ((array) $sig->meta)['acceptance'];
        $this->assertSame('esign', $sa['via']);
        $this->assertSame($req->id, $sa['envelope_id']);
        $this->assertSame($req->fresh()->verify_code, $sa['verify_code']);
        $this->assertSame('ممثّل العميل', $sa['signer']);
        $this->assertSame($sales->id, $sa['issued_by']);
        $this->assertSame('ممثّل العميل', $sig->accepted_by);
        // العقدُ القائم محفوظ: رمزُ التحقق في accept_sign كما كان
        $this->assertSame($req->fresh()->verify_code, ((array) $sig->meta)['accept_sign'] ?? null);
    }

    /** الويبُ متكرّرٌ بلا أثرٍ مكرّر — ولا يُعيد «محوّلاً» إلى «مقبول» */
    public function test_web_acceptance_is_idempotent(): void
    {
        $q = $this->quote('Q-UNI-IDEM');
        $sales = $this->sales('uni-idem@test.local');
        $this->actingAs($sales)->post("/quote/{$q->id}/act", ['do' => 'accept'])->assertRedirect();
        $this->actingAs($sales)->post("/quote/{$q->id}/act", ['do' => 'accept'])->assertRedirect();

        $this->assertSame(1, $this->acceptedArchives($q), 'قبولٌ مكرّرٌ أرشف مرّتين');
        $this->assertSame(1, $this->acceptAudits($q), 'قبولٌ مكرّرٌ دقّق مرّتين');
        $this->assertSame(1, HubNotification::where('record_id', $q->id)->where('kind', QuoteAcceptance::NOTIFY_KIND)
            ->where('user_id', $this->owner->id)->count(), 'قبولٌ مكرّرٌ أشعر مرّتين');

        $conv = $this->quote('Q-UNI-CONV', 'محوّل');
        $this->actingAs($sales)->post("/quote/{$conv->id}/act", ['do' => 'accept'])->assertRedirect();
        $this->assertSame('محوّل', $conv->fresh()->status, 'زرُّ القبول أعاد عرضاً محوّلاً إلى «مقبول»');
        $this->assertSame(0, $this->acceptAudits($conv));
    }

    /** قبولٌ بالويب ثم توقيعٌ متأخّر على العرض نفسِه: لا أرشيفَ ولا إشعارَ ولا تدقيقَ ثانٍ */
    public function test_cross_path_acceptance_is_idempotent(): void
    {
        $q = $this->quote('Q-UNI-X');
        $sales = $this->sales('uni-x@test.local');
        $req = $this->envelope($q, $sales);

        $this->actingAs($sales)->post("/quote/{$q->id}/act", ['do' => 'accept'])->assertRedirect();
        auth()->logout();
        $this->signAsClient($req);

        $q->refresh();
        $this->assertSame('web', ((array) ((array) $q->meta)['acceptance'])['via'], 'التوقيعُ المتأخّر داس سجلَّ القبول');
        $this->assertSame(1, $this->acceptedArchives($q));
        $this->assertSame(1, $this->acceptAudits($q));
        $this->assertSame(1, HubNotification::where('record_id', $q->id)->where('kind', QuoteAcceptance::NOTIFY_KIND)
            ->where('user_id', $this->owner->id)->count());

        // والنداءُ المباشر للمحرّك مرّةً أخرى لا يُكرّر شيئاً
        $this->assertFalse(QuoteAcceptance::accept($q->fresh(), $sales, ['via' => 'web']));
        $this->assertSame(1, $this->acceptedArchives($q));
    }

    /**
     * **ثغرةٌ (اختبارٌ فشل أولاً):** دورٌ حقلُ حالته «قراءة فقط» يُردّ من زرّ القبول (403)
     * لكنّه كان يلتفّ بإصدار طلبِ توقيعٍ مربوطٍ بالعرض — فيقلبه توقيعُ العميل «مقبول».
     * الآن قفلُ الحقل يسري على مُصدِر الطلب كما يسري على الزرّ.
     */
    public function test_status_locked_issuer_cannot_accept_through_esign(): void
    {
        $q = $this->quote('Q-UNI-RO');
        $ro = $this->sales('uni-ro@test.local', ['quotes' => ['status' => 'ro']]);

        $req = $this->envelope($q, $ro);
        $this->signAsClient($req);

        $q->refresh();
        $this->assertSame('مُرسل', $q->status, 'التوقيعُ قلب الحالةَ رغم قفلها لدى مُصدِر الطلب');
        $this->assertSame(0, $this->acceptedArchives($q));
        $this->assertSame(0, $this->acceptAudits($q));
        // التوقيعُ نفسُه محفوظ، والمعتمدون يُبلَّغون ليقبلوا يدوياً
        $this->assertSame('وُقّع', $req->fresh()->status);
        $this->assertTrue(HubNotification::where('user_id', $this->owner->id)
            ->where('text', 'like', '%' . $q->doc_no . '%')->where('text', 'like', '%يدوي%')->exists(),
            'لم يُبلَّغ المعتمدون بتوقيعٍ تعذّر قبولُه آلياً');
    }
}
