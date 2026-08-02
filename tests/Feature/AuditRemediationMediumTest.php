<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\FinDocument;
use App\Models\Task;
use Tests\TestCase;

/** دفعة المتوسطات من فحص العشرين وكيلاً — سلوكياتٌ خاطئة أُصلحت بإثبات */
class AuditRemediationMediumTest extends TestCase
{
    /** «فارغ» على رقمٍ لا يبتلع الصفر: 0 قيمةٌ لا غياب */
    public function test_empty_filter_on_numeric_column_keeps_zero_rows(): void
    {
        $this->seedCore();
        Task::create(['title' => 'بلا تقدير', 'status' => 'جديدة', 'est_h' => null]);
        Task::create(['title' => 'تقديرها صفر', 'status' => 'جديدة', 'est_h' => 0]);
        Task::create(['title' => 'مقدّرة', 'status' => 'جديدة', 'est_h' => 5]);

        $html = $this->actingAs($this->owner)
            ->get('/m/tasks?fl[0][f]=estH&fl[0][o]=empty')->assertOk()->getContent();

        $this->assertStringContainsString('بلا تقدير', $html);
        $this->assertStringNotContainsString('تقديرها صفر', $html,
            'الصفرُ صُنّف فارغاً — مقارنةُ العمود الرقمي بـ\'\' ');

        $html2 = $this->actingAs($this->owner)
            ->get('/m/tasks?fl[0][f]=estH&fl[0][o]=nempty')->assertOk()->getContent();
        $this->assertStringContainsString('تقديرها صفر', $html2, '«غير فارغ» أقصى الصفر');
        $this->assertStringNotContainsString('بلا تقدير', $html2);
    }

    /** عملة البنك تخالف عملة المستند: يُرفض لا يُخلط 1:1 */
    public function test_payment_refuses_currency_mismatch(): void
    {
        $this->seedCore();
        $bank = BankAccount::create(['name' => 'دينار', 'balance' => 1000, 'currency' => 'د.ك', 'status' => 'نشط']);
        $doc = FinDocument::create(['doc_no' => 'USD-1', 'kind' => 'فاتورة مبيعات',
            'total' => 100, 'state' => 'مرسلة', 'currency' => 'دولار']);

        $this->actingAs($this->owner)->post("/fin/{$doc->id}/act", [
            'do' => 'pay', 'amount' => 100, 'bankId' => $bank->id,
        ])->assertStatus(422);

        $this->assertSame(1000.0, (float) $bank->fresh()->balance, 'خُلطت العملتان 1:1');

        // وبنكٌ بعملة المستند يمرّ
        $usd = BankAccount::create(['name' => 'دولاري', 'balance' => 0, 'currency' => 'دولار', 'status' => 'نشط']);
        $this->actingAs($this->owner)->post("/fin/{$doc->id}/act", [
            'do' => 'pay', 'amount' => 100, 'bankId' => $usd->id,
        ])->assertRedirect();
        $this->assertSame(100.0, (float) $usd->fresh()->balance);
    }

    /** الدفعة معاملةٌ واحدة: القيد الآلي لا يبقى إن فشل ما بعده — ولا دفعَ فوق السداد */
    public function test_pay_is_transactional_and_never_overpays(): void
    {
        $this->seedCore();
        $doc = FinDocument::create(['doc_no' => 'TX-1', 'kind' => 'فاتورة مبيعات',
            'total' => 100, 'state' => 'مرسلة', 'currency' => 'د.ك']);

        // دفعتان متتاليتان: الثانية تُقصّ للمتبقي لا تتجاوزه
        $this->actingAs($this->owner)->post("/fin/{$doc->id}/act", ['do' => 'pay', 'amount' => 60]);
        $this->actingAs($this->owner)->post("/fin/{$doc->id}/act", ['do' => 'pay', 'amount' => 90]);

        $fresh = $doc->fresh();
        $this->assertSame(100.0, (float) $fresh->paid, 'تجاوز السداد المتبقي');
        $this->assertSame('مدفوعة', (string) $fresh->state);

        // والثالثة على مستندٍ مسدَّد تُرفض بلا أي أثر
        $this->actingAs($this->owner)->post("/fin/{$doc->id}/act", ['do' => 'pay', 'amount' => 10])
            ->assertStatus(422);
        $this->assertSame(100.0, (float) $doc->fresh()->paid);
    }

    /** «بلا تشفير» للبريد تصمد: كانت تُخزَّن فراغاً فيبتلعها setting ويعود TLS */
    public function test_mail_none_encryption_survives_save_and_apply(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/admin/integrations/messaging/mail', [
            'host' => 'relay.internal', 'port' => 25, 'encryption' => 'none',
            'username' => 'x@y.z', 'password' => 'p', 'from_address' => 'x@y.z',
        ]);

        $this->assertSame('none', setting('mail.encryption'), '«none» ابتُلعت فراغاً');

        \App\Support\MailSettings::apply();
        $this->assertNull(config('mail.mailers.smtp.encryption'),
            'بلا تشفير عادت TLS قسراً — خادم المنفذ 25 سيفشل');

        // والشاشة تعرض «بلا تشفير» محدداً لا TLS
        $html = $this->actingAs($this->owner)->get('/admin/integrations/messaging')->getContent();
        $this->assertMatchesRegularExpression('/value="none"\s+selected/', $html);
    }

    /** ترقيم سجل التدقيق والـAPI بفاصل id — لا صفوف تتكرر أو تسقط عبر الصفحات */
    public function test_paginators_carry_id_tiebreakers_in_source(): void
    {
        $audit = (string) file_get_contents(app_path('Http/Controllers/Web/AuditController.php'));
        $this->assertStringContainsString("orderByDesc('audits.created_at')->orderByDesc('audits.id')", $audit);

        $api = (string) file_get_contents(app_path('Http/Controllers/Api/V1Controller.php'));
        $this->assertStringContainsString("orderByDesc('created_at')->orderByDesc('id')", $api);
    }
}
