<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Purchase;
use App\Models\StockItem;
use App\Models\StockMove;
use Tests\TestCase;

/**
 * الفحص النهائيّ (بند ٨) — دفعة v2.302: عيبا المخزون وعيبا الإجازة، كلٌّ فاشلٌ أولاً:
 *
 *  (#13) استلامُ أمر الشراء يقرأ‑ويكتب رصيد الصنف بلا قفلٍ صفّي — استلامان
 *        متزامنان لنفس الصنف يضيع أحد زيادتيهما (تحديثٌ ضائع). السباق لا يُحاكى
 *        بمنفذٍ واحد، فنُثبت وجود القفل في المصدر (نمط البيت) مع سلامة الاستلام.
 *
 *  (#14) «مرتجع» أمر الشراء كان يقلب الحالة فقط ولا يعكس حركات الاستلام —
 *        فالرصيد يبقى منفوخاً بعد خروج البضاعة. الآن يُنشأ مرتجعٌ صادرٌ لكل
 *        استلامٍ ويُخصم الرصيد (بلا هبوطٍ تحت الصفر، وبلا خصمٍ مضاعف).
 *
 *  (#16) استعادةُ إجازةٍ معتمدة من السلة غير ذرّية: الصف يُحيا قبل إعادة الخصم،
 *        فإن عجز الرصيد بقيت الإجازة معتمدةً بلا خصم. الآن الاستعادة داخل معاملة.
 *
 *  (#17) اعتمادٌ يفشل خصمُه (رصيدٌ لا يكفي تحت القفل) كان يبقي الإجازة «معتمدة»
 *        بلا خصم — إجازةٌ مجانية. الآن كتابةُ الحالة والخصمُ ذرّيّان فترتدّ معاً.
 */
class FinalAuditStockLeaveTest extends TestCase
{
    /* ───────────── المخزون ───────────── */

    /** (#13) استلامُ الشراء يقفل صفَّ الصنف قبل قراءة‑وكتابة رصيده */
    public function test_purchase_receive_locks_the_stock_item_row(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Web/PurchaseController.php'));
        $recv = substr($src, strpos($src, 'protected function receive'),
            strpos($src, 'protected function returnStock') - strpos($src, 'protected function receive'));

        // قفلان: على أمر الشراء (idempotency) وعلى الصنف (الرصيد) — العيبُ كان غياب الثاني
        $this->assertGreaterThanOrEqual(2, substr_count($recv, 'lockForUpdate'),
            'استلام الشراء يقرأ‑ويكتب رصيد الصنف بلا قفلٍ صفّي — تحديثٌ ضائع عند التزامن');
    }

    /** (#13 سلامة) الاستلام العاديّ يزيد رصيد الصنف بمقدار البند — القفل لا يعطّله */
    public function test_purchase_receive_still_increments_stock(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $item = StockItem::create(['name' => 'كيبل', 'qty' => 10, 'reorder' => 2, 'status' => 'متاح']);
        $p = Purchase::create(['doc_no' => 'PO-RCV', 'title' => 'أمر', 'status' => 'أُرسل للمورد',
            'amount' => 100, 'items' => 'كيبل | 15 | 2']);

        $this->post("/purchase/{$p->id}/act", ['do' => 'receive'])->assertRedirect();
        $this->assertEquals(25.0, (float) $item->fresh()->qty, 'الاستلام لم يزد المخزون');
    }

    /** (#14) المرتجع يعكس حركة الاستلام فيعود الرصيد — لا يبقى منفوخاً */
    public function test_purchase_return_reverses_received_stock(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $item = StockItem::create(['name' => 'راوتر', 'qty' => 0, 'reorder' => 2, 'status' => 'نفد']);
        $p = Purchase::create(['doc_no' => 'PO-RET', 'title' => 'أمر', 'status' => 'أُرسل للمورد',
            'amount' => 100, 'items' => 'راوتر | 5 | 20']);

        $this->post("/purchase/{$p->id}/act", ['do' => 'receive']);
        $this->assertEquals(5.0, (float) $item->fresh()->qty, 'الاستلام لم يزد المخزون');

        $this->post("/purchase/{$p->id}/act", ['do' => 'return'])->assertRedirect();

        $this->assertEquals(0.0, (float) $item->fresh()->qty,
            'المرتجع لم يعكس حركة الاستلام — الرصيد بقي منفوخاً بعد خروج البضاعة');
        $this->assertSame('مرتجع', $p->fresh()->status);
        $this->assertSame(1, StockMove::where('reference', 'PO-RET')->where('kind', 'مرتجع صادر')->count(),
            'لم تُنشأ حركةُ مرتجعٍ صادرٍ تعكس الاستلام');
    }

    /** (#14) المرتجع لا يهبط بالرصيد تحت الصفر إن استُهلكت البضاعة قبل الرد */
    public function test_purchase_return_does_not_drop_stock_below_zero(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $item = StockItem::create(['name' => 'شاشة', 'qty' => 0, 'reorder' => 2, 'status' => 'نفد']);
        $p = Purchase::create(['doc_no' => 'PO-NEG', 'title' => 'أمر', 'status' => 'أُرسل للمورد',
            'amount' => 100, 'items' => 'شاشة | 5 | 20']);

        $this->post("/purchase/{$p->id}/act", ['do' => 'receive']);   // 0 → 5
        $item->update(['qty' => 0]);                                     // استُهلكت كلها قبل الرد

        $this->post("/purchase/{$p->id}/act", ['do' => 'return'])->assertRedirect();
        $this->assertEquals(0.0, (float) $item->fresh()->qty, 'المرتجع هبط بالرصيد تحت الصفر');
    }

    /* ───────────── الإجازات ───────────── */

    protected function emp(float $bal): Employee
    {
        return Employee::create(['name' => 'موظف ' . uniqid(), 'status' => 'على رأس العمل', 'leave_bal' => $bal]);
    }

    /**
     * (#16) استعادةُ إجازةٍ عجز رصيدها ذرّية: تبقى في السلة، لا تُحيا معتمدةً بلا خصم.
     */
    public function test_restoring_leave_is_atomic_when_balance_now_insufficient(): void
    {
        $this->seedCore();
        $emp = $this->emp(10);

        // A معتمدة ٦ أيام → الرصيد ٤
        $a = LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية', 'status' => 'مسودة',
            'date_from' => '2026-03-01', 'date_to' => '2026-03-06', 'days' => 6]);
        $a->update(['status' => 'معتمد']);
        $this->assertEquals(4.0, (float) $emp->fresh()->leave_bal);

        // احذفها → يعيد ٦ → الرصيد ١٠
        $a->delete();
        $this->assertEquals(10.0, (float) $emp->fresh()->leave_bal);

        // إجازةٌ أخرى تستهلك الرصيد → ٢
        $c = LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية', 'status' => 'مسودة',
            'date_from' => '2026-05-01', 'date_to' => '2026-05-09', 'days' => 8]);
        $c->update(['status' => 'معتمد']);
        $this->assertEquals(2.0, (float) $emp->fresh()->leave_bal);

        // استعادةُ A تحتاج ٦ والمتاح ٢ — تفشل ذرّيّاً: تبقى في السلة، الرصيد ٢
        try {
            $a->restore();
        } catch (\Illuminate\Validation\ValidationException $e) {
            // متوقّع — الرصيد لا يكفي
        }

        $this->assertTrue(LeaveRequest::withTrashed()->find($a->id)->trashed(),
            'استعادةٌ عجز رصيدها أحيت الإجازة معتمدةً بلا خصم — يجب أن تبقى في السلة');
        $this->assertEquals(2.0, (float) $emp->fresh()->leave_bal, 'الرصيد تغيّر رغم فشل الاستعادة');
    }

    /**
     * (#17) اعتمادٌ يفشل خصمُه لا يبقي الإجازة «معتمدة». اعتمادان متزامنان يمرّان
     * معاً من فحص `saving` غير المقفول (كلاهما يرى رصيداً كافياً)، ثم يخصم أحدهما
     * فيهبط الرصيد، فيرمي `applyBalance` المقفولُ للآخر — لكن حالته كُتبت سلفاً.
     *
     * نُحاكي النافذةَ حتميّاً: خطّافُ `updating` مُحقَنٌ يهبط بالرصيد **بين** فحص
     * `saving` (مرّ على ١٠) وخصمِ `applyBalance` في `updated` (يرى ٢ فيرمي) — عينُ
     * ما يفعله الاعتمادُ المنافس. بلا لفِّ الحفظ بمعاملةٍ تبقى الحالة «معتمد» بلا خصم.
     */
    public function test_approving_leave_rolls_back_status_when_deduction_fails_under_race(): void
    {
        $this->seedCore();
        $emp = $this->emp(10);
        $lv = LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية', 'status' => 'مسودة',
            'date_from' => '2026-06-01', 'date_to' => '2026-06-06', 'days' => 6]);

        // خصمٌ منافسٌ يهبط بالرصيد بعد فحص saving وقبل خصم updated المقفول
        LeaveRequest::updating(function (LeaveRequest $m) use ($emp) {
            if ((string) $m->status === 'معتمد') {
                Employee::whereKey($emp->id)->update(['leave_bal' => 2]);
            }
        });

        try {
            $lv->update(['status' => 'معتمد']);
            $this->fail('لم يُرفَض الاعتماد رغم عجز الرصيد تحت القفل');
        } catch (\Illuminate\Validation\ValidationException $e) {
            // متوقّع — لكن يجب أن ترتدّ كتابةُ الحالة مع الخصم الفاشل
        }

        $this->assertNotSame('معتمد', (string) $lv->fresh()->status,
            'الاعتماد فشل خصمُه لكن الحالة بقيت «معتمد» — إجازةٌ مجانية بلا خصم');
    }

    /** (#17 سلامة) اعتمادٌ رصيدُه كافٍ يمرّ ويُخصم — المعاملة لا تعطّل الحالة السليمة */
    public function test_approving_leave_with_sufficient_balance_still_deducts(): void
    {
        $this->seedCore();
        $emp = $this->emp(10);
        $lv = LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية',
            'status' => 'مسودة', 'date_from' => '2026-04-01', 'date_to' => '2026-04-06', 'days' => 6]);

        $lv->update(['status' => 'معتمد']);

        $this->assertSame('معتمد', (string) $lv->fresh()->status);
        $this->assertEquals(4.0, (float) $emp->fresh()->leave_bal, 'الاعتماد السليم لم يُخصم');
    }
}
