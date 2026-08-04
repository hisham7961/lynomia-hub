<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\PurchaseController;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\FinDocument;
use App\Models\Purchase;
use App\Models\StockItem;
use App\Models\Supplier;
use Tests\TestCase;

/**
 * الموجة الرابعة — عيوب الحتمية/الترتيب المنخفضة:
 * (١٠) Contract::nextDocNo بلا قفل: إنشاءان متزامنان يقرآن أعلى تسلسلٍ معاً
 *      فيولّدان الرقم نفسه ويتصادمان على القيد الفريد (500 على MySQL). العلاج:
 *      إعادة المحاولة بترقيمٍ جديد عند خرق القيد بدل إفشال الإنشاء.
 * (١٥) PortalController::me يحلّ موظف النفس بـ first() بلا orderBy: ربطٌ مزدوج
 *      (موظفان بنفس user_id) يجعل /me يعرض بيانات زميلٍ بالقرعة بين المحرّكين.
 * (١٦) مطابقة صنف استلام الشراء غير منطَّقة بالشركة: أمرٌ بلا شركة يحرّك مخزون
 *      شركةٍ أخرى (الفلتر يُتخطّى عند غياب company_id).
 * (١٧) toBill يفحص وجود الفاتورة السابقة بـ find (يستبعد المحذوف ناعماً) فيُنشئ
 *      فاتورة مورد مكرّرة إن نُقلت السابقة للسلة.
 */
class DeterminismLowRound4Test extends TestCase
{
    protected function invoke(string $method, Purchase $p): void
    {
        $ctrl = new PurchaseController();
        $ref = new \ReflectionMethod($ctrl, $method);
        $ref->setAccessible(true);
        $ref->invoke($ctrl, $p);
    }

    /** (١٠) رقم وثيقةٍ مكرّر يُعاد توليده لا يُفشل الإنشاء بـ500 */
    public function test_duplicate_contract_doc_no_is_regenerated_not_fatal(): void
    {
        $this->seedCore();
        $a = Contract::create(['title' => 'عقد أ', 'type' => 'عقد عميل']);

        // معالجٌ متزامن «سبقنا» إلى الرقم نفسه: عقدٌ ثانٍ يحمل رقم الأول
        $b = Contract::create(['title' => 'عقد ب', 'type' => 'عقد عميل', 'doc_no' => $a->doc_no]);

        $this->assertNotSame($a->doc_no, $b->fresh()->doc_no, 'الرقم المكرّر لم يُعَد توليده');
        $this->assertDatabaseHas('contracts', ['id' => $b->id]);
    }

    /** (١٥) /me يحلّ موظف النفس بترتيبٍ حاسم لا بالقرعة */
    public function test_me_resolves_self_employee_deterministically(): void
    {
        $this->seedCore();
        $u = $this->employee;

        // موظفان مربوطان بنفس user_id بمعرّفَين نتحكّم بترتيبهما (قبل المصادقة
        // فيتخطّى حارس user_id) — الأصغر معرّفاً يجب أن يُختار حتماً على المحرّكين
        $hi = (new Employee)->forceFill(['id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'name' => 'الأحدث معرّفاً', 'status' => 'نشط', 'user_id' => $u->id]);
        $hi->save();
        $lo = (new Employee)->forceFill(['id' => '00000000-0000-4000-8000-000000000000',
            'name' => 'الأقدم معرّفاً', 'status' => 'نشط', 'user_id' => $u->id]);
        $lo->save();

        $resp = $this->actingAs($u)->get('/me')->assertOk();
        $this->assertSame($lo->id, $resp->viewData('emp')->id,
            'me() اختار موظفاً بالقرعة لا بترتيبٍ حاسم — بيانات زميلٍ قد تظهر لصاحب الحساب');
    }

    /** (١٦) أمرٌ بلا شركة لا يحرّك مخزون شركةٍ أخرى */
    public function test_receive_without_company_does_not_touch_another_company_stock(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $itemA = StockItem::create(['name' => 'راوتر', 'qty' => 10, 'reorder' => 2,
            'status' => 'متاح', 'company_id' => $coA->id]);

        // أمرُ شراءٍ بلا شركة، ببندٍ يطابق اسم صنف شركة ألف
        $p = Purchase::create(['doc_no' => 'PO-NOCO', 'title' => 'أمر بلا شركة',
            'status' => 'أُرسل للمورد', 'amount' => 100, 'items' => 'راوتر | 5 | 20']);

        $this->invoke('receive', $p);

        $this->assertEquals(10.0, (float) $itemA->fresh()->qty,
            'أمرٌ بلا شركة حرّك مخزون صنفٍ يخصّ شركةً أخرى');
    }

    /** ولا يُفرط: أمرٌ بشركةٍ يطابق صنفها هو */
    public function test_receive_with_company_still_matches_its_own_stock(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $itemA = StockItem::create(['name' => 'سويتش', 'qty' => 10, 'reorder' => 2,
            'status' => 'متاح', 'company_id' => $coA->id]);

        $p = Purchase::create(['doc_no' => 'PO-COA', 'title' => 'أمر ألف', 'company_id' => $coA->id,
            'status' => 'أُرسل للمورد', 'amount' => 100, 'items' => 'سويتش | 5 | 20']);

        $this->invoke('receive', $p);

        $this->assertEquals(15.0, (float) $itemA->fresh()->qty, 'أمرُ الشركة لم يطابق صنفها');
    }

    /** (١٧) حذف فاتورة المورد ناعماً ثم إعادة توليدها لا يُنشئ نسخةً مكرّرة */
    public function test_tobill_does_not_duplicate_when_previous_is_trashed(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $s = Supplier::create(['name' => 'مورد الأجهزة']);
        $p = Purchase::create(['doc_no' => 'PO-BILL', 'title' => 'أمر', 'status' => 'مستلم',
            'amount' => 500, 'supplier_id' => $s->id, 'currency' => 'د.ك', 'pay_state' => 'غير مدفوع']);

        $this->invoke('toBill', $p);
        $bill = FinDocument::where('kind', 'فاتورة مشتريات')->firstOrFail();
        $bill->delete();   // نُقلت للسلة

        $this->invoke('toBill', $p->fresh());

        $this->assertSame(1, FinDocument::withTrashed()->where('kind', 'فاتورة مشتريات')->count(),
            'أُنشئت فاتورة مورد مكرّرة بعد حذف السابقة ناعماً');
    }
}
