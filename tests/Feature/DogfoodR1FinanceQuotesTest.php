<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\FinDocument;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * **محاكاة الاستعمال البشري — الجولة 1 · المالية والعروض** (F14–F20 · F27 · F36).
 *
 * إثباتٌ لا ادّعاء: كل حالةٍ هنا كُتبت لتفشل على السلوك القائم يومَ كتابتها
 * (نتائج محاكاة المحاسب يوسف والمبيعات فهد)، ثم أُصلح السلوك حتى اخضرّت:
 *
 *   F14  تعديلُ إجماليّ مستندٍ «مدفوع» كان يمرّ بصمت والحالة تبقى «مدفوعة».
 *   F15  الدفعةُ كانت مبلغاً صامتاً بلا تاريخ/مرجع ولا سبيلَ عكسٍ لخطأ الإدخال.
 *   F16  توجيهُ قبضٍ لحسابٍ بنكيّ كان محسوماً بـbanks:e وحدها — فالأرصدة لا تتحرك.
 *   F17  تصديرُ CSV خارج الدوام كان محظوراً بلا استثناء — إقفالُ الشهر ليلاً مستحيل.
 *   F18  فلترُ «متأخرة» كان حالةً مكتوبة لا استحقاقاً محسوباً — والعميل عمودٌ فارغ.
 *   F19  بندُ عرضٍ بخصمٍ فارغ كان يسقط بشاشة Internal Server Error.
 *   F20  نظاما بنودٍ لا يتكلّمان: نصٌّ ميت (الإجمالي 0.00) مقابل بنّاءٍ مهيكل.
 *   F27  المسنَدُ إليه كان ممنوعاً من تحريك حالة/تقدّم مهمّته والكانبان يفشل صامتاً.
 *   F36  أسماءُ السجلات في الجداول لم تكن روابط — صيدُ أيقونة 👁 وحدها.
 */
class DogfoodR1FinanceQuotesTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();   // اختبار F17 يجمّد الساعة — لا تسريبَ توقيتٍ لاختبارٍ تالٍ
        parent::tearDown();
    }

    /** مستخدمٌ بمصفوفة صلاحياتٍ محددة — نمطُ Permissions360FineGrainedTest */
    private function user(string $email, array $matrix, array $flags = []): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => $flags,
            'matrix' => $matrix, 'companies' => null]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function invoice(array $extra = []): FinDocument
    {
        return FinDocument::create(array_merge([
            'doc_no' => 'DG-' . strtoupper(substr(uniqid(), -6)), 'kind' => 'فاتورة مبيعات',
            'date' => now()->toDateString(), 'total' => 100, 'paid' => 0, 'state' => 'مرسلة',
        ], $extra));
    }

    /* ═══════════ F14 — تعديلُ مستندٍ مدفوعٍ يعيد اشتقاقَ الحالة من المدفوع الفعليّ ═══════════ */

    public function test_f14_editing_paid_doc_total_rederives_state_with_message(): void
    {
        $this->seedCore();
        $doc = $this->invoice(['doc_no' => 'INV-F14', 'total' => 2500, 'state' => 'معتمدة']);

        // سدادٌ كامل عبر زرّ «دفع» — مصدرُ الحقيقة القائم
        $this->actingAs($this->owner)->post('/fin/' . $doc->id . '/act', ['do' => 'pay', 'amount' => 2500]);
        $this->assertSame('مدفوعة', $doc->fresh()->state);

        // رفعُ الإجماليّ 2500 → 2600 من نموذج التعديل (الحالةُ تُعاد كما يعيدها النموذج)
        $resp = $this->actingAs($this->owner)->put('/m/fin/' . $doc->id, [
            'no' => 'INV-F14', 'kind' => 'فاتورة مبيعات', 'total' => '2600', 'state' => 'مدفوعة',
        ]);
        $resp->assertRedirect();
        $resp->assertSessionHas('ok');

        $doc->refresh();
        $this->assertSame('مدفوعة جزئياً', $doc->state,
            'إجماليٌّ ارتفع فوق المدفوع (2500 من 2600) والمستند بقي «مدفوعة» — عيب F14');
        $this->assertEquals(2500.0, (float) $doc->paid, 'المدفوع الفعليّ لا يُمسّ بتعديل الإجمالي');
        $this->assertStringContainsString('اشتقاق', (string) session('ok'),
            'إعادةُ الاشتقاق تقع بصمت — الرسالة يجب أن تقول ما جرى');
    }

    public function test_f14_hand_planting_paid_state_without_payment_is_rederived(): void
    {
        $this->seedCore();
        $doc = $this->invoice(['doc_no' => 'INV-F14B', 'total' => 1000, 'state' => 'معتمدة']);

        // زرعُ «مدفوعة» من نموذج التعديل بلا مبلغٍ مدفوع (الكانبان محميّ بـstatus_via_action
        // والنموذجُ كان البابَ الجانبيّ) — المدفوعُ الفعليّ (0) هو الحقيقة
        $this->actingAs($this->owner)->put('/m/fin/' . $doc->id, [
            'no' => 'INV-F14B', 'kind' => 'فاتورة مبيعات', 'total' => '1000', 'state' => 'مدفوعة',
        ])->assertRedirect();

        $this->assertSame('معتمدة', $doc->fresh()->state,
            '«مدفوعة» زُرعت يدوياً بلا مدفوعٍ فعليّ ولم تُعَد للاشتقاق');
    }

    /* ═══════════ F15 — الدفعةُ سجلٌّ محترم (تاريخ/مرجع/ملاحظة) وعكسُها موثَّقٌ بسبب ═══════════ */

    public function test_f15_payment_carries_record_and_reverse_restores_everything(): void
    {
        $this->seedCore();
        $bank = BankAccount::create(['name' => 'بنك الاختبار', 'balance' => 0]);
        $doc = $this->invoice(['doc_no' => 'INV-F15', 'total' => 100]);

        // دفعةٌ بتاريخٍ ومرجعٍ وملاحظة — تُخزَّن سجلاً في meta.payments وتحرّك البنك
        $this->actingAs($this->owner)->post('/fin/' . $doc->id . '/act', [
            'do' => 'pay', 'amount' => 40, 'bankId' => $bank->id,
            'payDate' => '2026-09-10', 'payRef' => 'TRX-9', 'payNote' => 'دفعة أولى',
        ])->assertRedirect();

        $doc->refresh();
        $this->assertEquals(40.0, (float) $doc->paid);
        $this->assertSame('مدفوعة جزئياً', $doc->state);
        $this->assertEquals(40.0, (float) $bank->fresh()->balance);

        // السجل يُؤكَّد قيمةً قيمة — مفاتيحُ كائن JSON تُعاد ترتيباً على MySQL 8
        $payments = (array) (((array) $doc->meta)['payments'] ?? []);
        $this->assertCount(1, $payments, 'الدفعة لم تُسجَّل سجلاً في meta.payments');
        $this->assertEquals(40.0, (float) $payments[0]['amount']);
        $this->assertSame('2026-09-10', (string) $payments[0]['at']);
        $this->assertSame('TRX-9', (string) $payments[0]['ref']);
        $this->assertSame('دفعة أولى', (string) $payments[0]['note']);
        $this->assertSame((string) $this->owner->id, (string) $payments[0]['by']);

        // عكسُ الدفعة بسببٍ إلزاميّ: يعيد المبلغَ والحالةَ ورصيدَ البنك ويوثَّق في التدقيق
        $this->actingAs($this->owner)->post('/fin/' . $doc->id . '/act', [
            'do' => 'reverse', 'amount' => 40, 'reason' => 'خطأ إدخال: 2,50.00 فُسّرت 250.00',
        ])->assertRedirect();

        $doc->refresh();
        $this->assertEquals(0.0, (float) $doc->paid, 'العكس لم يُعد المبلغ');
        $this->assertSame('معتمدة', $doc->state, 'العكس لم يُعد اشتقاق الحالة');
        $this->assertEquals(0.0, (float) $bank->fresh()->balance, 'العكس لم يُعد رصيد البنك');

        $rev = collect((array) (((array) $doc->meta)['payments'] ?? []))
            ->first(fn ($p) => (float) ($p['amount'] ?? 0) < 0);
        $this->assertNotNull($rev, 'العكس لم يُسجَّل سجلاً معاكساً في meta.payments');
        $this->assertEquals(-40.0, (float) $rev['amount']);
        $this->assertStringContainsString('خطأ إدخال', (string) $rev['reason']);

        $this->assertTrue(AuditEntry::where('action', 'عكس دفعة')->where('record_id', $doc->id)->exists(),
            'عكسُ الدفعة بلا قيد تدقيق');
    }

    public function test_f15_reverse_requires_reason_and_caps_at_paid(): void
    {
        $this->seedCore();
        $doc = $this->invoice(['doc_no' => 'INV-F15B', 'total' => 100]);
        $this->actingAs($this->owner)->post('/fin/' . $doc->id . '/act', ['do' => 'pay', 'amount' => 30]);

        // بلا سبب: يُرفض قبل أي كتابة
        $this->actingAs($this->owner)->post('/fin/' . $doc->id . '/act', [
            'do' => 'reverse', 'amount' => 30,
        ])->assertSessionHasErrors('reason');
        $this->assertEquals(30.0, (float) $doc->fresh()->paid);

        // فوق المدفوع: يُرفض برسالة لا يُقصّ بصمت
        $this->actingAs($this->owner)->post('/fin/' . $doc->id . '/act', [
            'do' => 'reverse', 'amount' => 500, 'reason' => 'تصحيح',
        ])->assertStatus(422);
        $this->assertEquals(30.0, (float) $doc->fresh()->paid);

        // مبلغٌ بفواصل («2,50.00») لا يُفسَّر بصمت — يُردّ برسالة تحقّقٍ عربية
        $this->actingAs($this->owner)->post('/fin/' . $doc->id . '/act', [
            'do' => 'pay', 'amount' => '2,50.00',
        ])->assertSessionHasErrors('amount');
        $this->assertEquals(30.0, (float) $doc->fresh()->paid);
    }

    /* ═══════════ F16 — مفتاح bankPost: قبضٌ/صرفٌ يحرّك رصيداً بنكياً دون banks:e ═══════════ */

    public function test_f16_bankpost_key_routes_payment_to_bank_without_banks_edit(): void
    {
        $this->seedCore();
        $bank = BankAccount::create(['name' => 'بنك المحاسب', 'balance' => 0]);

        // المفتاح معلَنٌ في الكتالوج (بلا نقاط) فلا يمحوه محرّر الأدوار
        $this->assertArrayHasKey('bankPost', config('hub_permissions'),
            'مفتاح bankPost غير معلَن في كتالوج hub_permissions');

        // المحاسب يوسف: يعدّل المالية ويرى البنوك ويحمل bankPost — لا banks:e
        $accountant = $this->user('yousef@test.local', [
            'fin' => ['v' => 1, 'e' => 1],
            'banks' => ['v' => 1, 'bankPost' => 1],
        ]);
        $doc = $this->invoice(['doc_no' => 'INV-F16', 'total' => 100]);

        $this->actingAs($accountant)->post('/fin/' . $doc->id . '/act', [
            'do' => 'pay', 'amount' => 50, 'bankId' => $bank->id,
        ])->assertRedirect();

        $this->assertEquals(50.0, (float) $doc->fresh()->paid, 'دفعة حامل bankPost لم تُسجَّل');
        $this->assertEquals(50.0, (float) $bank->fresh()->balance,
            'المحاسب ممنوعٌ من توجيه القبض للبنك — الأرصدة لا تتحرك أبداً (عيب F16)');

        // ومن لا يحمل المفتاح ولا الراية يبقى مردوداً — لا كسرَ للحارس
        $noKey = $this->user('nokey@test.local', [
            'fin' => ['v' => 1, 'e' => 1], 'banks' => ['v' => 1],
        ]);
        $doc2 = $this->invoice(['doc_no' => 'INV-F16B', 'total' => 100]);
        $this->actingAs($noKey)->post('/fin/' . $doc2->id . '/act', [
            'do' => 'pay', 'amount' => 50, 'bankId' => $bank->id,
        ])->assertStatus(422);
        $this->assertEquals(0.0, (float) $doc2->fresh()->paid);
    }

    /* ═══════════ F17 — مفتاح exportNight: تصديرُ CSV خارج الدوام لحامله وحده، موسوماً ═══════════ */

    public function test_f17_exportnight_key_exempts_csv_export_outside_work_hours(): void
    {
        $this->seedCore();
        $this->assertArrayHasKey('exportNight', config('hub_permissions'),
            'مفتاح exportNight غير معلَن في كتالوج hub_permissions');

        $this->hubSetting('sec.hours_on', '1');            // نظام ساعات العمل مفعَّل
        Carbon::setTestNow(Carbon::parse(now()->toDateString() . ' 22:30:00'));   // خارج الدوام

        $doc = $this->invoice(['doc_no' => 'INV-F17']);

        // موظفٌ يملك التصدير لكن بلا exportNight: القيد يبقى عليه (تصدير المحدد أيضاً)
        $dayOnly = $this->user('day@test.local', ['fin' => ['v' => 1, 'export' => 1]]);
        $this->actingAs($dayOnly)->post('/m/fin/bulk', ['do' => 'export', 'ids' => [$doc->id]])
            ->assertStatus(403);

        // المحاسب الليليّ: exportNight يستثنيه — والاستعمال يُوسَم في التدقيق
        $night = $this->user('night@test.local', ['fin' => ['v' => 1, 'export' => 1, 'exportNight' => 1]]);
        $this->actingAs($night)->post('/m/fin/bulk', ['do' => 'export', 'ids' => [$doc->id]])
            ->assertOk();
        $this->assertTrue(AuditEntry::where('action', 'like', '%خارج الدوام%')->exists(),
            'استعمالُ استثناء exportNight بلا قيد تدقيق');

        // داخل الدوام يبقى كل شيء كما كان — لا وسمَ ليليّ
        Carbon::setTestNow(Carbon::parse(now()->toDateString() . ' 10:00:00'));
        $this->actingAs($dayOnly)->post('/m/fin/bulk', ['do' => 'export', 'ids' => [$doc->id]])
            ->assertOk();
    }

    /* ═══════════ F18 — «متأخرة فعلاً» بالاستحقاق لا بالحالة + عمود العميل لا يبقى فارغاً ═══════════ */

    public function test_f18_overdue_is_computed_from_due_date_not_written_state(): void
    {
        $this->seedCore();
        $od = $this->invoice(['doc_no' => 'OVDX-1', 'due' => now()->subDays(5)->toDateString()]);
        $paid = $this->invoice(['doc_no' => 'PAIDX-1', 'due' => now()->subDays(3)->toDateString(),
            'paid' => 100, 'state' => 'مدفوعة']);
        $fut = $this->invoice(['doc_no' => 'FUTX-1', 'due' => now()->addDays(3)->toDateString()]);

        // الشارةُ المحسوبة تظهر في القائمة رغم أن الحالة المكتوبة «مرسلة»
        $this->actingAs($this->owner)->get('/m/fin')
            ->assertOk()
            ->assertSee('متأخرة فعلاً (5 يوماً');

        // العرضُ الجاهز يلتقطها بالاستحقاق: المتجاوزةُ غير المسدَّدة وحدها
        $this->actingAs($this->owner)->get('/m/fin?overdue=1')
            ->assertOk()
            ->assertSee('OVDX-1')
            ->assertDontSee('PAIDX-1')
            ->assertDontSee('FUTX-1');

        // الحسابُ نفسُه معلنٌ دالةً واحدة
        $this->assertSame(5, FinDocument::overdueDays($od->fresh()));
        $this->assertNull(FinDocument::overdueDays($paid->fresh()), 'المسدَّدة ليست متأخرة');
        $this->assertNull(FinDocument::overdueDays($fut->fresh()), 'المستقبلية ليست متأخرة');
    }

    public function test_f18_partner_column_falls_back_to_linked_client_name(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'شركة الياسمين المحدودة']);

        // التشخيص الجذري: السجل يُربط بمرجع clientId بينما القائمة تعرض عمود partner
        // النصيّ — فيبدو «عمود العميل فارغاً في كل الصفوف» رغم أن الربط قائم
        $doc = $this->invoice(['doc_no' => 'PRTX-1', 'partner' => null, 'client_id' => $c->id]);

        $this->assertSame('شركة الياسمين المحدودة', (string) $doc->fresh()->partner,
            'partner فارغ رغم عميلٍ مرتبط — العرض يقرأ عموداً والربط في عمودٍ آخر (عيب F18)');

        // والنصُّ المُدخل يدوياً يبقى هو الفائز حين يوجد
        $manual = $this->invoice(['doc_no' => 'PRTX-2', 'partner' => 'مورد نقدي', 'client_id' => $c->id]);
        $this->assertSame('مورد نقدي', (string) $manual->fresh()->partner);

        $this->actingAs($this->owner)->get('/m/fin')->assertSee('شركة الياسمين المحدودة');
    }

    /* ═══════════ F19 — بندُ عرضٍ بخصمٍ فارغ: تطبيعٌ لا Internal Server Error ═══════════ */

    public function test_f19_empty_discount_pct_is_normalized_not_500(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل العرض']);
        $q = Quote::create(['doc_no' => 'QT-F19', 'client_id' => $c->id, 'status' => 'مسودة']);

        // خصمٌ وضريبةٌ فارغان كانا يمرّان null إلى عمودَي NOT NULL ⇒ 500 بشاشة debug
        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/line', [
            'title' => 'تطوير واجهة', 'qty' => '2', 'unit_price' => '10',
            'discount_pct' => '', 'tax_pct' => '',
        ])->assertRedirect();

        $line = QuoteLine::where('quote_id', $q->id)->orderBy('id')->first();
        $this->assertNotNull($line, 'البند لم يُحفظ — سقط الطلب بخطأ خادم (عيب F19)');
        $this->assertEquals(0.0, (float) $line->discount_pct);
        $this->assertEquals(20.0, (float) $line->line_total);
        $this->assertEquals(20.0, (float) $q->fresh()->total);

        // وقيمةٌ غير رقمية تُردّ برسالة تحقّق (422) لا بخطأ خادم
        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/line', [
            'title' => 'بند معطوب', 'qty' => '1', 'unit_price' => '10', 'discount_pct' => 'abc',
        ])->assertSessionHasErrors('discount_pct');
        $this->assertSame(1, QuoteLine::where('quote_id', $q->id)->count());
    }

    /* ═══════════ F20 — نظاما البنود يتكلّمان: النصُّ يتحوّل بنوداً مهيكلة والترقيم مصدرٌ واحد ═══════════ */

    public function test_f20_items_text_becomes_structured_lines_and_totals_compute(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل النص']);

        // إنشاءُ عرضٍ بخانة البنود النصية — كانت تُحفظ نصاً ميتاً والإجمالي يبقى 0.00
        $this->actingAs($this->owner)->post('/m/quotes', [
            'no' => '', 'clientId' => $c->id,
            'items' => "تصميم هوية | 2 | 100\nتطوير متجر | 1 | 300",
        ])->assertSessionHasNoErrors();

        $q = Quote::where('client_id', $c->id)->orderBy('id')->first();
        $this->assertNotNull($q);
        $lines = QuoteLine::where('quote_id', $q->id)->orderBy('sort')->orderBy('id')->get();
        $this->assertCount(2, $lines, 'نصُّ البنود لم يتحوّل بنوداً مهيكلة (عيب F20)');
        // القيم قيمةً قيمة — لا تأكيدَ على مصفوفةٍ كاملةٍ من عمود JSON
        $this->assertSame('تصميم هوية', (string) $lines[0]->title);
        $this->assertEquals(2.0, (float) $lines[0]->qty);
        $this->assertEquals(100.0, (float) $lines[0]->unit_price);
        $this->assertSame('items', (string) (((array) $lines[0]->meta)['source'] ?? ''));
        $this->assertSame('items', (string) (((array) $lines[1]->meta)['source'] ?? ''));
        $this->assertEquals(500.0, (float) $q->fresh()->total,
            'الإجمالي بقي 0.00 بينما البنود النصية تقول 500 (عيب F20)');

        // بندُ بنّاءٍ مهيكلٍ يُضاف جنباً إلى جنب…
        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/line', [
            'title' => 'استضافة سنوية', 'qty' => '1', 'unit_price' => '50',
        ])->assertRedirect();
        $this->assertEquals(550.0, (float) $q->fresh()->total);

        // …وتعديلُ النص يستبدل بنودَ النص وحدها ويبقي بنود البنّاء
        $this->actingAs($this->owner)->put('/m/quotes/' . $q->id, [
            'no' => $q->doc_no, 'clientId' => $c->id, 'items' => 'تصميم هوية | 3 | 100',
        ])->assertSessionHasNoErrors();
        $q->refresh();
        $this->assertEquals(350.0, (float) $q->total, 'استبدالُ بنود النص لم يُعد الحساب (50 + 300)');
        $this->assertSame(2, QuoteLine::where('quote_id', $q->id)->count());

        // وسطرٌ معطوب (كمية ليست رقماً) يُردّ برسالةٍ تسمّي السطر — لا حفظَ صامتاً
        $this->actingAs($this->owner)->put('/m/quotes/' . $q->id, [
            'no' => $q->doc_no, 'clientId' => $c->id, 'items' => 'خدمة | كثير | 10',
        ])->assertSessionHasErrors('items');
        $this->assertEquals(350.0, (float) $q->fresh()->total);
    }

    public function test_f20_doc_numbering_continues_one_visible_sequence(): void
    {
        $this->seedCore();
        $year = now()->format('Y');

        // أرقامٌ قديمة أُدخلت يدوياً بصيغة Q-{سنة}-{تسلسل} (بيانات قائمة) —
        // المولّد كان يبدأ عدّاداً موازياً QT-{سنة}-0001 فيظهر ترقيمان متنافران
        Quote::create(['doc_no' => 'Q-' . $year . '-014', 'total' => 10]);

        $this->assertSame('QT-' . $year . '-0015', Quote::nextDocNo(),
            'المولّد يفتح عدّاداً موازياً بدل مواصلة التسلسل الظاهر من مصدرٍ واحد (عيب F20)');
    }

    /* ═══════════ F27 — المسنَدُ إليه يحرّك حالةَ مهمّته وتقدّمَها ولو بلا tasks:e ═══════════ */

    public function test_f27_assignee_moves_own_task_status_and_progress(): void
    {
        $this->seedCore();
        $worker = $this->user('worker@test.local', ['tasks' => ['v' => 1]]);   // بلا e
        $task = Task::create(['title' => 'مهمة فهد', 'status' => 'جديدة', 'assignee_id' => $worker->id]);

        $resp = $this->actingAs($worker)->postJson('/m/tasks/' . $task->id . '/status', [
            'status' => 'قيد التنفيذ', 'progress' => 40,
        ]);
        $resp->assertOk()->assertJson(['ok' => 1]);

        $task->refresh();
        $this->assertSame('قيد التنفيذ', $task->status,
            'المسنَد إليه ممنوعٌ من تحريك حالة مهمّته (عيب F27)');
        $this->assertEquals(40.0, (float) $task->progress, 'تقدّمُ المهمة لم يُحدَّث مع الحالة');
    }

    public function test_f27_non_assignee_without_edit_is_refused_with_a_message(): void
    {
        $this->seedCore();
        $worker = $this->user('worker2@test.local', ['tasks' => ['v' => 1]]);
        $task = Task::create(['title' => 'مهمة غيره', 'status' => 'جديدة', 'assignee_id' => $this->employee->id]);

        // ليست مهمتَه ولا يملك e ⇒ ٤٠٣ برسالةٍ يعرضها toast الكانبان (لا صمت)
        $resp = $this->actingAs($worker)->postJson('/m/tasks/' . $task->id . '/status', ['status' => 'قيد التنفيذ']);
        $resp->assertStatus(403);
        $this->assertNotSame('', (string) ($resp->json('message') ?? ''),
            'الرفض بلا رسالة — البطاقة تعود صامتة في الكانبان');
        $this->assertSame('جديدة', $task->fresh()->status);
    }

    /* ═══════════ F36 — اسمُ السجل في الجدول رابطٌ لصفحته ═══════════ */

    public function test_f36_display_column_links_to_show_page(): void
    {
        $this->seedCore();
        $doc = $this->invoice(['doc_no' => 'INV-F36-77']);

        $url = route('m.show', ['fin', $doc->id]);
        $this->actingAs($this->owner)->get('/m/fin')
            ->assertOk()
            ->assertSee('class="lx-link" hx-boost="false" href="' . $url . '"', false);
    }
}
