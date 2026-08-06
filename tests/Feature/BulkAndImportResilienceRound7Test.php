<?php

namespace Tests\Feature;

use App\Models\StockItem;
use App\Models\StockMove;
use Tests\TestCase;

/**
 * الموجة السابعة — **حارسُ صفٍّ واحد يكسر الدفعةَ كلَّها ولا يقول شيئاً.**
 *
 * حرّاسُ النماذج (‏`StockMove` مثلاً) تُلقي `ValidationException` — وهي في
 * المسار الفرديّ صحيحةٌ تماماً: رسالةٌ تعود إلى النموذج. أمّا في **الحلقات**
 * فالإلقاءُ يقطع الحلقة في منتصفها:
 *
 *  ١) `ModuleController::bulk` — الحذفُ الجماعيّ: تحديدٌ من خمسة فيه سجلٌّ
 *     واحدٌ محروس يحذف الأوّلَين، ويقفز عن الثلاثة الباقية، ويعود بخطأ
 *     تحقّقٍ لا يذكر **ماذا حُذف وماذا لم يُحذف**. فالمستخدم يرى «فشل» بينما
 *     سجلّان ذهبا إلى السلّة فعلاً — والفرقُ بين «لم يقع شيء» و«وقع نصفُه»
 *     هو كلُّ الفرق حين يكون الفعلُ حذفاً.
 *  ٢) `ModuleController::bulk` — تغييرُ الحالة الجماعيّ: العلّةُ نفسُها.
 *  ٣) `ImportController::run` — الحلقةُ داخل `DB::transaction`، فصفٌّ يرفضه
 *     حارسُ النموذج **يُلغي الملفَّ كلَّه**، والملفُّ يُمحى في `finally`
 *     والجلسةُ تُنسى: ألفُ صفٍّ سليم تضيع لأجل صفٍّ واحد، ولا تقريرَ
 *     بالمتخطّى أصلاً — بينما كلُّ خطأٍ آخر في الصف (قيمةٌ غير رقمية،
 *     مرجعٌ مفقود) يُبلَّغ في قائمة «المتخطّى» ويمضي الاستيراد.
 *
 * والقاعدةُ المستخلَصة: **رفضُ الحارس خطأُ صفٍّ لا خطأُ دفعة** — يُحصى
 * ويُبلَّغ كأيّ خطأ صفٍّ آخر، ولا يُسقط ما حوله.
 */
class BulkAndImportResilienceRound7Test extends TestCase
{
    protected StockItem $item;

    protected function scene(): void
    {
        $this->seedCore();
        $this->item = StockItem::create(['name' => 'صنف الاختبار', 'sku' => 'SKU-R7', 'qty' => 100]);
    }

    /** حركةٌ مُرحَّلة: الحذفُ محروسٌ عليها (‏v2.334) */
    protected function posted(string $no): StockMove
    {
        return StockMove::create(['doc_no' => $no, 'kind' => 'استلام', 'item_id' => $this->item->id,
            'qty' => 5, 'date' => now()->toDateString(), 'status' => 'مسودة',
            'meta' => ['posted_at' => now()->toDateTimeString()]]);
    }

    /** حركةٌ مسودة: بلا حارس */
    protected function draft(string $no): StockMove
    {
        return StockMove::create(['doc_no' => $no, 'kind' => 'استلام', 'item_id' => $this->item->id,
            'qty' => 5, 'date' => now()->toDateString(), 'status' => 'مسودة']);
    }

    /* ── ١) الحذفُ الجماعيّ يتخطّى المحروسَ ويُتمّ الباقي ── */

    public function test_bulk_delete_skips_the_guarded_row_and_finishes_the_rest(): void
    {
        $this->scene();
        $a = $this->draft('A');
        $b = $this->posted('B');       // محروسة — لا تُحذف
        $c = $this->draft('C');

        $this->actingAs($this->owner)
            ->post('/m/stockmv/bulk', ['do' => 'delete', 'ids' => [$a->id, $b->id, $c->id]]);

        $this->assertNull(StockMove::whereKey($a->id)->first(), 'السجلُّ الأوّل كان يجب أن يُحذف');
        $this->assertNotNull(StockMove::whereKey($b->id)->first(),
            'الحركةُ المُرحَّلة حُذفت رغم الحارس — أثرُها على الرصيد بلا مستند');
        $this->assertNull(StockMove::whereKey($c->id)->first(),
            'السجلُّ الثالث نجا لأنّ الحارسَ على الثاني قطع الحلقة — '
            . 'فالدفعةُ تنكسر في منتصفها ولا أحد يعلم أين توقّفت');
    }

    public function test_bulk_delete_reports_what_it_could_not_delete(): void
    {
        $this->scene();
        $a = $this->draft('A');
        $b = $this->posted('B');

        $res = $this->actingAs($this->owner)
            ->post('/m/stockmv/bulk', ['do' => 'delete', 'ids' => [$a->id, $b->id]]);

        $res->assertSessionHasNoErrors();
        $msg = (string) (session('ok') . ' ' . session('err') . ' ' . session('warn'));
        $this->assertStringContainsString('تعذّر', $msg,
            'الحذفُ الجماعيّ لم يذكر أنّ سجلاً امتنع — المستخدم يقرأ «نُقل ١» '
            . 'ويظنّ الثانيَ ذهب معه، أو يقرأ خطأً ويظنّ أنّ شيئاً لم يقع');
    }

    /* ── ٢) تغييرُ الحالة الجماعيّ كذلك ── */

    public function test_bulk_status_skips_the_guarded_row_and_finishes_the_rest(): void
    {
        $this->scene();
        $a = $this->posted('A');       // مُرحَّلة: «مؤكدة» مشروعةٌ لها
        $b = $this->draft('B');        // مسودة: الحارسُ يمنع تأكيدَها يدويّاً
        $c = $this->posted('C');

        $this->actingAs($this->owner)
            ->post('/m/stockmv/bulk', ['do' => 'status', 'status' => 'مؤكدة',
                'ids' => [$a->id, $b->id, $c->id]]);

        $this->assertSame('مؤكدة', (string) $a->fresh()->status);
        $this->assertSame('مسودة', (string) $b->fresh()->status,
            'المسودةُ تأكّدت يدويّاً رغم الحارس');
        $this->assertSame('مؤكدة', (string) $c->fresh()->status,
            'السجلُّ الثالث لم يتغيّر لأنّ الحارسَ على الثاني قطع الحلقة');
    }

    /* ── ٣) الاستيراد: رفضُ الحارس خطأُ صفٍّ لا خطأُ ملف ── */

    public function test_a_guard_rejection_skips_one_row_not_the_whole_file(): void
    {
        $this->scene();

        $csv = "نوع الحركة,الصنف,الكمية,التاريخ,الحالة\n"
             . 'استلام,' . $this->item->id . ",3," . now()->toDateString() . ",مسودة\n"
             . 'استلام,' . $this->item->id . ",4," . now()->toDateString() . ",مؤكدة\n"
             . 'استلام,' . $this->item->id . ",5," . now()->toDateString() . ",مسودة\n";

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('moves.csv', $csv);

        $this->actingAs($this->owner)->post('/m/stockmv/import', ['file' => $file])->assertOk();

        $res = $this->actingAs($this->owner)->post('/m/stockmv/import/run',
            ['map' => [0 => 'kind', 1 => 'itemId', 2 => 'qty', 3 => 'date', 4 => 'status']]);

        $res->assertOk();
        $res->assertViewHas('ok', 2);
        $this->assertSame(2, StockMove::count(),
            'صفٌّ واحدٌ رفضه حارسُ النموذج أسقط الملفَّ كلَّه — والملفُّ مُحي '
            . 'والجلسةُ نُسيت، فالصفوفُ السليمة ضاعت بلا سبيلٍ لاستعادتها');

        $skipped = $res->viewData('skipped');
        $this->assertCount(1, $skipped);
        $this->assertStringContainsString('صف 3', (string) $skipped[0],
            'المتخطّى لا يُسمّي الصفَّ الذي رُفض');
    }
}
