<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\PurchaseController;
use App\Models\Purchase;
use App\Models\StockItem;
use App\Models\StockMove;
use Tests\TestCase;

/**
 * الموجة الرابعة — سلامة المخزون/المشتريات:
 * (أ) إلغاء حركة مخزون مُرحَّلة كان يعكس الدلتا بلا حارس النقص الذي يحرسه الترحيل —
 *     فعكسُ استلامٍ استُهلك رصيدُه بحركاتٍ لاحقة يُنزل الرصيد تحت الصفر.
 * (ب) استلام أمر الشراء كان بلا معاملة ولا قفل — نقرتان متزامنتان تُضاعفان حركات
 *     المخزون والرصيد. الآن الاستلام داخل معاملة على صفٍّ مقفول يعيد قراءة الحالة.
 */
class StockPurchaseIntegrityRound4Test extends TestCase
{
    protected function move(StockItem $i, string $kind, float $qty): StockMove
    {
        return StockMove::create(['doc_no' => 'MV-' . strtoupper(substr(uniqid(), -6)),
            'kind' => $kind, 'item_id' => $i->id, 'qty' => $qty,
            'date' => now()->toDateString(), 'status' => 'مسودة']);
    }

    /** (أ) إلغاء استلامٍ استُهلك رصيدُه يُرفض بدل أن يُنزل الرصيد للسالب */
    public function test_cancelling_a_consumed_receipt_cannot_drive_balance_negative(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $item = StockItem::create(['name' => 'صنف', 'qty' => 10, 'reorder' => 2, 'status' => 'متاح']);

        $recv = $this->move($item, 'استلام', 5);
        $this->post("/stockmv/{$recv->id}/act", ['do' => 'confirm']);
        $this->assertEquals(15.0, (float) $item->fresh()->qty);

        $this->post("/stockmv/{$this->move($item, 'صرف', 12)->id}/act", ['do' => 'confirm']);
        $this->assertEquals(3.0, (float) $item->fresh()->qty);

        // عكسُ الاستلام يخصم 5 من 3 = -2 → يجب أن يُرفض
        $this->post("/stockmv/{$recv->id}/act", ['do' => 'cancel'])->assertStatus(422);
        $this->assertEquals(3.0, (float) $item->fresh()->qty, 'إلغاء الاستلام أنزل الرصيد للسالب');
        $this->assertSame('مؤكدة', $recv->fresh()->status, 'الحركة بقيت كما هي — لم تُلغَ');
    }

    /** (ب) نقرتان متزامنتان على «استلام» لا تُضاعفان المخزون */
    public function test_two_stale_receives_do_not_double_stock(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $item = StockItem::create(['name' => 'كيبل', 'qty' => 10, 'reorder' => 2, 'status' => 'متاح']);
        $p = Purchase::create(['doc_no' => 'PO-DUP', 'title' => 'أمر', 'status' => 'أُرسل للمورد',
            'amount' => 100, 'items' => 'كيبل | 15 | 2']);

        // معالجان حمّلا الأمر معاً قبل أن يستلمه أيٌّ منهما (status='أُرسل للمورد'، meta فارغة)
        $a = Purchase::find($p->id);
        $b = Purchase::find($p->id);

        $ctrl = new PurchaseController();
        $ref = new \ReflectionMethod($ctrl, 'receive');
        $ref->setAccessible(true);

        $ref->invoke($ctrl, $a);   // الأول يستلم: 10 → 25، حركة واحدة
        $ref->invoke($ctrl, $b);   // النسخة القديمة يجب ألا تكرر

        $this->assertEquals(25.0, (float) $item->fresh()->qty, 'نقرتان ضاعفتا المخزون');
        $this->assertSame(1, StockMove::where('reference', 'PO-DUP')->count(),
            'حركتا استلام مؤكدتان بدل واحدة');
    }
}
