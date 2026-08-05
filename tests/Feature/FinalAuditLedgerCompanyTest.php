<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Employee;
use App\Models\FinDocument;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\PayrollRun;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * الفحص النهائيّ (بند ٨) — دفعة v2.301: ثلاثة عيوبٍ متوسطة، كلٌّ فاشلٌ أولاً:
 *
 *  (#6)  autoJournal يحلّ حساب الدفتر بالرمز `code` وحده بلا تحصيرٍ بالشركة —
 *        و`ledger_accounts.code` غير فريدٍ و`company_id` قد يتكرّر عبر الشركات،
 *        فسطرُ قيدِ شركةٍ يلتصق بحساب شركةٍ أخرى. الإصلاح: تفضيلُ حساب شركة
 *        المستند/المسيّر، فحسابٌ عامٌّ (company_id فارغ) احتياطاً، وإلا فلا قيد
 *        أعرج يلوّث دفتر شركةٍ لا تخصّه. (FinController وPayrollController معاً.)
 *
 *  (#15) توليدُ الرواتب بلا company_id يجمع موظفي شركاتٍ بعملاتٍ مختلفة في
 *        إجمالٍ وقيدٍ واحد — كذبةُ رقمٍ واحد لعملاتٍ غير متجانسة. الإصلاح: رفضُ
 *        التوليد حين تتباين عملاتُ شركات الموظفين وشركةُ التشغيلة غير محدَّدة.
 *
 *  (#18) شعار الإعدادات يُقبل SVG (قاعدة `image` في Laravel 11 تشمله) ويُخزَّن
 *        على القرص العام ويُخدَم مباشرةً بلا Content-Disposition — XSS مخزَّن.
 *        الإصلاح: حصرُ الرفع في الصيغ النقطية (jpg/png/webp/gif) فقط.
 */
class FinalAuditLedgerCompanyTest extends TestCase
{
    /** يُفعّل الترحيل الآلي عبر hub:set (يخزّن عدداً كما في الإنتاج) */
    protected function enableAutoJournal(array $map): void
    {
        $this->artisan('hub:set', ['key' => 'finance.accounts',
            'value' => json_encode($map, JSON_UNESCAPED_UNICODE)]);
        $this->artisan('hub:set', ['key' => 'finance.auto_journal', 'value' => '1']);
    }

    /**
     * (#6 رواتب) قيدُ رواتب شركةٍ لا يلتصق بحساب دفترٍ يخصّ شركةً أخرى.
     * حسابا الدفتر (5200/1020) للشركة أ وحدها، والمسيّر للشركة ب — بلا التحصير
     * يختار المحرّك حساب أ للرمز (uuid فترتيب id ليس ترتيب الإنشاء)، فيلتصق
     * مصروفُ رواتب ب بدفتر أ. بعد الإصلاح: لا حساب لـب فلا قيد أعرج.
     */
    public function test_payroll_journal_never_binds_to_a_foreign_company_account(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ', 'currency' => 'د.ك']);
        $b = Company::create(['name_ar' => 'شركة ب', 'currency' => 'د.ك']);

        // الحسابان بشركة أ فقط — لا نظير لهما بشركة ب
        LedgerAccount::create(['code' => '5200', 'name' => 'مصروف رواتب أ', 'type' => 'مصروف', 'company_id' => $a->id]);
        LedgerAccount::create(['code' => '1020', 'name' => 'بنك أ', 'type' => 'أصل', 'company_id' => $a->id]);
        $this->enableAutoJournal(['bank' => '1020', 'exp' => '5200']);

        Employee::create(['name' => 'موظف ب', 'salary' => 1000, 'allow' => 200,
            'status' => 'على رأس العمل', 'company_id' => $b->id]);
        $run = PayrollRun::create(['name' => 'مسيّر ب', 'month' => '2026-07',
            'status' => 'مسودة', 'company_id' => $b->id]);

        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", ['do' => 'generate']);
        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", ['do' => 'approve']);

        // لا سطرَ قيدٍ يشير إلى حسابٍ يخصّ شركةً غير شركة المسيّر
        $foreign = JournalLine::query()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.acc_id')
            ->whereNotNull('ledger_accounts.company_id')
            ->where('ledger_accounts.company_id', '!=', $b->id)
            ->count();
        $this->assertSame(0, $foreign,
            'قيدُ رواتب الشركة ب التصق بحساب دفترٍ يخصّ شركةً أخرى');
    }

    /**
     * (#6 رواتب) عند وجود حسابٍ خاصٍّ بالشركة وآخرَ عامٍّ لنفس الرمز — يُفضَّل
     * الخاصّ. حتميٌّ بعد الإصلاح (`company_id IS NULL` يُرتَّب أخيراً).
     */
    public function test_payroll_journal_prefers_company_specific_over_global_account(): void
    {
        $this->seedCore();
        $b = Company::create(['name_ar' => 'شركة ب', 'currency' => 'د.ك']);

        // عامٌّ (بلا شركة) وخاصٌّ بـب لنفس الرمز — يجب أن يُختار الخاصّ
        LedgerAccount::create(['code' => '5200', 'name' => 'مصروف عامّ', 'type' => 'مصروف', 'company_id' => null]);
        LedgerAccount::create(['code' => '1020', 'name' => 'بنك عامّ', 'type' => 'أصل', 'company_id' => null]);
        $expB = LedgerAccount::create(['code' => '5200', 'name' => 'مصروف ب', 'type' => 'مصروف', 'company_id' => $b->id]);
        $cashB = LedgerAccount::create(['code' => '1020', 'name' => 'بنك ب', 'type' => 'أصل', 'company_id' => $b->id]);
        $this->enableAutoJournal(['bank' => '1020', 'exp' => '5200']);

        Employee::create(['name' => 'موظف ب', 'salary' => 800, 'allow' => 0,
            'status' => 'على رأس العمل', 'company_id' => $b->id]);
        $run = PayrollRun::create(['name' => 'مسيّر ب٢', 'month' => '2026-08',
            'status' => 'مسودة', 'company_id' => $b->id]);

        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", ['do' => 'generate']);
        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", ['do' => 'approve']);

        $entry = JournalEntry::where('reference', $run->name)->first();
        $this->assertNotNull($entry, 'لم يُرحّل القيد رغم توفّر حسابَي الشركة');
        $accs = JournalLine::where('entry_id', $entry->id)->pluck('acc_id')->all();
        $this->assertContains($expB->id, $accs, 'لم يُفضَّل حسابُ المصروف الخاصّ بالشركة على العامّ');
        $this->assertContains($cashB->id, $accs, 'لم يُفضَّل حسابُ البنك الخاصّ بالشركة على العامّ');
    }

    /**
     * (#6 مالية) دفعةُ مستندٍ في شركةٍ لا تلتصق بحساب دفترٍ يخصّ شركةً أخرى.
     */
    public function test_fin_payment_journal_never_binds_to_a_foreign_company_account(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ', 'currency' => 'د.ك']);
        $b = Company::create(['name_ar' => 'شركة ب', 'currency' => 'د.ك']);

        // خريطة المبيعات/البنك — حساباهما بشركة أ وحدها
        LedgerAccount::create(['code' => '4000', 'name' => 'مبيعات أ', 'type' => 'إيراد', 'company_id' => $a->id]);
        LedgerAccount::create(['code' => '1000', 'name' => 'بنك أ', 'type' => 'أصل', 'company_id' => $a->id]);
        $this->enableAutoJournal(['bank' => '1000', 'sales' => '4000']);

        $bank = BankAccount::create(['name' => 'بنك ب', 'balance' => 0, 'company_id' => $b->id, 'status' => 'نشط']);
        $doc = FinDocument::create(['doc_no' => 'INV-B', 'kind' => 'فاتورة مبيعات',
            'total' => 300, 'state' => 'مرسلة', 'company_id' => $b->id, 'bank_id' => $bank->id]);

        $this->actingAs($this->owner)->post("/fin/{$doc->id}/act",
            ['do' => 'pay', 'amount' => 300, 'bankId' => $bank->id]);

        $foreign = JournalLine::query()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.acc_id')
            ->whereNotNull('ledger_accounts.company_id')
            ->where('ledger_accounts.company_id', '!=', $b->id)
            ->count();
        $this->assertSame(0, $foreign,
            'قيدُ دفعةِ مستندِ الشركة ب التصق بحساب دفترٍ يخصّ شركةً أخرى');
    }

    /**
     * (#15) توليدُ رواتبٍ بلا شركةٍ يجمع عملاتٍ مختلفة → يُرفَض.
     * موظفٌ في شركة بالدينار وآخرُ في شركة بالدولار، والتشغيلة بلا company_id.
     */
    public function test_payroll_generate_rejects_mixed_currencies_when_company_blank(): void
    {
        $this->seedCore();
        $kwd = Company::create(['name_ar' => 'شركة الدينار', 'currency' => 'د.ك']);
        $usd = Company::create(['name_ar' => 'شركة الدولار', 'currency' => '$']);

        Employee::create(['name' => 'موظف دينار', 'salary' => 1000, 'allow' => 0,
            'status' => 'على رأس العمل', 'company_id' => $kwd->id]);
        Employee::create(['name' => 'موظف دولار', 'salary' => 900, 'allow' => 0,
            'status' => 'على رأس العمل', 'company_id' => $usd->id]);

        // تشغيلةٌ بلا شركة — المالك يرى الشركتين، فبلا حارسٍ تُجمع عملتاهما
        $run = PayrollRun::create(['name' => 'مسيّر مختلط', 'month' => '2026-07', 'status' => 'مسودة']);

        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", ['do' => 'generate'])
            ->assertStatus(422);

        $this->assertSame(0, \App\Models\PayrollLine::where('run_id', $run->id)->count(),
            'وُلّدت سطور رواتبٍ بعملاتٍ مختلطة رغم غياب شركة التشغيلة');
    }

    /** (#15) تشغيلةٌ بشركةٍ واحدة تعمل كما هي — الحارس لا يعيق الحالة السليمة */
    public function test_payroll_generate_allows_single_company_run(): void
    {
        $this->seedCore();
        $kwd = Company::create(['name_ar' => 'شركة الدينار', 'currency' => 'د.ك']);
        Employee::create(['name' => 'موظف', 'salary' => 700, 'allow' => 0,
            'status' => 'على رأس العمل', 'company_id' => $kwd->id]);
        $run = PayrollRun::create(['name' => 'مسيّر موحّد', 'month' => '2026-07',
            'status' => 'مسودة', 'company_id' => $kwd->id]);

        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", ['do' => 'generate'])
            ->assertRedirect();
        $this->assertSame(1, \App\Models\PayrollLine::where('run_id', $run->id)->count());
    }

    /** (#18) شعارُ SVG يُرفَض — لا يُخزَّن على القرص العام ولا يُسجَّل مفتاحه */
    public function test_settings_logo_rejects_svg_upload(): void
    {
        $this->seedCore();
        Storage::fake('public');

        $svg = UploadedFile::fake()->createWithContent('logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');

        $this->actingAs($this->owner)->post('/admin/settings', ['app_logo' => $svg])
            ->assertSessionHasErrors('app_logo');

        $this->assertNull(Setting::where('key', 'app.logo')->first(),
            'شعارُ SVG سُجّل مفتاحُه رغم خطر XSS المخزَّن');
        $this->assertSame([], Storage::disk('public')->allFiles('hub/branding'),
            'ملفُّ SVG خُزّن على القرص العام ويُخدَم مباشرةً');
    }

    /** (#18) الشعارُ النقطيّ (PNG) يُقبل كما هو — الحصرُ لا يمنع الشعار المشروع */
    public function test_settings_logo_accepts_raster_png(): void
    {
        $this->seedCore();
        Storage::fake('public');

        $png = UploadedFile::fake()->image('logo.png', 64, 64);

        $this->actingAs($this->owner)->post('/admin/settings', ['app_logo' => $png])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(Setting::where('key', 'app.logo')->first(),
            'شعارٌ نقطيٌّ مشروعٌ رُفض');
    }
}
