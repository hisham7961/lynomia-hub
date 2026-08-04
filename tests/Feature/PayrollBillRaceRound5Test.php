<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\PayrollController;
use App\Http\Controllers\Web\PurchaseController;
use App\Models\Employee;
use App\Models\FinDocument;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\PayrollRun;
use App\Models\Purchase;
use App\Models\Supplier;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * الموجة الخامسة — دفعة v2.280.0: ذرّية المسارات المالية.
 *
 * اعتمادُ مسيّر الرواتب (PayrollController::approve) وإنشاءُ فاتورة المورد
 * (PurchaseController::toBill) كانا **يفحصان الحالة ثم يكتبان قيداً/فاتورة بلا
 * DB::transaction ولا lockForUpdate** — بخلاف أشقّائهما FinController::pay
 * و PurchaseController::receive المقفولين. فمعتمِدان متزامنان (نقر مزدوج/تبويبان/
 * معالجان) يقرأ كلٌّ الصفَّ مستقلاً وهو 'مسودة'/'مستلم' فيمرّان معاً: قيدُ رواتبٍ
 * مضاعفٌ (مصروفٌ يتكرّر في الدفتر) أو فاتورةُ موردٍ مكرّرة (التزامٌ مضاعف).
 * ولا حاجزَ قاعدةٍ (doc_no بلا unique).
 *
 * تُحاكى المتزامنة حتميّاً بنسختين حُمِّلتا قبل أن ينفّذ أيٌّ منهما: الحارس السليم
 * يعيد القراءة من صفٍّ مقفول داخل المعاملة فترفض النسخةُ القديمة أو تُوجَّه للقائمة.
 */
class PayrollBillRaceRound5Test extends TestCase
{
    protected function invoke(object $ctrl, string $method, ...$args)
    {
        $ref = new \ReflectionMethod($ctrl, $method);
        $ref->setAccessible(true);

        return $ref->invoke($ctrl, ...$args);
    }

    /** إعدادٌ عبر hub:set كما في الإنتاج (يخزّن عدداً) */
    protected function enableAutoJournal(): void
    {
        LedgerAccount::create(['code' => '5200', 'name' => 'مصروف رواتب', 'type' => 'مصروف']);
        LedgerAccount::create(['code' => '1020', 'name' => 'البنك', 'type' => 'أصل']);
        $this->artisan('hub:set', ['key' => 'finance.accounts',
            'value' => json_encode(['bank' => '1020', 'exp' => '5200'], JSON_UNESCAPED_UNICODE)]);
        $this->artisan('hub:set', ['key' => 'finance.auto_journal', 'value' => '1']);
    }

    /** (١) اعتماد المسيّر: نسختان قديمتان لا تُرحّلان قيدين */
    public function test_two_stale_payroll_approvals_post_one_journal(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $this->enableAutoJournal();

        Employee::create(['name' => 'موظف', 'user_id' => $this->employee->id,
            'salary' => 1000, 'allow' => 200, 'status' => 'على رأس العمل']);
        $run = PayrollRun::create(['name' => 'مسيّر يوليو', 'month' => '2026-07', 'status' => 'مسودة']);
        $this->post("/payroll/{$run->id}/act", ['do' => 'generate']);

        // معتمِدان حمّلا المسيّر معاً وهو 'مسودة' قبل أن يعتمده أيٌّ منهما
        $a = PayrollRun::find($run->id);
        $b = PayrollRun::find($run->id);
        $ctrl = new PayrollController();

        $this->invoke($ctrl, 'approve', $a);   // الأول يعتمد ويُرحّل القيد

        try {
            $this->invoke($ctrl, 'approve', $b);   // النسخة القديمة يجب أن تُرفض
            $this->fail('اعتمادٌ مزدوج مرّ — قيد رواتب مضاعف في الدفتر');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(1, JournalEntry::where('reference', $run->name)->count(),
            'قيد الرواتب رُحّل مرتين — مصروفٌ مضاعف في الدفتر');
        $this->assertSame('معتمد', $run->fresh()->status);
    }

    /** (٢) فاتورة المورد: نسختان قديمتان لا تُنشئان فاتورتين */
    public function test_two_stale_tobill_create_one_finance_doc(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $sup = Supplier::create(['name' => 'مورد']);
        $p = Purchase::create(['doc_no' => 'PO-RACE', 'title' => 'أمر', 'status' => 'مستلم',
            'amount' => 500, 'supplier_id' => $sup->id, 'pay_state' => 'غير مدفوع']);

        // معالجان حمّلا الأمر معاً وهو 'مستلم' بلا bill_id قبل أن يُفوتِر أيٌّ منهما
        $a = Purchase::find($p->id);
        $b = Purchase::find($p->id);
        $ctrl = new PurchaseController();

        $this->invoke($ctrl, 'toBill', $a);   // الأول ينشئ فاتورة المورد
        $this->invoke($ctrl, 'toBill', $b);   // النسخة القديمة تُوجَّه للقائمة لا تنشئ ثانية

        $this->assertSame(1, FinDocument::where('kind', 'فاتورة مشتريات')
            ->where('description', 'like', '%' . $p->doc_no . '%')->count(),
            'فاتورة المورد أُنشئت مرتين — التزامٌ مضاعف في المالية والتقارير');
    }
}
