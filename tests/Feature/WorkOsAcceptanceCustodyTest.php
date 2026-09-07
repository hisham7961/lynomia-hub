<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeCustodyMove;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * **سيناريو القبول §102 — رحلةُ العهدة المالية من الشحن إلى مصالحة الراتب**
 * (Work OS · الطور M · WP-M.4).
 *
 * يمتدّ سوابقَه الموسومة ولا يستنسخها (`WorkOsCustodyLedgerTest` الدفترُ الثابت،
 * `WorkOsCustodyPostingTest` الترحيلُ الواحد، `WorkOsCustodyLifecycleTest`
 * الأسطحُ والحرّاس) — وهذا الملفُّ يثبت **الرحلةَ الواحدة** لموظفٍ واحدٍ عبر
 * أسطح HTTP الحقيقية، بحيث يبقى الرصيدُ في كل خطوةٍ = SUM(sign×amount) على
 * **كل** الصفوف:
 *
 *   شحنٌ +500 مموَّلٌ من بنكٍ (رصيدُ البنك ينقص والقيدُ متوازنٌ «مرحّل»)
 *   ← مصروفٌ 120 باعتمادٍ وإيصالٍ مرفق ← القيدُ المُرحَّل مقفلٌ (لا تعديلَ ولا
 *   حذف) والحركةُ المُرحَّلة كذلك ← عكسُ المصروف: بلا تصعيدٍ يُرَدّ، وبه يُنشئ
 *   صفاً وقيداً معاكسَين، والعكسُ المزدوجُ محجوب ← خصمُ سلفةِ مسيّرِ رواتبَ
 *   يُصالِح `PayrollLine.advance` مرّةً واحدةً لا مرّتين.
 */
class WorkOsAcceptanceCustodyTest extends TestCase
{
    /** يبذر حساباتِ الدفتر ويُفعّل الترحيلَ الآليّ (نمطُ WorkOsCustodyLifecycleTest) */
    private function enableAutoJournal(): void
    {
        LedgerAccount::create(['code' => '1250', 'name' => 'عُهَد الموظفين', 'type' => 'أصول']);
        LedgerAccount::create(['code' => '1020', 'name' => 'البنك', 'type' => 'أصول']);
        LedgerAccount::create(['code' => '5200', 'name' => 'مصروفات', 'type' => 'مصروفات']);
        $this->hubSetting('finance.accounts',
            json_encode(['custody' => '1250', 'bank' => '1020', 'exp' => '5200'], JSON_UNESCAPED_UNICODE));
        $this->hubSetting('finance.auto_journal', '1');
    }

    /** الرصيدُ الحقيقيّ = مجموعُ المبالغ بإشاراتها على كل صفوف الموظف — لا اختصار */
    private function ledgerSum(Employee $e): float
    {
        return round((float) EmployeeCustodyMove::where('employee_id', $e->id)
            ->get()->sum(fn ($m) => ((int) $m->sign) * (float) $m->amount), 3);
    }

    /** يؤكّد أن الرصيدَ المشتقَّ يطابق مجموعَ الدفتر ويطابق المتوقَّع */
    private function assertBalance(Employee $e, float $expected, string $step): void
    {
        $this->assertSame($expected, $e->fresh()->custody_balance, "{$step}: الرصيدُ المشتقُّ انحرف");
        $this->assertSame($expected, $this->ledgerSum($e), "{$step}: الرصيدُ ليس SUM على كل الصفوف");
    }

    public function test_the_full_custody_journey_from_charge_to_payroll_reconciliation(): void
    {
        $this->seedCore();
        $this->enableAutoJournal();
        $co = Company::create(['name_ar' => 'شركةُ العهدة']);
        $emp = Employee::create(['name' => 'موظفُ القبول', 'status' => 'نشط', 'company_id' => $co->id]);
        $bank = BankAccount::create(['name' => 'بنكُ التشغيل', 'balance' => 10000, 'status' => 'نشط']);

        /* ── (١) شحنٌ +500 من البنك: الرصيدُ يُشتقّ والبنكُ يُصرَف والقيدُ «مرحّل» ── */

        $this->actingAs($this->owner)->post(route('custody.wallet.charge'),
            ['employee_id' => $emp->id, 'amount' => 500, 'bank_id' => $bank->id])
            ->assertSessionDoesntHaveErrors()->assertRedirect();

        $this->assertBalance($emp, 500.0, 'بعد الشحن');
        $this->assertSame(9500.0, (float) $bank->fresh()->balance, 'الشحنُ لم يُصرَف من البنك');

        $charge = EmployeeCustodyMove::where('kind', 'charge')->firstOrFail();
        $this->assertNotNull($charge->posted_at, 'الشحنُ لم يُرحَّل');
        $entry = JournalEntry::findOrFail($charge->entry_id);
        $this->assertSame('مرحّل', (string) $entry->state, 'قيدُ الشحن ليس «مرحّلاً»');
        $lines = JournalLine::where('entry_id', $entry->id)->orderBy('id')->get();
        $this->assertSame((float) $lines->sum('debit'), (float) $lines->sum('credit'),
            'قيدُ الشحن غيرُ موزون');

        /* ── (٢) مصروفٌ 120 باعتمادٍ وإيصال: بلا إيصالٍ يُرَدّ، وبه يُعتمَد ── */

        $this->actingAs($this->owner)->post(route('custody.wallet.expense'),
            ['employee_id' => $emp->id, 'amount' => 120])->assertStatus(422);
        $this->assertBalance($emp, 500.0, 'مصروفٌ بلا إيصالٍ مرّ');

        DB::table('attachments')->insert([
            'id' => $receiptId = (string) Str::uuid(), 'module' => 'hr', 'record_id' => $emp->id,
            'disk' => 'local', 'path' => 'r/receipt.pdf', 'original_name' => 'إيصال_مطعم.pdf',
            'mime' => 'application/pdf', 'size' => 12, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->owner)->post(route('custody.wallet.expense'),
            ['employee_id' => $emp->id, 'amount' => 120, 'receipt_id' => $receiptId])
            ->assertSessionDoesntHaveErrors()->assertRedirect();

        $this->assertBalance($emp, 380.0, 'بعد المصروف المعتمد');
        $expense = EmployeeCustodyMove::where('kind', 'expense')->firstOrFail();
        $this->assertSame('approved', (string) $expense->approval_state);
        $this->assertSame($receiptId, (string) $expense->receipt_id, 'المصروفُ بلا إيصاله');

        /* ── (٣) القفلُ المزدوج: القيدُ المُرحَّل والحركةُ المُرحَّلة لا يُحرَّران ── */

        foreach ([
            'قيدٌ مُرحَّلٌ عُدِّل' => fn () => $entry->fresh()->update(['description' => 'تلاعب']),
            'قيدٌ مُرحَّلٌ حُذف' => fn () => $entry->fresh()->delete(),
            'حركةٌ مُرحَّلةٌ عُدِّلت' => fn () => $charge->fresh()->update(['amount' => 999]),
            'حركةٌ مُرحَّلةٌ حُذفت' => fn () => $charge->fresh()->delete(),
        ] as $why => $attempt) {
            try {
                $attempt();
                $this->fail($why . ' — الدفترُ الثابتُ انثقب');
            } catch (ValidationException) {
                // الرفضُ هو المطلوب
            }
        }
        $this->assertSame('500.000', (string) $charge->fresh()->amount, 'مبلغُ الحركة تبدّل رغم الرفض');
        $this->assertNotNull(JournalEntry::find($entry->id), 'القيدُ اختفى رغم الرفض');
        $this->assertBalance($emp, 380.0, 'بعد محاولات التحرير الصامت');

        /* ── (٤) العكس: بلا تصعيدٍ يُرَدّ، وبه صفٌّ وقيدٌ معاكسان — والمزدوجُ محجوب ── */

        $this->actingAs($this->owner)->post(route('custody.wallet.reverse', $expense->id),
            ['reason' => 'بلا هوية'])->assertRedirectContains('/stepup');
        $this->assertSame(0, EmployeeCustodyMove::where('reverses_id', $expense->id)->count(),
            'عكسٌ مرّ بلا تصعيد هوية');

        $this->actingAs($this->owner)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/'])
            ->assertRedirect();
        $this->actingAs($this->owner)->post(route('custody.wallet.reverse', $expense->id),
            ['reason' => 'إيصالٌ مرفوضٌ من المدقّق'])->assertRedirect();

        $rev = EmployeeCustodyMove::where('reverses_id', $expense->id)->firstOrFail();
        $this->assertSame(1, (int) $rev->sign, 'عكسُ المصروف (−) يجب أن يكون موجبَ الإشارة');
        $this->assertSame('120.000', (string) $rev->amount);
        $this->assertBalance($emp, 500.0, 'بعد عكس المصروف');

        // قيدُ العكس مرآةُ الأصل على حساب العهدة (1250)
        $custodyAcc = LedgerAccount::where('code', '1250')->value('id');
        $origLine = JournalLine::where('entry_id', $expense->entry_id)->where('acc_id', $custodyAcc)->firstOrFail();
        $revLine = JournalLine::where('entry_id', $rev->entry_id)->where('acc_id', $custodyAcc)->firstOrFail();
        $this->assertSame((float) $origLine->credit, (float) $revLine->debit, 'قيدُ العكس ليس مرآةً');

        // العكسُ المزدوج: الطلبُ نفسُه ثانيةً لا يُنشئ عكساً ثانياً ولا يحرّك الرصيد
        $this->actingAs($this->owner)->post(route('custody.wallet.reverse', $expense->id),
            ['reason' => 'مرّةٌ ثانية'])->assertRedirect();
        $this->assertSame(1, EmployeeCustodyMove::where('reverses_id', $expense->id)->count(),
            'عُكست الحركةُ مرّتين — رصيدٌ من عدم');
        $this->assertBalance($emp, 500.0, 'بعد محاولة العكس المزدوج');

        /* ── (٥) خصمُ سلفةِ المسيّر يُصالِح PayrollLine.advance مرّةً واحدة ── */

        $run = PayrollRun::create(['name' => 'مسيّرُ الشهر', 'month' => now()->format('Y-m'),
            'status' => 'مسودة', 'company_id' => $co->id]);
        $line = PayrollLine::create(['run_id' => $run->id, 'emp_id' => $emp->id,
            'base' => 900, 'advance' => 200, 'net' => 700]);

        $this->actingAs($this->owner)->post(route('custody.wallet.deduction'), ['line_id' => $line->id])
            ->assertSessionDoesntHaveErrors()->assertRedirect();
        $this->assertBalance($emp, 300.0, 'بعد خصم سلفة الراتب (500−200)');

        $ded = EmployeeCustodyMove::where('kind', 'deduction')->firstOrFail();
        $this->assertSame('payroll_lines', (string) $ded->source_module);
        $this->assertSame((string) $line->id, (string) $ded->source_id,
            'الخصمُ لا يشير إلى سطر المسيّر — لا مصالحةَ تُتتبَّع');

        // الخصمُ الثاني لنفس السطر: idempotent — لا حركةَ ثانية ولا رصيدَ يُنقص مرّتين
        $this->actingAs($this->owner)->post(route('custody.wallet.deduction'), ['line_id' => $line->id])
            ->assertRedirect();
        $this->assertSame(1, EmployeeCustodyMove::where('kind', 'deduction')->count(),
            'سلفةُ المسيّر خُصمت مرّتين');
        $this->assertBalance($emp, 300.0, 'بعد محاولة الخصم المزدوج');

        /* ── (٦) الختام: الدفترُ كلُّه يجمع الرصيدَ — عدّاً ومبلغاً ── */

        $this->assertSame(4, EmployeeCustodyMove::where('employee_id', $emp->id)->count(),
            'شحنٌ + مصروفٌ + عكسٌ + خصمٌ = أربعُ حركاتٍ لا غير');
        $this->assertSame(4, JournalEntry::count(), 'قيدٌ لكل حركةٍ مُرحَّلة — لا أكثر ولا أقل');
    }
}
