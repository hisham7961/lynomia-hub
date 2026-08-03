<?php

namespace Tests\Feature;

use App\Models\Purchase;
use App\Models\StockItem;
use App\Models\StockMove;
use App\Models\Supplier;
use Tests\TestCase;

/**
 * المرحلة ٣ — أول اختبار لمسار الشراء (أنضج وحدة في مجموعتها كانت بلا حارس):
 *  1) آلة الحالة مفروضة على الخادم — كانت الأزرار تُخفى في القالب فقط.
 *  2) الاستلام يختم تاريخه الصريح ويحرّك المخزون من بنود الأمر.
 *  3) درجة المورد تقيس الالتزام من تاريخ الاستلام لا من آخر تحديث.
 */
class PurchaseFlowRound6Test extends TestCase
{
    protected function po(array $extra = []): Purchase
    {
        return Purchase::create(array_merge([
            'doc_no' => 'PO-' . strtoupper(substr(uniqid(), -6)),
            'title' => 'أمر شراء اختباري', 'status' => 'مسودة', 'amount' => 100,
        ], $extra));
    }

    public function test_state_machine_is_enforced_server_side(): void
    {
        $this->seedCore();
        $p = $this->po();

        // استلام مسودة كان يمرّ — صار يُرفض
        $this->actingAs($this->owner)->post('/purchase/' . $p->id . '/act', ['do' => 'receive'])->assertStatus(422);
        $this->assertSame('مسودة', $p->fresh()->status);

        // المسار الصحيح خطوةً خطوة
        foreach ([['submit', 'بانتظار الاعتماد'], ['approve', 'معتمد'],
                  ['send', 'أُرسل للمورد'], ['receive', 'مستلم']] as [$do, $expected]) {
            $this->actingAs($this->owner)->post('/purchase/' . $p->id . '/act', ['do' => $do]);
            $this->assertSame($expected, $p->fresh()->status, "الإجراء {$do}");
        }
        $this->assertNotNull($p->fresh()->received_at, 'الاستلام يختم تاريخه الصريح');

        // الملغى لا يُبعث حياً
        $dead = $this->po(['status' => 'ملغى']);
        $this->actingAs($this->owner)->post('/purchase/' . $dead->id . '/act', ['do' => 'submit'])->assertStatus(422);
    }

    public function test_approval_needs_approver_flag(): void
    {
        $this->seedCore();
        $p = $this->po(['status' => 'بانتظار الاعتماد']);

        // الموظفة بلا علم approve — تُرفض
        $this->actingAs($this->employee)->post('/purchase/' . $p->id . '/act', ['do' => 'approve'])->assertForbidden();
        $this->assertSame('بانتظار الاعتماد', $p->fresh()->status);
    }

    public function test_receiving_moves_stock_from_order_items(): void
    {
        $this->seedCore();
        $item = StockItem::create(['name' => 'كيبل شبكة', 'qty' => 10, 'reorder' => 2, 'status' => 'متاح']);
        $p = $this->po(['status' => 'أُرسل للمورد', 'items' => "كيبل شبكة | 15 | 2\nصنف غير مسجل | 3 | 1"]);

        $this->actingAs($this->owner)->post('/purchase/' . $p->id . '/act', ['do' => 'receive']);

        $this->assertEquals(25.0, (float) $item->fresh()->qty, 'استلام الأمر يحرّك رصيد الصنف المطابق');
        $mv = StockMove::where('reference', $p->doc_no)->first();
        $this->assertNotNull($mv, 'حركة استلام مؤكدة تولّدت من البند');
        $this->assertSame('مؤكدة', $mv->status);

        // استلام متكرر لا يكرر الحركات (idempotent)
        $p->fresh()->update(['status' => 'أُرسل للمورد']);
        $this->actingAs($this->owner)->post('/purchase/' . $p->id . '/act', ['do' => 'receive']);
        $this->assertEquals(25.0, (float) $item->fresh()->qty, 'لا ازدواج في الحركات');
    }

    public function test_supplier_score_measures_from_received_at(): void
    {
        $this->seedCore();
        $s = Supplier::create(['name' => 'مورد الموعد']);
        // استُلم في موعده فعلاً (received_at = أمس = الاستحقاق) لكن آخر تحديث اليوم —
        // القياس القديم (updated_at) كان يحسبه متأخراً
        Purchase::create(['doc_no' => 'PO-ONTIME', 'title' => 'في الموعد', 'status' => 'مستلم',
            'supplier_id' => $s->id, 'amount' => 100,
            'due' => now()->subDay()->toDateString(), 'received_at' => now()->subDay()->toDateString()]);

        $row = collect(hub_supplier_scores(true)['rows'])->firstWhere('id', $s->id);
        $this->assertSame(100, $row['onTimeRate'],
            'الالتزام يُقاس من تاريخ الاستلام الصريح لا من آخر تحديث للسجل');
    }
}
