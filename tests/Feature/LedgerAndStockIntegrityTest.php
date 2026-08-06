<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Purchase;
use App\Models\StockItem;
use App\Models\StockMove;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ٣: **كلُّ كتابةٍ تُحرّك رصيداً أو تُقفل قيداً.**
 *
 *  ١) `JournalEntry:37` — الحارسُ معلّقٌ على `updating/deleting` ويفحص
 *     `getOriginal('state')`، فهو يمنع الخروجَ من «مرحّل» **لا الدخول إليه**.
 *     وحقلُ `state` قائمةٌ منسدلة في نموذج الوحدة العام بلا `locked`. فقيدٌ
 *     غيرُ موزون (مدين ١٠٠، دائن ٠) يُرحَّل بـ`PUT /m/entries/{id}` أو
 *     بإنشائه «مرحّلاً» ابتداءً — ثمّ **يُقفل أبداً**: لا تعديلَ ولا حذفَ ولا
 *     إصلاحَ في التطبيق كلّه. دفترٌ مختلٌّ دائم، بينما `post()` يرفض بـ422.
 *
 *  ٢) `PurchaseController:213` — المرتجعُ يعكس حركاتِ استلامٍ **أُلغيت من قبل**:
 *     لا فحصَ لـ`status` ولا لـ`meta['reversed_at']`. فاستلامٌ رُحِّل ثمّ أُلغي
 *     (فعاد الرصيد) ثمّ رُدّت البضاعة → خصمٌ مضاعف يُبخّر مخزوناً حقيقياً.
 *
 *  ٣) `StockController:28` — `act()` بلا `hub_block_if_queued` بينما
 *     `PurchaseController:70` يفعلها؛ فزرُّ الترحيل يلتفّ على طابور الموافقات
 *     الذي يسدّه نموذجُ الوحدة.
 *
 *  ٤) `EntryController:30` — `accId/ccId` بقاعدة `exists` وحدها بلا تنطيق،
 *     والقائمةُ في القالب تعرض دليلَ حساباتِ كلِّ الشركات.
 */
class LedgerAndStockIntegrityTest extends TestCase
{
    /* ── ١) لا ترحيل لقيدٍ غير موزون من أي منفذ ── */

    private ?\App\Models\Project $proj = null;

    /** المشروع إلزاميّ في نموذج القيود — فبدونه يسقط التحقّق ويمرّ الاختبار زوراً */
    private function project(): \App\Models\Project
    {
        return $this->proj ??= \App\Models\Project::create(['name' => 'مشروعُ الاختبار', 'status' => 'نشط']);
    }

    private function draftWithOneSidedLine(): JournalEntry
    {
        $e = JournalEntry::create(['doc_no' => 'JV-9001', 'date' => now()->toDateString(),
            'project_id' => $this->project()->id, 'state' => 'مسودة']);
        $acc = LedgerAccount::create(['code' => '4100', 'name' => 'مبيعات', 'type' => 'إيراد']);
        JournalLine::create(['entry_id' => $e->id, 'acc_id' => $acc->id, 'debit' => 100, 'credit' => 0]);

        return $e->refresh();
    }

    public function test_the_posting_route_rejects_an_unbalanced_entry(): void
    {
        $this->seedCore();
        $e = $this->draftWithOneSidedLine();

        $this->actingAs($this->owner)->post('/entry/' . $e->id . '/post')->assertStatus(422);
        $this->assertSame('مسودة', $e->fresh()->state, 'المسارُ المخصّص يحرس التوازن — خطُّ الأساس');
    }

    public function test_the_generic_module_form_cannot_post_an_unbalanced_entry(): void
    {
        $this->seedCore();
        $e = $this->draftWithOneSidedLine();

        $this->actingAs($this->owner)->put('/m/entries/' . $e->id, [
            'no' => 'JV-9001', 'date' => now()->toDateString(),
            'projectId' => $this->project()->id, 'state' => 'مرحّل',
        ]);

        $this->assertSame('مسودة', $e->fresh()->state,
            'رُحِّل قيدٌ غير موزون من نموذج الوحدة العام — ثمّ قُفل أبداً: لا تعديل ولا حذف ولا إصلاح');
    }

    public function test_an_entry_cannot_be_created_already_posted_without_lines(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/m/entries', [
            'no' => 'JV-9002', 'date' => now()->toDateString(),
            'projectId' => $this->project()->id, 'state' => 'مرحّل',
        ]);

        $this->assertSame(0, JournalEntry::where('doc_no', 'JV-9002')->where('state', 'مرحّل')->count(),
            'أُنشئ قيدٌ «مرحّل» بلا سطرٍ واحد — مقفولٌ ومختلٌّ من لحظة ميلاده');
    }

    public function test_a_balanced_entry_still_posts_through_the_module_form(): void
    {
        $this->seedCore();
        $e = JournalEntry::create(['doc_no' => 'JV-9003', 'date' => now()->toDateString(),
            'project_id' => $this->project()->id, 'state' => 'مسودة']);
        $acc = LedgerAccount::create(['code' => '4200', 'name' => 'نقدية', 'type' => 'أصل']);
        JournalLine::create(['entry_id' => $e->id, 'acc_id' => $acc->id, 'debit' => 250, 'credit' => 0]);
        JournalLine::create(['entry_id' => $e->id, 'acc_id' => $acc->id, 'debit' => 0, 'credit' => 250]);

        $this->actingAs($this->owner)->put('/m/entries/' . $e->id, [
            'no' => 'JV-9003', 'date' => now()->toDateString(),
            'projectId' => $this->project()->id, 'state' => 'مرحّل',
        ]);

        $this->assertSame('مرحّل', $e->fresh()->state,
            'الحارسُ يمنع المسارَ السليم — القيدُ الموزون يُرحَّل من نموذجه كما كان');
    }

    /* ── ٢) المرتجع لا يعكس ما أُلغي سلفاً ── */

    public function test_a_return_does_not_reverse_an_already_cancelled_receipt(): void
    {
        $this->seedCore();

        $item = StockItem::create(['name' => 'صنفُ الاختبار', 'sku' => 'SKU-1', 'qty' => 50, 'wh' => 'الرئيسي']);
        $p = Purchase::create(['doc_no' => 'PO-77', 'status' => 'مستلم', 'supplier' => 'موردٌ ما']);

        StockMove::$posting = true;
        try {
            $mv = StockMove::create(['doc_no' => 'IN-77', 'kind' => 'استلام', 'item_id' => $item->id,
                'qty' => 10, 'to_wh' => 'الرئيسي', 'date' => now()->toDateString(), 'status' => 'مسودة']);
        } finally {
            StockMove::$posting = false;
        }

        // استلامٌ يُرحَّل (50 → 60) ثمّ يُلغى (60 → 50) عبر المسارين الحقيقيين
        $this->actingAs($this->owner)->post('/stockmv/' . $mv->id . '/act', ['do' => 'confirm']);
        $this->assertSame(60.0, (float) $item->fresh()->qty, 'الترحيل رفع الرصيد — خطُّ الأساس');
        $this->actingAs($this->owner)->post('/stockmv/' . $mv->id . '/act', ['do' => 'cancel']);
        $this->assertSame(50.0, (float) $item->fresh()->qty, 'الإلغاء أعاد الرصيد — خطُّ الأساس');

        $p->meta = ['stock_moves' => [$mv->id]];
        $p->save();

        $this->actingAs($this->owner)->post('/purchase/' . $p->id . '/act', ['do' => 'return']);

        $this->assertSame(50.0, (float) $item->fresh()->qty,
            'المرتجعُ عكس استلاماً أُلغي من قبل — خصمٌ مضاعف بخّر مخزوناً حقيقياً');
    }

    public function test_a_return_still_reverses_a_live_receipt(): void
    {
        $this->seedCore();

        $item = StockItem::create(['name' => 'صنفٌ حيّ', 'sku' => 'SKU-2', 'qty' => 50, 'wh' => 'الرئيسي']);
        $p = Purchase::create(['doc_no' => 'PO-78', 'status' => 'مستلم', 'supplier' => 'موردٌ ما']);

        StockMove::$posting = true;
        try {
            $mv = StockMove::create(['doc_no' => 'IN-78', 'kind' => 'استلام', 'item_id' => $item->id,
                'qty' => 10, 'to_wh' => 'الرئيسي', 'date' => now()->toDateString(), 'status' => 'مسودة']);
        } finally {
            StockMove::$posting = false;
        }
        $this->actingAs($this->owner)->post('/stockmv/' . $mv->id . '/act', ['do' => 'confirm']);
        $this->assertSame(60.0, (float) $item->fresh()->qty);

        $p->meta = ['stock_moves' => [$mv->id]];
        $p->save();
        $this->actingAs($this->owner)->post('/purchase/' . $p->id . '/act', ['do' => 'return']);

        $this->assertSame(50.0, (float) $item->fresh()->qty,
            'المرتجعُ الحقيقيّ يجب أن يخصم — الحارسُ لا يعطّل المسار السليم');
    }

    /* ── ٣) زرُّ الترحيل لا يلتفّ على طابور الموافقات ── */

    public function test_stock_posting_respects_the_approval_queue(): void
    {
        $this->seedCore();
        $this->hubSetting('approval.rules', 'stockmv:e');

        $item = StockItem::create(['name' => 'صنفٌ محجوز', 'sku' => 'SKU-3', 'qty' => 20, 'wh' => 'الرئيسي']);
        StockMove::$posting = true;
        try {
            $mv = StockMove::create(['doc_no' => 'IN-79', 'kind' => 'استلام', 'item_id' => $item->id,
                'qty' => 5, 'to_wh' => 'الرئيسي', 'date' => now()->toDateString(), 'status' => 'مسودة']);
        } finally {
            StockMove::$posting = false;
        }

        $this->actingAs($this->employee)->post('/stockmv/' . $mv->id . '/act', ['do' => 'confirm']);

        $this->assertSame(20.0, (float) $item->fresh()->qty,
            'زرُّ الترحيل حرّك الرصيد متجاوزاً طابور الموافقات الذي يسدّه نموذج الوحدة');
    }

    /* ── ٤) سطرُ القيد لا يُعلَّق على حسابٍ خارج النطاق ── */

    public function test_a_journal_line_cannot_reference_an_out_of_scope_account(): void
    {
        $this->seedCore();

        $a = \App\Models\Company::create(['name_ar' => 'شركة أ']);
        $b = \App\Models\Company::create(['name_ar' => 'شركة ب']);
        $foreign = LedgerAccount::create(['code' => '4900', 'name' => 'مبيعات ب',
            'type' => 'إيراد', 'company_id' => $b->id]);

        $e = JournalEntry::create(['doc_no' => 'JV-9100', 'date' => now()->toDateString(),
            'project_id' => $this->project()->id, 'state' => 'مسودة', 'company_id' => $a->id]);

        // مستخدمٌ معزولٌ على الشركة أ
        $this->employee->forceFill(['companies' => [$a->id]])->save();
        session(['hub.company' => $a->id]);

        $this->actingAs($this->employee)->post('/entry/' . $e->id . '/line', [
            'accId' => $foreign->id, 'debit' => 100,
        ]);

        $this->assertSame(0, JournalLine::where('entry_id', $e->id)->count(),
            'سطرُ قيدٍ لشركة أ عُلِّق على حساب دفترِ شركة ب — تلوّثٌ مرجعيّ عابرٌ للشركات');
    }

    public function test_the_account_picker_is_scoped_to_the_readers_companies(): void
    {
        $this->seedCore();

        $a = \App\Models\Company::create(['name_ar' => 'شركة أ']);
        $b = \App\Models\Company::create(['name_ar' => 'شركة ب']);
        LedgerAccount::create(['code' => '4901', 'name' => 'حسابُ ب السرّي', 'type' => 'إيراد', 'company_id' => $b->id]);
        $e = JournalEntry::create(['doc_no' => 'JV-9101', 'date' => now()->toDateString(),
            'project_id' => $this->project()->id, 'state' => 'مسودة', 'company_id' => $a->id]);

        $this->employee->forceFill(['companies' => [$a->id]])->save();
        session(['hub.company' => $a->id]);

        $html = $this->actingAs($this->employee)->get('/m/entries/' . $e->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('حسابُ ب السرّي', $html,
            'قائمةُ الحسابات تعرض دليلَ شركةٍ أجنبية — تسريبٌ وبابُ ربطٍ بها');
    }
}
