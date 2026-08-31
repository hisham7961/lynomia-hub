<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\QuoteMilestone;
use App\Models\SignRequest;
use Tests\TestCase;

/**
 * عروض المشاريع — البوّابة العامة للعميل (بلا حساب) واستنساخ القوالب.
 */
class QuoteTemplateAndPortalTest extends TestCase
{
    protected string $sig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sig = 'data:image/png;base64,' . base64_encode(str_repeat('لوحة توقيع', 20));
    }

    /** البوّابة العامة: العميلُ بلا حساب يرى العرضَ الاحترافيّ ويقبله بالتوقيع */
    public function test_public_portal_shows_proposal_and_accepts_on_sign(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل البوّابة']);
        $q = Quote::create(['client_id' => $c->id, 'title' => 'بوّابة المشروع', 'total' => 0,
            'currency' => 'د.ك', 'scope' => 'نطاقُ عملٍ يُعرَض في البوّابة', 'status' => 'مُرسل']);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'تحليل', 'kind' => 'مرحلة',
            'qty' => 1, 'unit_price' => 4500, 'unit_cost' => 1000]);
        $q->recalc();

        // الداخل: طلبُ توقيعٍ مربوطٌ بالعرض (quotes ضمن LINKABLE) برابطٍ وكلمة سر
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'قبول عرض المشروع', 'free_body' => 'يرجى مراجعة العرض وقبوله.',
            'pass' => 'sign1234', 'link_module' => 'quotes', 'link_id' => $q->id,
        ])->assertSessionHas('sign_link');

        $req = SignRequest::first();
        $this->assertSame('quotes', $req->link_module);
        $this->assertSame($q->id, $req->link_id);

        // العميل (بلا حساب): البوّابة ثم فتحُ العرض الاحترافيّ
        auth()->logout();
        $this->post("/sign/{$req->token}/unlock", ['pass' => 'sign1234'])->assertRedirect();
        $this->get("/sign/{$req->token}")->assertOk()
            ->assertSee('عرضُ مشروعٍ')                    // غلافُ العرض الاحترافيّ
            ->assertSee('نطاقُ عملٍ يُعرَض في البوّابة')   // النطاقُ المُشارَك
            ->assertSee('العرض التجاريّ')                  // جدولُ التسعير
            ->assertSee('4,500')                          // مبلغُ البند
            ->assertDontSee('1,000')                      // **لا تكلفةَ داخلية في البوّابة**
            ->assertSee('أوقّع وأوافق');

        // القبول بالتوقيع → العرض «مقبول» بأدلّةٍ كاملة (لا محرك قبولٍ ثانٍ)
        $this->post("/sign/{$req->token}", ['signer_name' => 'ممثّل العميل', 'signature' => $this->sig])
            ->assertOk();
        $q->refresh();
        $this->assertSame('مقبول', $q->status);
        $this->assertSame('ممثّل العميل', $q->accepted_by);
        $this->assertNotNull($q->accepted_at);
        $this->assertSame($req->fresh()->verify_code, ((array) $q->meta)['accept_sign'] ?? null);
    }

    /** استنساخُ القالب: مسودةٌ جديدةٌ تنسخ النطاقَ والبنودَ والمراحل بلا إعادة إدخال */
    public function test_clone_template_copies_scope_lines_and_milestones(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل القالب']);
        $tpl = Quote::create(['client_id' => $c->id, 'title' => 'قالبُ مشروعٍ رشيق', 'total' => 0,
            'currency' => 'د.ك', 'is_template' => true, 'scope' => 'نطاقٌ قياسيّ', 'status' => 'مسودة']);
        QuoteLine::create(['quote_id' => $tpl->id, 'title' => 'اكتشاف', 'kind' => 'مرحلة',
            'phase' => 'المرحلة ١', 'qty' => 1, 'unit_price' => 2000, 'unit_cost' => 500]);
        QuoteLine::create(['quote_id' => $tpl->id, 'title' => 'تنفيذ', 'kind' => 'مرحلة',
            'phase' => 'المرحلة ٢', 'qty' => 2, 'unit_price' => 1500]);
        QuoteMilestone::create(['quote_id' => $tpl->id, 'title' => 'دفعةٌ أولى', 'pct' => 50, 'trigger' => 'عند القبول']);
        $tpl->recalc();
        $tplTotal = (float) $tpl->fresh()->total;

        $this->assertTrue($tpl->fresh()->is_template);

        // استنساخٌ عبر إجراء المسار
        $this->actingAs($this->owner)
            ->post('/quote/' . $tpl->id . '/act', ['do' => 'clone'])
            ->assertRedirect();

        $new = Quote::where('id', '!=', $tpl->id)->latest('created_at')->first();
        $this->assertNotNull($new);
        $this->assertNotSame($tpl->doc_no, $new->doc_no, 'رقمٌ فريدٌ جديد');
        $this->assertSame('مسودة', $new->status);
        $this->assertFalse((bool) $new->is_template, 'النسخةُ عرضٌ حيّ لا قالب');
        $this->assertSame('نطاقٌ قياسيّ', $new->scope);
        $this->assertStringContainsString('نسخة من', (string) $new->title);
        $this->assertCount(2, $new->lines()->get());
        $this->assertCount(1, $new->milestones()->get());
        $this->assertSame($tplTotal, (float) $new->total, 'الإجماليُّ يُعاد حسابه فيطابق القالب');
        // التحويلُ لم يُورَّث: لا ربطَ مشروعٍ ولا قبولٍ منقول
        $this->assertNull($new->accepted_at);
        $this->assertEmpty((array) $new->meta);
    }

    /** الاستنساخُ يتطلب صلاحيةَ تعديل العروض (كإجراءات المسار) */
    public function test_clone_requires_edit_permission(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['client_id' => $c->id, 'total' => 100]);

        $role = \App\Models\Role::create(['name' => 'رؤية فقط', 'scope' => 'all', 'flags' => [],
            'matrix' => ['quotes' => ['v' => 1]]]);
        $u = \App\Models\User::create(['name' => 'قارئ', 'email' => 'ro@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($u)->post('/quote/' . $q->id . '/act', ['do' => 'clone'])->assertForbidden();
        $this->assertSame(1, Quote::count(), 'لا نسخةَ دون صلاحية');
    }
}
