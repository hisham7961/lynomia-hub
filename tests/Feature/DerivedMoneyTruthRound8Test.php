<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Purchase;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الموجة الثامنة (الدفعة ١) — **الرقمُ المشتقّ لا يُكتب باليد ولا يُحسب مرّتين.**
 *
 *  ١) **ربحيةُ المشروع تحتسب أمر الشراء مرّتين** (`helpers.php:1764`): التكلفةُ
 *     الخارجية = مجموعُ `purchases` + مجموعُ مستندات المالية من نوع «مصروف».
 *     لكن زرَّ «إنشاء فاتورة المورد» (`PurchaseController::toBill`) يولّد من أمر
 *     الشراء مستندَ مالية نوعُه «فاتورة مشتريات» — وهو أحدُ أنواع المصروف في
 *     `config('hub.fin.expense')`. فبعد الفوترة يُعدّ المبلغُ مرّتين: مرةً من
 *     صفّ الشراء نفسِه، ومرةً من الفاتورة المولَّدة منه. كلُّ رقمِ تكلفةٍ وهامشٍ
 *     وصحّةٍ يقرؤه المالك على `/costs` **خاطئٌ بنقرةِ زرٍّ مشحونٍ في المنتج**.
 *     والحالاتُ الميتة (ملغى/مرتجع) تُحمَّل أيضاً وهي ليست تكلفة.
 *
 *  ٢) **«إجمالي المسيّر» حقلٌ حرّ يُحرَّر من النموذج العام** (`PayrollController:220`):
 *     `payroll.total` بلا `locked`، فمستخدمُ `payroll.e` يكتب أيَّ رقمٍ عبر
 *     `PUT /m/payroll/{id}` متجاوزاً مجموعَ السطور. وقيدُ اليومية المُرحَّل —
 *     **المقفولُ أبداً بحارس `JournalEntry::booted`** — يبني مدينَه على `total`
 *     لا على مجموع `net`، فيدخل الدفترَ رقمٌ مختلَقٌ لا يُصحَّح بعد الترحيل.
 */
class DerivedMoneyTruthRound8Test extends TestCase
{
    /* ── ١) الشراء المُفوتَر يُعدّ مرّةً واحدة ── */

    public function test_a_billed_purchase_is_not_double_counted_in_project_cost(): void
    {
        $this->seedCore();

        $p = Project::create(['name' => 'مشروعٌ للربحية', 'status' => 'نشط',
            'budget' => 6000, 'currency' => 'د.ك', 'start_date' => now()->subMonths(2)->toDateString()]);

        $pur = Purchase::create(['doc_no' => 'PO-1', 'status' => 'مستلم',
            'amount' => 5000, 'currency' => 'د.ك', 'project_id' => $p->id,
            'pay_state' => 'غير مدفوع', 'date' => now()->toDateString()]);

        // قبل الفوترة: التكلفةُ الخارجية = قيمةُ الشراء وحدها
        $before = hub_project_pl($p->id, true);
        $this->assertSame(5000.0, $before['cost']['external'],
            'التكلفةُ الخارجية قبل الفوترة يجب أن تساوي قيمةَ أمر الشراء');
        $this->assertSame(-1000.0, $before['over'],
            'التجاوزُ عن ميزانية 6000 بتكلفة 5000 = -1000');

        // نُفوتِر أمرَ الشراء: يولّد مستندَ مالية «فاتورة مشتريات» بنفس المبلغ
        $this->actingAs($this->owner)->post("/purchase/{$pur->id}/act", ['do' => 'bill'])
            ->assertRedirect();

        $this->assertDatabaseHas('fin_documents', ['kind' => 'فاتورة مشتريات', 'project_id' => $p->id]);

        // بعد الفوترة: المبلغُ نفسُه لا يُعدّ مرّتين
        $after = hub_project_pl($p->id, true);
        $this->assertSame(5000.0, $after['cost']['external'],
            'أمرُ الشراء المُفوتَر يُحتسَب مرّةً واحدة: مرةً من صفّه أو مرةً من '
            . 'فاتورته المولَّدة — لا المرّتين معاً. كلُّ رقمِ تكلفةٍ وهامشٍ على '
            . '/costs يعتمد على هذا');
    }

    /** والحالةُ الميتة (ملغاة/مرتجعة) ليست تكلفةً على المشروع */
    public function test_a_dead_purchase_is_not_charged_to_the_project(): void
    {
        $this->seedCore();

        $p = Project::create(['name' => 'مشروعٌ ثانٍ', 'status' => 'نشط',
            'currency' => 'د.ك', 'start_date' => now()->subMonth()->toDateString()]);

        Purchase::create(['doc_no' => 'PO-live', 'status' => 'مستلم', 'amount' => 3000,
            'currency' => 'د.ك', 'project_id' => $p->id, 'date' => now()->toDateString()]);
        Purchase::create(['doc_no' => 'PO-dead', 'status' => 'ملغى', 'amount' => 9000,
            'currency' => 'د.ك', 'project_id' => $p->id, 'date' => now()->toDateString()]);

        $pl = hub_project_pl($p->id, true);
        $this->assertSame(3000.0, $pl['cost']['external'],
            'أمرُ شراءٍ ملغىً يُحمَّل على المشروع تكلفةً وهماً — الحالاتُ الميتة '
            . 'خارج الحساب كسائر التقارير');
    }

    /* ── ٢) إجمالي المسيّر لا يُكتب من النموذج العام ── */

    public function test_payroll_total_cannot_be_freely_edited_via_the_generic_form(): void
    {
        $this->seedCore();

        $run = PayrollRun::create(['name' => 'مسيّر يناير', 'status' => 'مسودة', 'month' => '2026-01', 'total' => 3000]);
        foreach ([1000, 1000, 1000] as $i => $net) {
            PayrollLine::create(['run_id' => $run->id, 'emp_id' => (string) \Illuminate\Support\Str::uuid(),
                'base' => $net, 'allow' => 0, 'overtime' => 0, 'deduct' => 0, 'advance' => 0, 'net' => $net]);
        }

        // مستخدمٌ يملك تعديلَ الرواتب يحاول رفعَ الإجمالي إلى عشرة أضعافه —
        // بحمولةٍ كاملةٍ تجتاز التحقّق (name وmonth مُلزَمان) كي يُختبَر الكتابةُ
        // نفسُها لا رفضُ التحقّق (وإلا مرّ الاختبارُ فراغاً)
        $this->actingAs($this->owner)->put("/m/payroll/{$run->id}", [
            'name' => 'مسيّر يناير', 'month' => '2026-01', 'total' => 30000,
        ])->assertRedirect();

        $this->assertSame('3000.000', (string) $run->fresh()->total,
            'حقلُ «الإجمالي» في المسيّر مشتقٌّ من مجموع السطور — لا يُحرَّر من '
            . 'النموذج العام، وإلا بنى عليه قيدُ اليومية المُرحَّل (المقفول أبداً) '
            . 'رقماً مختلَقاً');
    }

    /** والاعتمادُ يرفض مسيّراً إجمالُه يخالف مجموعَ سطوره، والقيدُ يبني على المجموع */
    public function test_approve_rejects_a_run_whose_total_diverges_from_its_lines(): void
    {
        $this->seedCore();
        $this->hubSetting('finance.auto_journal', '1');
        $this->hubSetting('finance.accounts', json_encode(['exp' => '5100', 'bank' => '1010']));
        \App\Models\LedgerAccount::create(['code' => '5100', 'name' => 'مصروف رواتب', 'type' => 'مصروف']);
        \App\Models\LedgerAccount::create(['code' => '1010', 'name' => 'البنك', 'type' => 'أصل']);

        $run = PayrollRun::create(['name' => 'مسيّر مُفسَد', 'status' => 'مسودة', 'month' => '2026-01', 'total' => 3000]);
        foreach ([1000, 1000, 1000] as $i => $net) {
            PayrollLine::create(['run_id' => $run->id, 'emp_id' => (string) \Illuminate\Support\Str::uuid(),
                'base' => $net, 'allow' => 0, 'overtime' => 0, 'deduct' => 0, 'advance' => 0, 'net' => $net]);
        }

        // إفسادُ الإجمالي مباشرةً في القاعدة (تجاوزاً لقفل الحقل) ثم الاعتماد
        DB::table('payroll_runs')->where('id', $run->id)->update(['total' => 30000]);

        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", ['do' => 'approve'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('journal_entries', ['reference' => 'مسيّر مُفسَد']);
    }

    /** ومسيّرٌ سليمٌ يُعتمد ويُرحّل قيدَه بمدينٍ = مجموع السطور لا الحقل المخزّن */
    public function test_a_sound_run_posts_a_journal_debit_equal_to_the_line_sum(): void
    {
        $this->seedCore();
        $this->hubSetting('finance.auto_journal', '1');
        $this->hubSetting('finance.accounts', json_encode(['exp' => '5100', 'bank' => '1010']));
        $exp = \App\Models\LedgerAccount::create(['code' => '5100', 'name' => 'مصروف رواتب', 'type' => 'مصروف']);
        \App\Models\LedgerAccount::create(['code' => '1010', 'name' => 'البنك', 'type' => 'أصل']);

        $run = PayrollRun::create(['name' => 'مسيّر سليم', 'status' => 'مسودة', 'month' => '2026-01', 'total' => 3000]);
        foreach ([1000, 1000, 1000] as $i => $net) {
            PayrollLine::create(['run_id' => $run->id, 'emp_id' => (string) \Illuminate\Support\Str::uuid(),
                'base' => $net, 'allow' => 0, 'overtime' => 0, 'deduct' => 0, 'advance' => 0, 'net' => $net]);
        }

        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", ['do' => 'approve'])
            ->assertRedirect();

        $entry = DB::table('journal_entries')->where('reference', 'مسيّر سليم')->first();
        $this->assertNotNull($entry, 'لم يُرحَّل قيدُ الرواتب');
        $debit = (float) DB::table('journal_lines')->where('entry_id', $entry->id)
            ->where('acc_id', $exp->id)->value('debit');
        $this->assertSame(3000.0, $debit, 'مدينُ قيد الرواتب = مجموع سطور net لا الحقل المخزّن');
    }
}
