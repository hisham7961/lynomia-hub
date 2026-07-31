<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\FinDocument;
use Tests\TestCase;

/**
 * المرحلة ٢ — «سجّل دفعة»: كان «المدفوع» حقلاً يدوياً محضاً، والحالات الثلاث
 * (مدفوعة جزئياً/مدفوعة/متأخرة) معرفةً بلا قائد، ورصيد البنوك جامداً.
 * الدفعة الآن تقود الثلاثة: المبلغ والحالة والرصيد.
 */
class FinPayRound6Test extends TestCase
{
    protected function invoice(array $extra = []): FinDocument
    {
        return FinDocument::create(array_merge([
            'doc_no' => 'PAY-' . strtoupper(substr(uniqid(), -6)), 'kind' => 'فاتورة مبيعات',
            'date' => now()->toDateString(), 'total' => 100, 'paid' => 0, 'state' => 'مرسلة',
        ], $extra));
    }

    public function test_partial_then_full_payment_drives_state(): void
    {
        $this->seedCore();
        $doc = $this->invoice();

        $this->actingAs($this->owner)->post('/fin/' . $doc->id . '/act', ['do' => 'pay', 'amount' => 40]);
        $doc->refresh();
        $this->assertEquals(40.0, (float) $doc->paid);
        $this->assertSame('مدفوعة جزئياً', $doc->state, 'الدفعة الجزئية تنقل الحالة آلياً');

        $this->actingAs($this->owner)->post('/fin/' . $doc->id . '/act', ['do' => 'pay', 'amount' => 60]);
        $doc->refresh();
        $this->assertEquals(100.0, (float) $doc->paid);
        $this->assertSame('مدفوعة', $doc->state, 'اكتمال السداد يختم الحالة «مدفوعة»');
    }

    public function test_payment_moves_bank_balance_by_document_direction(): void
    {
        $this->seedCore();
        $bank = BankAccount::create(['name' => 'الصندوق الرئيسي', 'balance' => 1000]);

        // قبضٌ لفاتورة مبيعات يزيد الرصيد
        $inv = $this->invoice(['total' => 200]);
        $this->actingAs($this->owner)->post('/fin/' . $inv->id . '/act',
            ['do' => 'pay', 'amount' => 200, 'bankId' => $bank->id]);
        $this->assertEquals(1200.0, (float) $bank->fresh()->balance);

        // وصرفٌ لمصروف ينقصه
        $exp = $this->invoice(['kind' => 'مصروف', 'total' => 50]);
        $this->actingAs($this->owner)->post('/fin/' . $exp->id . '/act',
            ['do' => 'pay', 'amount' => 50, 'bankId' => $bank->id]);
        $this->assertEquals(1150.0, (float) $bank->fresh()->balance);
    }

    public function test_overpayment_is_capped_and_dead_docs_refuse(): void
    {
        $this->seedCore();
        $doc = $this->invoice(['total' => 100]);
        $this->actingAs($this->owner)->post('/fin/' . $doc->id . '/act', ['do' => 'pay', 'amount' => 500]);
        $this->assertEquals(100.0, (float) $doc->fresh()->paid, 'الفائض خطأ إدخال لا إيراد — يُقصّ على المتبقي');

        $dead = $this->invoice(['state' => 'ملغاة']);
        $this->actingAs($this->owner)->post('/fin/' . $dead->id . '/act', ['do' => 'pay', 'amount' => 10])
            ->assertStatus(422);
        $this->assertEquals(0.0, (float) $dead->fresh()->paid);
    }

    public function test_payment_requires_edit_permission(): void
    {
        $this->seedCore();
        $doc = $this->invoice();
        $this->actingAs($this->viewer)->post('/fin/' . $doc->id . '/act', ['do' => 'pay', 'amount' => 10])
            ->assertForbidden();
    }
}
