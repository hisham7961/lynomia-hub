<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Support\Platform\Audit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AUD-07 — قفلُ رأسِ سلسلة التدقيق لا يُحمَل طوالَ معاملة العمل.
 *
 * كان `creating` يأخذ `lockForUpdate` على `audit_chain` — فإن وقع القيدُ داخل
 * معاملةِ عملٍ بقي القفلُ حتى تلتزم كلُّها، فتصطفّ خلفه كلُّ كتابةٍ مُدقَّقة.
 * الآن: داخل المعاملة يُدرَج القيدُ بلا بصمة، ويُختم بعد الالتزام في خطوةٍ قصيرة.
 * والسلسلةُ تبقى متّصلةً قابلةً للتحقّق — وترتيبُها ترتيبُ الالتزام.
 */
class AuditDeferredSealTest extends TestCase
{
    protected function chainHead(): string
    {
        return (string) DB::table('audit_chain')->where('id', 1)->value('head');
    }

    public function test_an_audit_inside_a_business_transaction_does_not_touch_the_chain_head_until_commit(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        hub_audit('قيدٌ سابق', 'hr', null, 'قبل المعاملة');
        $headBefore = $this->chainHead();

        $chainQueries = null; $inside = null;
        DB::transaction(function () use (&$chainQueries, &$inside, $headBefore) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $entry = hub_audit('قيدٌ داخل معاملة عمل', 'hr', null, 'داخل المعاملة');
            $log = DB::getQueryLog();
            DB::disableQueryLog();

            // لا استعلامَ واحدَ يلمس جدولَ الرأس داخل معاملة العمل — فلا قفلَ يُحمَل
            $chainQueries = array_values(array_filter($log, fn ($q) => str_contains($q['query'], 'audit_chain')));
            $inside = DB::table('audits')->where('id', $entry->id)->first(['hash', 'prev_hash']);
            $this->assertSame($headBefore, $this->chainHead(), 'تقدّم الرأسُ قبل التزام المعاملة');
        });

        $this->assertSame([], $chainQueries, 'قفلُ الرأس أُخذ داخل معاملة العمل: '
            . implode(' ؛ ', array_column((array) $chainQueries, 'query')));
        $this->assertNotNull($inside, 'القيدُ لم يُدرَج ذرّةً مع العمل');
        $this->assertNull($inside->hash, 'القيدُ خُتم داخل المعاملة — أي أنّ القفلَ أُخذ فيها');

        // بعد الالتزام: مختومٌ وموصولٌ بالرأس السابق، والرأسُ عليه
        $row = AuditEntry::where('action', 'قيدٌ داخل معاملة عمل')->orderBy('id')->first();
        $this->assertNotNull($row->hash, 'القيدُ لم يُختم بعد الالتزام');
        $this->assertSame($headBefore, $row->prev_hash);
        $this->assertSame($row->hash, $this->chainHead());

        $this->assertSame(0, Artisan::call('hub:audit-verify'), Artisan::output());
        $tail = Audit::verifyTail();
        $this->assertSame('ok', $tail['state'], $tail['why']);
    }

    public function test_the_returned_entry_reflects_its_seal_after_commit(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $entry = DB::transaction(fn () => hub_audit('قيدٌ يُقرأ بعد الالتزام', 'hr', null, 'س'));

        $this->assertNotNull($entry->hash);
        $this->assertSame($entry->hash, $this->chainHead());
        $this->assertFalse($entry->isDirty('hash'), 'البصمةُ في الذاكرة ليست مطابقةً للمخزون');
    }

    public function test_a_rolled_back_transaction_leaves_no_entry_and_no_seal(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        hub_audit('قيدٌ سليم', 'hr', null, 'الأول');
        $headBefore = $this->chainHead();

        try {
            DB::transaction(function () {
                hub_audit('قيدٌ في معاملةٍ ترتدّ', 'hr', null, 'يرتدّ');
                throw new \RuntimeException('فشل العمل');
            });
        } catch (\RuntimeException $e) {
            // متوقّع
        }

        $this->assertSame(0, AuditEntry::where('action', 'قيدٌ في معاملةٍ ترتدّ')->count(),
            'ارتدّت المعاملةُ وبقي قيدُها');
        $this->assertSame($headBefore, $this->chainHead(), 'تقدّم الرأسُ لقيدٍ ارتدّ — «عبثٌ» كاذبٌ دائم');
        $this->assertSame(0, Artisan::call('hub:audit-verify'), Artisan::output());
        $this->assertSame('ok', Audit::verifyTail()['state']);
    }

    public function test_entries_of_one_transaction_are_sealed_in_insert_order(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $ids = DB::transaction(fn () => [
            hub_audit('دفعة ١', 'hr', null, '١')->id,
            hub_audit('دفعة ٢', 'hr', null, '٢')->id,
            hub_audit('دفعة ٣', 'hr', null, '٣')->id,
        ]);

        // كلُّ قيدٍ من الثلاثة يُفحص — لا واحدٌ منها
        $rows = AuditEntry::whereIn('id', $ids)->orderBy('id')->get()->values();
        $this->assertCount(3, $rows);
        foreach ($rows as $i => $row) {
            $this->assertNotNull($row->hash, "القيد {$row->id} لم يُختم");
            if ($i > 0) $this->assertSame($rows[$i - 1]->hash, $row->prev_hash, 'ترتيبُ الختم خالف ترتيبَ الإدراج');
        }
        $this->assertSame($rows[2]->hash, $this->chainHead());
        $this->assertSame(0, Artisan::call('hub:audit-verify'), Artisan::output());
    }

    /**
     * **ترتيبُ الالتزام لا ترتيبُ `id`.** معاملتان متزامنتان: الأولى أدرجت قيدَها
     * أولاً (id أصغر) والتزمت آخراً — فيسبقه في السلسلة قيدٌ أحدثُ منه `id`.
     * يُحاكى بختم القيدين عكسَ ترتيب إدراجهما بالخطوة نفسِها التي تُنفَّذ بعد الالتزام.
     */
    public function test_a_chain_sealed_in_commit_order_not_id_order_still_verifies_and_still_detects_tampering(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        hub_audit('قيدٌ أوّل', 'hr', null, 'أ');

        [$early, $late] = DB::transaction(function () {
            $early = hub_audit('أُدرج أولاً والتزم آخراً', 'hr', null, 'ب');
            $late  = hub_audit('أُدرج آخراً والتزم أولاً', 'hr', null, 'ج');
            AuditEntry::sealCommitted($late);
            AuditEntry::sealCommitted($early);

            return [$early, $late];
        });

        $this->assertLessThan($late->id, $early->id);
        $this->assertSame($late->hash, (string) DB::table('audits')->where('id', $early->id)->value('prev_hash'),
            'المحاكاةُ لم تُنتج سلسلةً بترتيبٍ يخالف id');
        $this->assertSame($early->hash, $this->chainHead());

        $this->assertSame(0, Artisan::call('hub:audit-verify'), Artisan::output());
        $tail = Audit::verifyTail();
        $this->assertSame('ok', $tail['state'], 'إنذارٌ كاذب على سلسلةٍ سليمة بترتيب الالتزام: ' . $tail['why']);

        // والعبثُ ما زال يُكشف: حذفُ الحلقة الوسطى يقطع المشي من الرأس
        DB::table('audits')->where('id', $late->id)->delete();
        $this->assertSame('bad', Audit::verifyTail()['state'], 'حذفُ قيدٍ من وسط السلسلة مرّ بلا إنذار');
        $this->assertSame(1, Artisan::call('hub:audit-verify'));
    }

    public function test_outside_any_transaction_the_seal_stays_immediate(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $entry = hub_audit('قيدٌ خارج المعاملات', 'hr', null, 'فوري');

        $this->assertNotNull(DB::table('audits')->where('id', $entry->id)->value('hash'));
        $this->assertSame($entry->hash, $this->chainHead());
    }
}
