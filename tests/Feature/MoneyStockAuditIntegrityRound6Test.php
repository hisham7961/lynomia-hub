<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\BankAccount;
use App\Models\Contract;
use App\Models\ContractObligation;
use App\Models\FinDocument;
use App\Models\StockItem;
use App\Models\StockMove;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ١٨: **حارسٌ خارج القفل، وأثرٌ لا يُعكس، وسلسلةٌ
 * تُبلّغ سلامةً وهي مقطوعة.**
 *
 *  ١) `FinController::pay:30` — فحصُ الحالة الميتة **قبل** المعاملة والقفل:
 *     نافذةُ سباقٍ يُلغى فيها المستندُ بين الفحص والدفع، فتُسجَّل دفعةٌ ويتحرّك
 *     رصيدُ بنكٍ ويُرحَّل قيدٌ على مستندٍ ملغى.
 *  ٢) `FinController:153` — `doc_no` القيد الآليّ مشتقٌّ من `doc_no` المستند
 *     (‏٣٠٠ محرفاً) ببادئةٍ ولاحقة: يتجاوز عرضَ عموده فيرمي MySQL 1406 —
 *     **والاستثناءُ مُبتلَع**، فالدفعةُ تنجح والقيدُ لا يُكتب: دفترٌ ناقص صامت.
 *  ٣) `Contract::deleting:50` — إلغاءُ التزامات العقد خارج أي معاملة: فشلُ
 *     الحذف بعدها يترك التزاماتٍ ملغاةً لعقدٍ قائم.
 *  ٤) `StockMove` — الحارسُ على `creating`/`updating` وحدهما: حركةٌ **مُرحَّلة**
 *     تُحذف من النموذج العام بلا عكسِ أثرها، فيبقى الرصيدُ متحرّكاً بلا مستند.
 *  ٥) `Audit::verifyTail:110` — تفحص بصمةَ كل قيدٍ ولا تفحص **الوصلَ** بينها:
 *     حذفُ قيدٍ من المنتصف يُبلَّغ «سلسلة سليمة» — وهذا بالضبط ما تُصان
 *     السلسلةُ من أجله.
 *  ٦) `SettingController::update:112` — `validate` داخل الحلقة **يرمي**، فما
 *     كُتب قبله يبقى بلا `Cache::forget` ولا أثرِ تدقيق: الشاشةُ تعرض القديم
 *     والسجلُّ لا يعرف من غيّر.
 */
class MoneyStockAuditIntegrityRound6Test extends TestCase
{
    /* ── ١) الحالةُ الميتة تُفحص داخل القفل ── */

    public function test_a_document_cancelled_mid_flight_takes_no_payment(): void
    {
        $this->seedCore();

        $doc = FinDocument::create(['doc_no' => 'INV-RACE', 'kind' => 'فاتورة مبيعات',
            'total' => 1000, 'paid' => 0, 'state' => 'مرسلة', 'date' => now()->toDateString()]);

        // محاكاةُ السباق حرفيّاً: الطلبُ يقرأ المستند (فيرى «مرسلة» ويمرّ الحارسَ
        // الخارجيّ)، ثمّ **يُلغى الصفُّ في القاعدة** قبل أن يبلغ القفلَ والكتابة.
        // خطّافُ `retrieved` يقع بعد التحميل، فالنموذجُ المحمَّل يبقى «مرسلة».
        $fired = false;
        FinDocument::retrieved(function ($m) use (&$fired, $doc) {
            if ($fired || (string) $m->id !== (string) $doc->id) return;
            $fired = true;
            DB::table('fin_documents')->where('id', $doc->id)->update(['state' => 'ملغاة']);
        });

        $this->actingAs($this->owner)->post("/fin/{$doc->id}/act", ['do' => 'pay', 'amount' => 100]);

        $fresh = $doc->fresh();
        $this->assertSame(0.0, (float) ($fresh->paid ?? 0),
            'سُجّلت دفعةٌ على مستندٍ أُلغي بين الفحص والكتابة — الحارسُ خارج القفل '
            . 'فنافذةُ السباق مفتوحة، ورصيدُ البنك يتحرّك والقيدُ يُرحَّل');
    }

    /* ── ٢) قيدُ الدفعة الآليّ يُكتب مهما طال رقمُ المستند ── */

    public function test_the_auto_journal_is_written_even_for_a_long_document_number(): void
    {
        $this->seedCore();
        $this->hubSetting('finance.auto_journal', '1');
        $this->hubSetting('finance.accounts', json_encode(['cash' => '1010', 'sales' => '4010']));

        DB::table('ledger_accounts')->insert([
            ['id' => (string) Str::uuid(), 'code' => '1010', 'name' => 'الصندوق', 'type' => 'أصول',
             'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'code' => '4010', 'name' => 'إيرادات', 'type' => 'إيرادات',
             'created_at' => now(), 'updated_at' => now()],
        ]);

        // ‏`fin_documents.doc_no` عمودٌ بعرض ٣٠٠، و`journal_entries.doc_no` كذلك —
        // فالمشتقُّ «JE-{٣٠٠ محرفاً}-{وقت}» يتجاوز عمودَه حتماً
        $long = str_repeat('X', 295);   // ‏JE- + ٢٩٥ + - + ٦ = ٣٠٥ > ٣٠٠
        $doc = FinDocument::create(['doc_no' => $long, 'kind' => 'فاتورة مبيعات',
            'total' => 500, 'paid' => 0, 'state' => 'مرسلة', 'date' => now()->toDateString()]);

        $this->actingAs($this->owner)->post("/fin/{$doc->id}/act", ['do' => 'pay', 'amount' => 500]);

        $this->assertSame(500.0, (float) $doc->fresh()->paid, 'الدفعةُ نفسُها لم تُسجَّل');

        // على MySQL يرمي 1406 فلا يُكتب القيد أصلاً، وعلى SQLite يُكتب برقمٍ
        // أطولَ من عموده — والتأكيدان معاً يفضحان العيبَ على المحرّكين
        $entry = \App\Models\JournalEntry::where('fin_id', $doc->id)->first();
        $this->assertNotNull($entry,
            'الدفعةُ نجحت والقيدُ لم يُكتب — المشتقُّ يتجاوز عرضَ عموده فيرمي 1406 '
            . 'والاستثناءُ مُبتلَع: دفترٌ ناقص صامت');
        $this->assertLessThanOrEqual(hub_col_max('journal_entries', 'doc_no') ?? 300,
            mb_strlen((string) $entry->doc_no),
            'رقمُ القيد المشتقّ أطولُ من عموده — يمرّ على SQLite ويُسقط MySQL');
    }

    /* ── ٣) حذفُ العقد وإلغاءُ التزاماته صفقةٌ واحدة ── */

    public function test_deleting_a_contract_cancels_its_obligations_inside_a_transaction(): void
    {
        $this->seedCore();

        $c = Contract::create(['title' => 'عقدٌ بالتزامات', 'type' => 'عقد عميل', 'status' => 'ساري']);
        ContractObligation::create(['contract_id' => $c->id, 'title' => 'التزامٌ قائم',
            'status' => 'قائم', 'due' => now()->addDays(10)->toDateString()]);

        // حزمةُ الاختبار نفسُها تلفّ كلَّ اختبارٍ في معاملة — فالقياسُ **فرقٌ**
        // لا قيمةٌ مطلقة، وإلا مرّ الاختبارُ فراغاً
        $base = DB::transactionLevel();
        $level = null;
        Contract::deleting(function () use (&$level) { $level = DB::transactionLevel(); });

        $c->delete();

        $this->assertNotNull($level, 'الخطّافُ لم يُستدعَ — الاختبارُ يقيس فراغاً');
        $this->assertGreaterThan($base, $level,
            'إلغاءُ التزامات العقد يقع خارج أي معاملة — فشلُ الحذف بعده يترك التزاماتٍ '
            . 'ملغاةً لعقدٍ قائم، ولا شيء يُرجعها');
    }

    /* ── ٤) الحركةُ المُرحَّلة لا تُحذف بلا عكسٍ ── */

    public function test_a_posted_stock_move_cannot_be_deleted_without_reversal(): void
    {
        $this->seedCore();

        $item = StockItem::create(['name' => 'صنفٌ مرحَّل', 'qty' => 100]);
        $mv = StockMove::create(['item_id' => $item->id, 'kind' => 'إدخال', 'qty' => 10,
            'date' => now()->toDateString(), 'status' => 'مسودة']);
        // ترحيلٌ بالطريق المشروع: العلمُ يرفع الحارس كما يفعل زرُّ الترحيل
        StockMove::$posting = true;
        $mv->forceFill(['status' => 'مؤكدة', 'meta' => ['posted_at' => now()->toIso8601String()]])->save();
        StockMove::$posting = false;

        $res = $this->actingAs($this->owner)->delete("/m/stockmv/{$mv->id}");

        // الحذفُ الناعم يُخرج الصفَّ من الاستعلام الافتراضي — فالتأكيدُ على
        // **بقائه ظاهراً** لا على `deleted_at` (وإلا مرّ الاختبارُ فراغاً)
        $this->assertTrue(StockMove::whereKey($mv->id)->exists(),
            'حركةٌ مُرحَّلة حُذفت بلا عكسِ أثرها — الرصيدُ تحرّك ولا مستندَ خلفه، '
            . 'وحارسُ التعديل قائمٌ بينما الحذفُ مفتوح (الرد: ' . $res->getStatusCode() . ')');
    }

    /* ── ٥) فحصُ الذيل يكشف قيداً محذوفاً من المنتصف ── */

    public function test_the_chain_tail_check_detects_a_deleted_middle_entry(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        for ($i = 0; $i < 6; $i++) {
            hub_audit('إجراءٌ ' . $i, 'clients', null, 'وصف');
        }

        $ids = AuditEntry::whereNotNull('hash')->orderBy('id')->pluck('id');
        $this->assertGreaterThanOrEqual(6, $ids->count(), 'السلسلةُ لم تُبنَ — الاختبارُ يقيس فراغاً');

        // حذفٌ من **المنتصف**: الرأسُ يبقى مطابقاً وبصمةُ كل قيدٍ باقٍ صحيحة —
        // ولا يكشفه إلا فحصُ الوصل
        DB::table('audits')->where('id', $ids[2])->delete();

        $v = \App\Support\Audit::verifyTail();
        $this->assertFalse($v['ok'],
            'حُذف قيدٌ من منتصف السلسلة والفحصُ يقول «سليمة» — يفحص بصمةَ كل قيدٍ '
            . 'ولا يفحص الوصلَ بينها، وهذا بالضبط ما تُصان السلسلةُ من أجله');
    }

    /* ── ٦) صورةٌ مرفوضة لا تُضيّع ما حُفظ قبلها ── */

    public function test_a_rejected_image_does_not_discard_the_settings_written_before_it(): void
    {
        $this->seedCore();

        $bad = \Illuminate\Http\UploadedFile::fake()->create('payload.svg', 8, 'image/svg+xml');

        $this->actingAs($this->owner)->post('/admin/settings', [
            'app_currency' => 'دولار',
            'app_logo' => $bad,
        ]);

        $this->assertSame('دولار', (string) setting('app.currency'),
            'قيمةٌ صحيحة ضاعت لأن صورةً بعدها رُفضت — التحقّقُ يرمي من داخل الحلقة '
            . 'فلا يُنسَف الكاش ولا يُكتب أثرُ التدقيق، والشاشةُ تعرض القديم');
        $this->assertSame(0, \App\Models\Setting::where('key', 'app.logo')->count(),
            'صورةٌ مرفوضة كُتبت رغم رفضها');
    }
}
