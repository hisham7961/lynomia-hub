<?php

namespace Tests\Feature;

use App\Models\ContractApprovalStep;
use App\Models\SignRequest;
use App\Support\DocRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ٢: **سلامة الوثيقة القانونية ومسار التوقيع.**
 *
 * ثلاثة عيوبٍ مؤكَّدة في ملفَّين، كلها تمسّ ما يُطبَع أو يُعتمَد أو يُسلَّم:
 *
 *  ١) `DocRenderer:89` — توقيعُ الموقّع (مدخلٌ خارجيّ من طرفٍ بلا حساب) كان
 *     يُلصَق في الـHTML **بلا تهريب** بينما كل حقلٍ آخر في الدالة يمرّ بـ`e()`.
 *     فيصل `Mpdf::WriteHTML` نصٌّ يزرعه الموقّع: تزويرُ محتوى في الوثيقة
 *     القانونية المؤرشَفة (لا تكشفه `doc_hash` لأنها على النصّ لا على المخرَج)،
 *     وطلبٌ صادرٌ من داخل الشبكة عبر `<img src="http://10.0.0.x">`.
 *
 *  ٢) `EsignController:498` — تصفيرُ الاعتمادات بعد تحرير النصّ يبحث عن
 *     `status = 'معتمد'` بينما `approve()` يكتب `'موافق'` (السطر ٩٠٥). فالضابط
 *     خامدٌ ١٠٠٪: بنودُ عقدٍ تُبدَّل بعد اعتماد المالية ثمّ تُرسَل لتوقيعٍ ملزم
 *     والاعتمادُ لا يزال ملصقاً بنصٍّ لم يره صاحبه.
 *
 *  ٣) `EsignController:617` — نسخةُ العميل (`clientPdf`) تستدعي `docHtml`
 *     بلا وسمِ عميل، فتُسرَّب IP ورقمُ هوية **كل** موقّعٍ لبقية الأطراف — بخلاف
 *     `doc.blade.php` التي تحجبهما عن `$client` صراحةً بسياسةٍ موثّقة فيها.
 */
class EsignDocumentIntegrityTest extends TestCase
{
    /** طلبٌ موقَّعٌ بموقّعٍ واحد له أثرٌ حسّاس (IP + هوية) */
    protected function seedSigned(string $signature = 'data:image/png;base64,iVBORw0KGgo='): string
    {
        $rid = (string) Str::uuid();
        DB::table('sign_requests')->insert(['id' => $rid, 'title' => 'اتفاقيةٌ موقّعة',
            'body' => 'نصُّ الاتفاقية', 'doc_hash' => hash('sha256', 'نصُّ الاتفاقية'),
            'pass' => bcrypt('Secret1234'), 'token' => Str::random(48),
            'verify_code' => 'LYN-DOC1-TEST', 'status' => 'وُقّع',
            'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('contract_signers')->insert([
            'id' => (string) Str::uuid(), 'request_id' => $rid, 'order' => 1,
            'name' => 'الطرف الأول', 'role' => 'موقّع', 'token' => Str::random(48),
            'status' => 'وُقّع', 'id_no' => '299001234567', 'ip' => '85.12.3.44',
            'signature' => $signature, 'signed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $rid;
    }

    /* ── ١) حقنُ HTML عبر التوقيع ── */

    public function test_signature_cannot_inject_html_into_the_document(): void
    {
        $this->seedCore();
        // حمولةٌ تبدأ بالبادئة المسموحة فتمرّ حارس starts_with، ثمّ تكسر السمة
        $evil = 'data:image/png;base64,AAAA" onerror="x"><h1>مبلغُ العقد صفر</h1><img src="http://169.254.169.254/latest/meta-data" x="';
        $rid = $this->seedSigned($evil);

        $html = DocRenderer::docHtml(SignRequest::find($rid));

        $this->assertStringNotContainsString('<h1>', $html,
            'التوقيعُ زرع وسماً في الوثيقة — تزويرُ محتوى في مستندٍ قانونيّ مؤرشَف');
        $this->assertStringNotContainsString('169.254.169.254', $html,
            'التوقيعُ زرع طلباً صادراً من داخل الشبكة في وثيقةٍ تُرسم على الخادم');
        $this->assertStringNotContainsString('onerror=', $html);
    }

    public function test_a_legitimate_signature_still_renders(): void
    {
        $this->seedCore();
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $rid = $this->seedSigned($png);

        $html = DocRenderer::docHtml(SignRequest::find($rid));
        $this->assertStringContainsString($png, $html,
            'التوقيعُ المشروع يجب أن يظهر في الوثيقة — الحارس لا يكسر المسار السليم');
    }

    /* ── ٢) تصفيرُ الاعتمادات يطابق ما يكتبه approve() فعلاً ── */

    public function test_editing_the_body_resets_stages_approved_through_the_real_route(): void
    {
        $this->seedCore();

        $rid = (string) Str::uuid();
        DB::table('sign_requests')->insert(['id' => $rid, 'title' => 'عقدٌ بموافقات',
            'body' => 'النص أ', 'doc_hash' => hash('sha256', 'النص أ'),
            'pass' => bcrypt('Secret1234'), 'token' => Str::random(48),
            'verify_code' => 'LYN-APR2-TEST', 'status' => 'بانتظار الموافقة',
            'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
        // مرحلتان: تُعتمد الأولى بالمسار الحقيقي فتبقى الثانية معلّقة والطلب محجوزاً
        foreach ([1, 2] as $stage) {
            ContractApprovalStep::create(['request_id' => $rid, 'stage' => $stage,
                'kind' => 'مالية', 'label' => 'اعتماد ' . $stage, 'status' => 'بانتظار']);
        }

        $this->actingAs($this->owner)->post('/esign/' . $rid . '/approve', ['note' => 'موافق']);

        $s1 = ContractApprovalStep::where('request_id', $rid)->where('stage', 1)->first();
        $this->assertNotSame('بانتظار', $s1->status,
            'لم تُعتمد المرحلة عبر المسار الحقيقي — الاختبار لا يحرس شيئاً');
        $decided = $s1->status;

        // تحريرُ النصّ بعد الاعتماد يجب أن يُعيد السلسلة إلى أولها
        $this->actingAs($this->owner)->put('/esign/' . $rid, [
            'title' => 'عقدٌ بموافقات', 'body' => 'النص ب — بنودٌ مختلفة',
        ]);

        $this->assertSame('بانتظار',
            ContractApprovalStep::where('request_id', $rid)->where('stage', 1)->value('status'),
            "الاعتماد بحالة «{$decided}» نجا من تحرير النصّ — الضابط يبحث عن حالةٍ لا يكتبها أحد، "
            . 'فبنودٌ لم يرها المعتمِد تمضي إلى توقيعٍ ملزم');
    }

    /* ── ٣) نسخةُ العميل بلا أثرٍ حسّاس ── */

    public function test_client_copy_hides_ip_and_national_id(): void
    {
        $this->seedCore();
        $rid = $this->seedSigned();
        $req = SignRequest::find($rid);

        $client = DocRenderer::docHtml($req, evidence: false);
        $this->assertStringNotContainsString('299001234567', $client,
            'نسخةُ العميل تحمل رقمَ هوية الطرف الآخر — بخلاف سياسة doc.blade.php نفسها');
        $this->assertStringNotContainsString('85.12.3.44', $client,
            'نسخةُ العميل تحمل عنوانَ IP الطرف الآخر');
        $this->assertStringContainsString('الطرف الأول', $client,
            'الاسمُ والتوقيعُ يبقيان — المحجوبُ هو الأثرُ البيومتريّ وحده');

        // ونسخةُ المالك الداخلية تحتفظ بالأثر كاملاً — وإلا ضاع الدليل
        $owner = DocRenderer::docHtml($req);
        $this->assertStringContainsString('299001234567', $owner);
        $this->assertStringContainsString('85.12.3.44', $owner);
    }

    /** ومسارُ العميل نفسه يمرّر الوسم — لا الدالة وحدها */
    public function test_client_pdf_route_passes_the_client_flag(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Web/EsignController.php'));
        $this->assertMatchesRegularExpression(
            '/function clientPdf.*?docHtml\(\$req,\s*evidence:\s*false\)/s', $src,
            'clientPdf يستدعي docHtml بلا وسم عميل — فيُسرّب الأثر رغم إصلاح الدالة');
    }
}
