<?php

namespace Tests\Feature;

use App\Models\FinDocument;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use Tests\TestCase;

/**
 * المرحلة ٢ — القيود المزدوجة: كان القيد رأساً بلا جسم (لا مدين/دائن/حساب)
 * فلا يوازن قيدٌ ولا يُشتق ميزان. السطور تُبنى على المسودة، والترحيل يرفض
 * ما لا يوازن ويقفل نهائياً، وخريطة finance.accounts المبذورة وجدت قارئها
 * الأول: قيد قبضٍ/صرفٍ آلي مع كل دفعة (خلف إعداد).
 */
class JournalLinesRound6Test extends TestCase
{
    protected function seedLedger(): void
    {
        foreach ([['1010', 'الصندوق', 'أصول'], ['1020', 'البنك', 'أصول'],
                  ['4100', 'المبيعات', 'إيرادات'], ['5200', 'مصروفات تشغيلية', 'مصروفات']] as [$c, $n, $t]) {
            LedgerAccount::create(['code' => $c, 'name' => $n, 'type' => $t]);
        }
        $this->hubSetting('finance.accounts', json_encode(
            ['cash' => '1010', 'bank' => '1020', 'sales' => '4100', 'exp' => '5200'], JSON_UNESCAPED_UNICODE));
    }

    protected function acc(string $code): LedgerAccount
    {
        return LedgerAccount::where('code', $code)->firstOrFail();
    }

    public function test_unbalanced_entry_refuses_posting_and_balanced_locks(): void
    {
        $this->seedCore();
        $this->seedLedger();
        $e = JournalEntry::create(['doc_no' => 'JE-T1', 'date' => now()->toDateString(), 'state' => 'مسودة']);

        $this->actingAs($this->owner)->post('/entry/' . $e->id . '/line',
            ['accId' => $this->acc('1010')->id, 'debit' => 100]);
        $this->actingAs($this->owner)->post('/entry/' . $e->id . '/line',
            ['accId' => $this->acc('4100')->id, 'credit' => 60]);

        // غير موزون → يرفض ويبقى مسودة
        $this->actingAs($this->owner)->post('/entry/' . $e->id . '/post')->assertStatus(422);
        $this->assertSame('مسودة', $e->fresh()->state);

        // إكمال التوازن → يُرحَّل
        $this->actingAs($this->owner)->post('/entry/' . $e->id . '/line',
            ['accId' => $this->acc('4100')->id, 'credit' => 40]);
        $this->actingAs($this->owner)->post('/entry/' . $e->id . '/post');
        $this->assertSame('مرحّل', $e->fresh()->state);
    }

    public function test_posted_entry_is_locked_on_every_door(): void
    {
        $this->seedCore();
        $this->seedLedger();
        // القيدُ يُرحَّل بمساره الحقيقي — لا يُزرع «مرحّلاً» بلا سطور: حارسُ
        // الدخول (v2.314) يرفض ذلك، وهو نفسه ما يمنع دفتراً مختلاً مقفولاً أبداً
        $e = JournalEntry::create(['doc_no' => 'JE-T2', 'date' => now()->toDateString(), 'state' => 'مسودة']);
        $this->actingAs($this->owner)->post('/entry/' . $e->id . '/line',
            ['accId' => $this->acc('1010')->id, 'debit' => 40]);
        $this->actingAs($this->owner)->post('/entry/' . $e->id . '/line',
            ['accId' => $this->acc('4100')->id, 'credit' => 40]);
        $this->actingAs($this->owner)->post('/entry/' . $e->id . '/post');
        $this->assertSame('مرحّل', $e->fresh()->state, 'خطُّ الأساس: القيد رُحِّل فعلاً');

        // التعديل من النموذج مرفوض
        $this->actingAs($this->owner)->put('/m/entries/' . $e->id, [
            'no' => 'JE-T2-معدل', 'date' => now()->toDateString(), 'state' => 'مسودة',
        ])->assertSessionHasErrors();
        $this->assertSame('JE-T2', $e->fresh()->doc_no);

        // وإضافة سطر على المُرحَّل مرفوضة
        $this->actingAs($this->owner)->post('/entry/' . $e->id . '/line',
            ['accId' => $this->acc('1010')->id, 'debit' => 5])->assertStatus(422);
        $this->assertSame(2, JournalLine::where('entry_id', $e->id)->count(),
            'لم يُضَف سطرٌ إلى القيد المُرحَّل — سطراه الأصليان وحدهما');
    }

    public function test_payment_generates_balanced_locked_journal_when_enabled(): void
    {
        $this->seedCore();
        $this->seedLedger();
        $this->hubSetting('finance.auto_journal', '1');
        $doc = FinDocument::create(['doc_no' => 'INV-J1', 'kind' => 'فاتورة مبيعات',
            'date' => now()->toDateString(), 'total' => 250, 'paid' => 0, 'state' => 'مرسلة']);

        $this->actingAs($this->owner)->post('/fin/' . $doc->id . '/act', ['do' => 'pay', 'amount' => 250]);

        $entry = JournalEntry::where('fin_id', $doc->id)->first();
        $this->assertNotNull($entry, 'خريطة finance.accounts المبذورة وجدت قارئها — قيد آلي مع الدفعة');
        $this->assertSame('مرحّل', $entry->state);

        $lines = JournalLine::where('entry_id', $entry->id)->get();
        $this->assertCount(2, $lines);
        $this->assertEquals(250.0, (float) $lines->sum('debit'));
        $this->assertEquals(250.0, (float) $lines->sum('credit'));
        // قبض بلا بنك → الصندوق 1010 مدين والمبيعات 4100 دائنة
        $this->assertSame($this->acc('1010')->id, $lines->firstWhere('debit', '>', 0)->acc_id);
        $this->assertSame($this->acc('4100')->id, $lines->firstWhere('credit', '>', 0)->acc_id);
    }

    public function test_auto_journal_stays_silent_when_disabled(): void
    {
        $this->seedCore();
        $this->seedLedger();
        $doc = FinDocument::create(['doc_no' => 'INV-J2', 'kind' => 'مصروف',
            'date' => now()->toDateString(), 'total' => 90, 'paid' => 0, 'state' => 'مرسلة']);

        $this->actingAs($this->owner)->post('/fin/' . $doc->id . '/act', ['do' => 'pay', 'amount' => 90]);

        $this->assertSame(0, JournalEntry::where('fin_id', $doc->id)->count(),
            'الترحيل الآلي قرار المنشأة — معطل افتراضياً');
    }
}
