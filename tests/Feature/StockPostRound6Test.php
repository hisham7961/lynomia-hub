<?php

namespace Tests\Feature;

use App\Models\StockItem;
use App\Models\StockMove;
use Tests\TestCase;

/**
 * المرحلة ٣ — الدفتران يلتقيان أخيراً: كانت حركات المخزون تُسجَّل و«مؤكدة»
 * لا تفعل شيئاً — لا سطر واحد يحدّث رصيد الصنف من حركة. الترحيل الآن يحرّك
 * الرصيد بإشارة النوع، يمنع الازدواج، يعكس عند الإلغاء، ويشتق حالة الصنف
 * فتحيا مسارات «نفد/منخفض» الجاهزة المعطلة منذ ولادتها.
 */
class StockPostRound6Test extends TestCase
{
    protected function item(float $qty = 10, float $reorder = 3): StockItem
    {
        return StockItem::create(['name' => 'صنف اختبار ' . uniqid(), 'qty' => $qty, 'reorder' => $reorder,
            'status' => 'متاح']);
    }

    protected function move(StockItem $i, string $kind, float $qty, array $extra = []): StockMove
    {
        return StockMove::create(array_merge(['doc_no' => 'MV-' . strtoupper(substr(uniqid(), -6)),
            'kind' => $kind, 'item_id' => $i->id, 'qty' => $qty,
            'date' => now()->toDateString(), 'status' => 'مسودة'], $extra));
    }

    public function test_confirm_moves_balance_and_double_posting_is_blocked(): void
    {
        $this->seedCore();
        $i = $this->item(10);
        $mv = $this->move($i, 'استلام', 5);

        $this->actingAs($this->owner)->post('/stockmv/' . $mv->id . '/act', ['do' => 'confirm']);
        $this->assertEquals(15.0, (float) $i->fresh()->qty, 'الاستلام يزيد الرصيد');
        $this->assertSame('مؤكدة', $mv->fresh()->status);

        // الترحيل المزدوج ممنوع
        $this->actingAs($this->owner)->post('/stockmv/' . $mv->id . '/act', ['do' => 'confirm'])->assertStatus(422);
        $this->assertEquals(15.0, (float) $i->fresh()->qty);
    }

    public function test_issue_decrements_and_derives_status_down_to_zero(): void
    {
        $this->seedCore();
        $i = $this->item(10, 3);

        $this->actingAs($this->owner)->post('/stockmv/' . $this->move($i, 'صرف', 8)->id . '/act', ['do' => 'confirm']);
        $i->refresh();
        $this->assertEquals(2.0, (float) $i->qty);
        $this->assertSame('منخفض', $i->status, 'بلوغ حد إعادة الطلب يشتق «منخفض»');

        $this->actingAs($this->owner)->post('/stockmv/' . $this->move($i, 'صرف', 2)->id . '/act', ['do' => 'confirm']);
        $i->refresh();
        $this->assertEquals(0.0, (float) $i->qty);
        $this->assertSame('نفد', $i->status);

        // الصرف فوق الرصيد مرفوض بوضوح
        $this->actingAs($this->owner)->post('/stockmv/' . $this->move($i, 'صرف', 1)->id . '/act', ['do' => 'confirm'])
            ->assertStatus(422);
    }

    public function test_count_adjusts_to_counted_and_cancel_reverses(): void
    {
        $this->seedCore();
        $i = $this->item(10);

        // جرد: العدد المعدود 7 → تسوية بفارق -3
        $count = $this->move($i, 'جرد', 7);
        $this->actingAs($this->owner)->post('/stockmv/' . $count->id . '/act', ['do' => 'confirm']);
        $this->assertEquals(7.0, (float) $i->fresh()->qty, 'الجرد تسويةٌ إلى العدد المعدود');

        // الإلغاء يعكس الأثر
        $this->actingAs($this->owner)->post('/stockmv/' . $count->id . '/act', ['do' => 'cancel']);
        $this->assertEquals(10.0, (float) $i->fresh()->qty, 'إلغاء المُرحَّلة يعيد الرصيد');
        $this->assertSame('ملغاة', $count->fresh()->status);
    }

    public function test_transfer_requires_both_warehouses(): void
    {
        $this->seedCore();
        $i = $this->item(10);
        $mv = $this->move($i, 'تحويل بين المستودعات', 4, ['from_wh' => 'الرئيسي']);

        $this->actingAs($this->owner)->post('/stockmv/' . $mv->id . '/act', ['do' => 'confirm'])->assertStatus(422);
        $this->assertSame('مسودة', $mv->fresh()->status);
    }

    public function test_manual_status_flip_to_confirmed_is_rejected(): void
    {
        $this->seedCore();
        $i = $this->item(10);
        $mv = $this->move($i, 'استلام', 5);

        // قلب الحالة من نموذج التعديل العام لا يحرّك رصيداً — فيُمنع بمساره الصحيح
        $this->actingAs($this->owner)->put('/m/stockmv/' . $mv->id, [
            'no' => $mv->doc_no, 'kind' => 'استلام', 'itemId' => $i->id, 'qty' => 5,
            'date' => now()->toDateString(), 'status' => 'مؤكدة',
        ])->assertSessionHasErrors();
        $this->assertSame('مسودة', $mv->fresh()->status);
        $this->assertEquals(10.0, (float) $i->fresh()->qty);
    }
}
