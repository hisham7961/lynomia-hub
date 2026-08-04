<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\EntryController;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * الموجة الرابعة — سباق بين إضافة سطرٍ وترحيل القيد:
 * line() يفحص «مسودة؟» من نسخةٍ في الذاكرة ثم يُنشئ السطر، وpost() يجمع السطور
 * ثم يقلب الحالة إلى «مرحّل» — بلا معاملةٍ ولا قفلٍ صفّيّ يسلسل الفحص مع الكتابة،
 * وJournalLine بلا حارس (بينما JournalEntry::booted يمنع تعديل/حذف المُرحَّل).
 * فمعالجٌ يجتاز فحص line() بينما القيد مسودة، ثم يُرحِّله post()، ثم يهبط سطرُه
 * على قيدٍ مُرحَّلٍ مقفول: مدينٌ ≠ دائن ولا مسار واجهة لإصلاحه — ميزانٌ مختلٌّ دائم.
 *
 * تُحاكى المتزامنة حتميّاً بنسخةٍ قُرئت مسودةً ثم رُحِّل القيدُ من تحتها: الحارس
 * السليم يعيد قراءة الحالة من صفٍّ مقفول داخل المعاملة، فالنسخة القديمة تُرفض بـ422.
 */
class EntryLineRaceRound4Test extends TestCase
{
    protected function seedLedger(): void
    {
        foreach ([['1010', 'الصندوق', 'أصول'], ['4100', 'المبيعات', 'إيرادات']] as [$c, $n, $t]) {
            LedgerAccount::create(['code' => $c, 'name' => $n, 'type' => $t]);
        }
    }

    protected function acc(string $code): string
    {
        return LedgerAccount::where('code', $code)->firstOrFail()->id;
    }

    /** قيد مسودة موزون: مدين 100 على الصندوق ودائن 100 على المبيعات */
    protected function balancedDraft(): JournalEntry
    {
        $e = JournalEntry::create(['doc_no' => 'JE-R-' . strtoupper(substr(uniqid(), -6)),
            'date' => now()->toDateString(), 'state' => 'مسودة']);
        JournalLine::create(['entry_id' => $e->id, 'acc_id' => $this->acc('1010'), 'debit' => 100, 'credit' => 0]);
        JournalLine::create(['entry_id' => $e->id, 'acc_id' => $this->acc('4100'), 'debit' => 0, 'credit' => 100]);

        return $e;
    }

    protected function invokePost(string $id): void
    {
        $ctrl = new EntryController();
        $ref = new \ReflectionMethod($ctrl, 'post');
        $ref->setAccessible(true);
        $ref->invoke($ctrl, $id);
    }

    protected function invokeAddLine(JournalEntry $e, array $attrs): void
    {
        $ctrl = new EntryController();
        $ref = new \ReflectionMethod($ctrl, 'addLineTo');
        $ref->setAccessible(true);
        $ref->invoke($ctrl, $e, $attrs);
    }

    public function test_line_cannot_land_on_entry_posted_after_its_state_read(): void
    {
        $this->seedCore();
        $this->seedLedger();
        $this->actingAs($this->owner);
        $e = $this->balancedDraft();

        // معالجٌ (line) قرأ القيدَ وهو مسودة — نسخةٌ قديمة تحمل «مسودة» في ذاكرتها
        $stale = JournalEntry::find($e->id);
        $this->assertSame('مسودة', $stale->state);

        // معالجٌ آخر (post) يُرحّل القيدَ من تحته
        $this->invokePost($e->id);
        $this->assertSame('مرحّل', JournalEntry::find($e->id)->state);

        // الآن يمضي line() بنسخته القديمة ليُنشئ سطره — يجب أن يُرفض لا أن يهبط على المُرحَّل
        try {
            $this->invokeAddLine($stale, ['acc_id' => $this->acc('1010'), 'debit' => 50, 'credit' => 0]);
            $this->fail('سطرٌ هبط على قيدٍ رُحِّل بعد قراءة حالته — ميزانٌ مختلٌّ لا يُصلَح');
        } catch (HttpException $ex) {
            $this->assertSame(422, $ex->getStatusCode());
        }

        // القيدُ المُرحَّل بقي كما رُحِّل: سطران فقط، ومدينٌ = دائن
        $this->assertEquals(2, JournalLine::where('entry_id', $e->id)->count(),
            'السطر المتأخر تسلّل إلى القيد المُرحَّل');
        $this->assertEquals(100.0, (float) JournalLine::where('entry_id', $e->id)->sum('debit'));
        $this->assertEquals(100.0, (float) JournalLine::where('entry_id', $e->id)->sum('credit'));
    }

    /** الترحيل نفسه لا يمرّ مرتين: نسختان قديمتان لا تُرحّلان مرّتين ولا تُشوّهان */
    public function test_post_is_idempotent_under_two_stale_reads(): void
    {
        $this->seedCore();
        $this->seedLedger();
        $this->actingAs($this->owner);
        $e = $this->balancedDraft();

        $this->invokePost($e->id);   // رُحّل
        $this->assertSame('مرحّل', JournalEntry::find($e->id)->state);

        // نسخةٌ قديمة تحاول الترحيل ثانيةً — تُرفض
        try {
            $this->invokePost($e->id);
            $this->fail('قيدٌ رُحّل مرتين');
        } catch (HttpException $ex) {
            $this->assertSame(422, $ex->getStatusCode());
        }
    }
}
