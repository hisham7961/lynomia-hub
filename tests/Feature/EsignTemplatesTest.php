<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\SignRequest;
use App\Models\SignTemplate;
use App\Models\SignTemplateVersion;
use Tests\TestCase;

/** CLM م3 (v2.119): إدارة القوالب بإصدارات، سجل المتغيرات بالتعبئة التلقائية، والمعاينة */
class EsignTemplatesTest extends TestCase
{
    public function test_template_edit_snapshots_version_on_body_change(): void
    {
        $this->seedCore();
        $tpl = SignTemplate::create(['name' => 'قالب للإصدار', 'body' => 'النص الأول {اسم_الطرف_الثاني}']);

        $blocks = json_encode([['h' => 'البند الأول', 'body' => 'نصٌ معدل {اسم_الطرف_الثاني}', 'break' => false]], JSON_UNESCAPED_UNICODE);
        $this->actingAs($this->owner)->put('/esign/templates/' . $tpl->id, [
            'name' => 'قالب للإصدار', 'blocks' => $blocks, 'note' => 'تحسين الصياغة',
        ])->assertRedirect();

        $fresh = $tpl->fresh();
        $this->assertSame(2, (int) $fresh->version);
        $this->assertStringContainsString('نصٌ معدل', $fresh->body);
        $snap = SignTemplateVersion::where('template_id', $tpl->id)->first();
        $this->assertNotNull($snap);
        $this->assertSame(1, (int) $snap->version);
        $this->assertStringContainsString('النص الأول', $snap->body);   // اللقطة تحفظ القديم
        $this->assertSame('تحسين الصياغة', $snap->note);

        // حفظٌ بلا تغيير نصي لا يرفع الإصدار
        $this->actingAs($this->owner)->put('/esign/templates/' . $tpl->id, [
            'name' => 'اسم جديد فقط', 'blocks' => $blocks,
        ]);
        $this->assertSame(2, (int) $tpl->fresh()->version);
    }

    public function test_archived_template_leaves_send_form_but_old_requests_survive(): void
    {
        $this->seedCore();
        $tpl = SignTemplate::create(['name' => 'قالب للأرشفة', 'body' => 'نص {اسم_الطرف_الثاني}']);
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'طلب قبل الأرشفة', 'template_id' => $tpl->id,
            'vars' => ['اسم_الطرف_الثاني' => 'فلان'], 'pass' => 'Secret1234',
        ]);

        $this->actingAs($this->owner)->post('/esign/templates/' . $tpl->id . '/archive')->assertRedirect();
        $this->assertNotNull($tpl->fresh()->archived_at);

        $html = $this->actingAs($this->owner)->get('/esign')->assertOk()->getContent();
        // خيار الإرسال لا يحوي المؤرشف، وقائمة الإدارة تعرضه بشارة
        $this->assertStringNotContainsString('<option value="' . $tpl->id . '"', $html);
        $this->assertStringContainsString('مؤرشف', $html);
        // الطلب القديم سليم بنصه
        $this->assertSame('نص فلان', SignRequest::where('title', 'طلب قبل الأرشفة')->value('body'));

        // الاستعادة تعيده
        $this->actingAs($this->owner)->post('/esign/templates/' . $tpl->id . '/archive');
        $this->assertNull($tpl->fresh()->archived_at);
    }

    public function test_auto_fill_resolves_from_linked_contract_and_client(): void
    {
        $this->seedCore();
        $client = Client::create(['name' => 'شركة الذواقة للتموين', 'email' => 'gourmet@example.com']);
        $contract = Contract::create([
            'title' => 'عقد التموين', 'type' => 'عقد عميل', 'client_id' => $client->id,
            'value' => 4500, 'currency' => 'د.ك',
        ]);
        $tpl = SignTemplate::create(['name' => 'قالب التعبئة',
            'body' => 'الطرف الثاني: {اسم_الطرف_الثاني} — القيمة {المبلغ} {العملة} — الرقم {رقم_العقد} — بتاريخ {التاريخ}']);

        // بلا أي vars مدخلة — كلها تحل تلقائياً من العقد وعميله
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'طلب التعبئة', 'template_id' => $tpl->id,
            'pass' => 'Secret1234', 'contract_id' => $contract->id,
        ])->assertRedirect();

        $body = SignRequest::where('title', 'طلب التعبئة')->value('body');
        $this->assertStringContainsString('شركة الذواقة للتموين', $body);
        $this->assertStringContainsString('4,500', $body);
        $this->assertStringContainsString('د.ك', $body);
        $this->assertStringContainsString($contract->fresh()->doc_no, $body);
        $this->assertStringContainsString(now()->toDateString(), $body);
        $this->assertStringNotContainsString('{', $body, 'لا أقواس خام في الوثيقة');
    }

    public function test_required_missing_blocks_and_optional_gets_visible_marker(): void
    {
        $this->seedCore();
        $tpl = SignTemplate::create(['name' => 'قالب الإلزام',
            'body' => 'بين {اسم_الطرف_الثاني} و{اسم_شركتنا} — هوية: {هوية_الطرف_الثاني}']);

        // اسم_الطرف_الثاني إلزامي ولا ربط يحله → يُمنع الإنشاء برسالة واضحة
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'ناقص الإلزامي', 'template_id' => $tpl->id, 'pass' => 'Secret1234',
        ])->assertRedirect()->assertSessionHas('err');
        $this->assertNull(SignRequest::where('title', 'ناقص الإلزامي')->first());

        // مع تعبئته: الاختياري الفارغ (الهوية) يظهر علامةً ⟦⟧ لا قوسين خاويين
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'مكتمل الإلزامي', 'template_id' => $tpl->id, 'pass' => 'Secret1234',
            'vars' => ['اسم_الطرف_الثاني' => 'فلان'],
        ]);
        $body = SignRequest::where('title', 'مكتمل الإلزامي')->value('body');
        $this->assertStringContainsString('فلان', $body);
        $this->assertStringContainsString('⟦', $body);
        $this->assertStringNotContainsString('{هوية_الطرف_الثاني}', $body);
    }

    public function test_preview_endpoint_escapes_html_and_shows_missing(): void
    {
        $this->seedCore();
        $tpl = SignTemplate::create(['name' => 'قالب المعاينة', 'body' => 'مرحباً {اسم_الطرف_الثاني}']);

        $html = $this->actingAs($this->owner)->post('/esign/preview', [
            'template_id' => $tpl->id,
            'vars' => ['اسم_الطرف_الثاني' => '<script>alert(1)</script>'],
        ])->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);

        // بلا تعبئة: المعاينة تسمّي الإلزامي الناقص
        $html2 = $this->actingAs($this->owner)->post('/esign/preview', ['template_id' => $tpl->id])->assertOk()->getContent();
        $this->assertStringContainsString('متغيرات إلزامية', $html2);
    }

    public function test_blocks_flatten_into_body_and_wizard_renders(): void
    {
        $this->seedCore();
        $tpl = SignTemplate::create(['name' => 'قالب البنود', 'body' => 'قديم']);
        $blocks = json_encode([
            ['h' => 'التعريفات', 'body' => 'يقصد بالطرف الأول {اسم_شركتنا}.', 'break' => false],
            ['h' => 'المدة', 'body' => 'سنة من {تاريخ_البداية}.', 'break' => true],
        ], JSON_UNESCAPED_UNICODE);
        $this->actingAs($this->owner)->put('/esign/templates/' . $tpl->id, [
            'name' => 'قالب البنود', 'blocks' => $blocks,
        ]);
        $body = $tpl->fresh()->body;
        $this->assertStringContainsString("التعريفات\nيقصد", $body);
        $this->assertStringContainsString("المدة\nسنة", $body);
        $this->assertCount(2, $tpl->fresh()->blocks);

        // نموذج الإرسال: معالج الخطوات وسجل المتغيرات فيه
        $html = $this->actingAs($this->owner)->get('/esign')->assertOk()->getContent();
        $this->assertStringContainsString('data-wstep', $html);
        $this->assertStringContainsString('data-reg', $html);
        $this->assertStringContainsString('١ · القالب', $html);
        // صفحة تحرير القالب: مكتبة المتغيرات والإصدارات
        $html2 = $this->actingAs($this->owner)->get('/esign/templates/' . $tpl->id . '/edit')->assertOk()->getContent();
        $this->assertStringContainsString('varchip', $html2);
        $this->assertStringContainsString('الإصدارات', $html2);
    }

    public function test_template_routes_require_edit_permission(): void
    {
        $this->seedCore();
        $tpl = SignTemplate::create(['name' => 'محمي', 'body' => 'نص']);
        $this->actingAs($this->viewer)->get('/esign/templates/' . $tpl->id . '/edit')->assertForbidden();
        $this->actingAs($this->viewer)->put('/esign/templates/' . $tpl->id, [
            'name' => 'x', 'blocks' => '[]',
        ])->assertForbidden();
        $this->actingAs($this->viewer)->post('/esign/templates/' . $tpl->id . '/archive')->assertForbidden();
    }
}
