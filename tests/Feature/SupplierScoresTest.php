<?php

namespace Tests\Feature;

use App\Models\Purchase;
use App\Models\Supplier;
use Tests\TestCase;

class SupplierScoresTest extends TestCase
{
    public function test_scores_from_purchase_history_math(): void
    {
        $this->seedCore();
        $s = Supplier::create(['name' => 'مورد الأجهزة', 'cat' => 'معدات', 'rating' => '⭐⭐⭐⭐ جيد جداً']);

        // مستلم في الموعد (updated الآن، due غداً)
        Purchase::create(['doc_no' => 'PO-1', 'supplier_id' => $s->id, 'amount' => 5000,
            'due' => now()->addDay(), 'status' => 'مستلم', 'pay_state' => 'مدفوع']);
        // مستلم متأخر (due قبل ٥ أيام) وغير مدفوع
        Purchase::create(['doc_no' => 'PO-2', 'supplier_id' => $s->id, 'amount' => 3000,
            'due' => now()->subDays(5), 'status' => 'مستلم', 'pay_state' => 'غير مدفوع']);
        // مرتجع
        Purchase::create(['doc_no' => 'PO-3', 'supplier_id' => $s->id, 'amount' => 1000, 'status' => 'مرتجع']);

        $row = collect(hub_supplier_scores(true)['rows'])->firstWhere('id', $s->id);

        $this->assertSame(3, $row['orders']);
        $this->assertSame(9000.0, $row['spend']);
        $this->assertSame(2, $row['received']);
        $this->assertSame(50, $row['onTimeRate']);        // ١ من ٢ في الموعد
        $this->assertSame(2, $row['onTimeBase']);
        $this->assertSame(1, $row['returned']);
        $this->assertSame(1, $row['unpaid']);             // PO-2 مستلم وغير مدفوع
        $this->assertSame(4, $row['stars']);
        // الدرجة: 50×0.6 + (1 − 1/3)×100×0.4 = 30 + 26.67 ≈ 57
        $this->assertSame(57, $row['score']);
    }

    public function test_supplier_with_no_history_has_null_score_not_zero(): void
    {
        $this->seedCore();
        $s = Supplier::create(['name' => 'مورد بلا أوامر']);

        $row = collect(hub_supplier_scores(true)['rows'])->firstWhere('id', $s->id);
        $this->assertSame(0, $row['orders']);
        $this->assertNull($row['score'], 'بلا سجل لا درجة — الصفر كذبة');
        $this->assertNull($row['onTimeRate']);
        $this->assertSame(1, hub_supplier_scores(true)['totals']['noHistory']);
    }

    public function test_cancellations_do_not_count_against_the_supplier(): void
    {
        $this->seedCore();
        $s = Supplier::create(['name' => 'مورد ممتاز']);
        // تسليم واحد في الموعد + أربعة أوامر ألغيناها نحن → يجب ألا تسحب الدرجة
        Purchase::create(['doc_no' => 'D1', 'supplier_id' => $s->id, 'amount' => 100,
            'due' => now()->addDay(), 'status' => 'مستلم', 'pay_state' => 'مدفوع']);
        foreach (['C1', 'C2', 'C3', 'C4'] as $n) {
            Purchase::create(['doc_no' => $n, 'supplier_id' => $s->id, 'amount' => 50, 'status' => 'ملغى']);
        }

        $row = collect(hub_supplier_scores(true)['rows'])->firstWhere('id', $s->id);
        // delivered = 1، returned = 0 → 100×0.6 + 1×100×0.4 = 100
        $this->assertSame(100, $row['score'], 'الإلغاء قرارنا لا يُحتسب ضد المورد');
        $this->assertSame(4, $row['cancelled']);
    }

    public function test_supplier_with_only_open_orders_has_null_score(): void
    {
        $this->seedCore();
        $s = Supplier::create(['name' => 'مورد جديد']);
        Purchase::create(['doc_no' => 'O1', 'supplier_id' => $s->id, 'amount' => 100, 'status' => 'أُرسل للمورد']);

        $row = collect(hub_supplier_scores(true)['rows'])->firstWhere('id', $s->id);
        $this->assertSame(1, $row['orders']);
        $this->assertSame(0, $row['delivered']);
        $this->assertNull($row['score'], 'أوامر مفتوحة بلا تسليم لا تعطي درجة خضراء وهمية');
        $this->assertSame(1, hub_supplier_scores(true)['totals']['noHistory']);
    }

    public function test_page_gated_by_suppliers_visibility(): void
    {
        $this->seedCore();
        Supplier::create(['name' => 'مورد']);

        $this->actingAs($this->owner)->get('/supplier-scores')->assertOk()->assertSee('تقييم الموردين');

        $role = $this->viewer->role;
        $m = $role->matrix; $m['suppliers'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->update(['matrix' => $m]);
        $this->actingAs($this->viewer)->get('/supplier-scores')->assertForbidden();
    }
}
