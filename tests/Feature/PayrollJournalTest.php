<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\FinDocument;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * تكامل قيود المال — الموجة الثالثة:
 * (١) قيد الرواتب لا يُرحَّل حين يُفعَّل الإعداد بـhub:set (يخزّن عدداً) —
 *     PayrollController نسي (string) الذي وضعه FinController؛
 * (٢) بحث حساب الدفتر بلا orderBy(id) على code غير الفريد — قرعة؛
 * (٣) autoJournal بلا معاملة يترك قيداً مرحّلاً بسطرٍ واحد لا يُصحَّح؛
 * (٤) paid يُحرَّر يدويّاً متجاوزاً pay() (فيض/سالب/فكّ ربط بالبنك).
 */
class PayrollJournalTest extends TestCase
{
    /** إعدادٌ عبر hub:set لا hubSetting — كي يُخزَّن عدداً كما في الإنتاج */
    protected function enableAutoJournal(): void
    {
        LedgerAccount::create(['code' => '5200', 'name' => 'مصروف رواتب', 'type' => 'مصروف']);
        LedgerAccount::create(['code' => '1020', 'name' => 'البنك', 'type' => 'أصل']);
        $this->artisan('hub:set', ['key' => 'finance.accounts',
            'value' => json_encode(['bank' => '1020', 'exp' => '5200'], JSON_UNESCAPED_UNICODE)]);
        $this->artisan('hub:set', ['key' => 'finance.auto_journal', 'value' => '1']);
    }

    protected function seedRun(): PayrollRun
    {
        Employee::create(['name' => 'موظف', 'user_id' => $this->employee->id,
            'salary' => 1000, 'allow' => 200, 'status' => 'على رأس العمل']);
        $run = PayrollRun::create(['name' => 'مسيّر يوليو', 'month' => '2026-07', 'status' => 'مسودة']);
        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", ['do' => 'generate']);

        return $run->fresh();
    }

    /** (١) القيد يُرحَّل حين يُفعَّل الإعداد بالطريقة الموثّقة */
    public function test_payroll_journal_posts_when_enabled_via_hub_set(): void
    {
        $this->seedCore();
        $this->enableAutoJournal();
        $run = $this->seedRun();

        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", ['do' => 'approve']);

        $this->assertSame(1, JournalEntry::where('reference', $run->name)->count(),
            'تفعيل auto_journal بـhub:set لم يُرحّل قيد الرواتب — مصروف الرواتب لا يبلغ الدفتر أبداً');
        $entry = JournalEntry::where('reference', $run->name)->first();
        $lines = JournalLine::where('entry_id', $entry->id)->get();
        $this->assertSame(1200.0, (float) $lines->sum('debit'));
        $this->assertSame((float) $lines->sum('debit'), (float) $lines->sum('credit'),
            'القيد غير موزون');
    }

    /** (٢) حسابان بنفس code يُنتجان قيداً حتميّاً لا قرعة */
    public function test_ledger_account_lookup_is_deterministic(): void
    {
        $this->seedCore();
        // رمزٌ مكرّر — بلا orderBy تختار القاعدة أحدهما بالقرعة
        LedgerAccount::create(['code' => '5200', 'name' => 'مصروف رواتب أ', 'type' => 'مصروف']);
        $dupe = LedgerAccount::create(['code' => '5200', 'name' => 'مصروف رواتب ب', 'type' => 'مصروف']);
        LedgerAccount::create(['code' => '1020', 'name' => 'البنك', 'type' => 'أصل']);
        $this->artisan('hub:set', ['key' => 'finance.accounts',
            'value' => json_encode(['bank' => '1020', 'exp' => '5200'], JSON_UNESCAPED_UNICODE)]);
        $this->artisan('hub:set', ['key' => 'finance.auto_journal', 'value' => '1']);

        $run = $this->seedRun();
        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", ['do' => 'approve']);

        // الحتمية لا الأسبقية: المعرّفات uuid فترتيبها ليس ترتيب الإنشاء، لكن
        // orderBy(id) يُنتج النتيجة **نفسها على المحرّكين** — وهذا عين ما يمنع القرعة
        $first = LedgerAccount::where('code', '5200')->orderBy('id')->value('id');
        $line = JournalLine::where('memo', 'مصروف رواتب')->first();
        $this->assertSame($first, $line->acc_id,
            'قيد الرواتب توزّع على حسابٍ بالقرعة — code غير فريد بلا orderBy(id)');
    }

    /** (٣) فشلُ السطر الثاني لا يترك قيداً مرحّلاً معطوباً — حارس مصدر */
    public function test_autojournal_is_transaction_wrapped(): void
    {
        foreach (['FinController', 'PayrollController'] as $c) {
            $src = file_get_contents(app_path("Http/Controllers/Web/{$c}.php"));
            // موضع autoJournal يلفّ إنشاء القيد وسطريه في معاملة
            $this->assertMatchesRegularExpression('/autoJournal.*?DB::transaction/su', $src,
                "{$c}::autoJournal بلا معاملة — فشلُ السطر الثاني يترك قيداً مرحّلاً بسطرٍ واحد لا يُصحَّح");
        }
    }

    /** (٤) paid لا يُحرَّر يدويّاً — يُحرَّك عبر pay() وحده */
    public function test_paid_cannot_be_hand_edited_past_bounds(): void
    {
        $this->seedCore();
        $doc = FinDocument::create(['doc_no' => 'INV-P', 'kind' => 'فاتورة مبيعات',
            'total' => 100, 'paid' => 0, 'state' => 'مرسلة']);

        // فيضٌ وسالبٌ عبر النموذج العام — كلاهما يتجاوز سقف pay() وحركة البنك
        $this->actingAs($this->owner)->put("/m/fin/{$doc->id}",
            ['docNo' => 'INV-P', 'kind' => 'فاتورة مبيعات', 'total' => 100, 'paid' => 999999, 'state' => 'مرسلة']);
        $this->assertSame(0.0, (float) $doc->fresh()->paid,
            'حقل المدفوع حُرّر يدويّاً إلى 999999 متجاوزاً pay() وحركة البنك والقيد');

        // والحقل يُعرض «قراءة فقط» في النموذج
        $html = $this->actingAs($this->owner)->get("/m/fin/{$doc->id}/edit")->getContent();
        $this->assertMatchesRegularExpression('/المدفوع.*?قراءة فقط/su', $html);
    }
}
