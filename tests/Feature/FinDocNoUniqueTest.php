<?php

namespace Tests\Feature;

use App\Models\FinDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * تدقيقٌ عميق — تفرّدُ رقم المستند الماليّ (بإذنٍ صريح، وترحيلٌ مأمونُ البيانات).
 *
 * رقمُ المستند مُدخَلٌ يدوياً بلا فهرسٍ فريد، فقد يكتب مستخدمان الرقمَ نفسَه.
 * الآن: فهرسٌ فريدٌ يمنع التصادم، و`save()` يولّد بديلاً عند السباق بدل الـ٥٠٠،
 * والفراغُ NULL يسمح بعدّة مستنداتٍ بلا رقمٍ بعدُ.
 */
class FinDocNoUniqueTest extends TestCase
{
    public function test_unique_index_exists_on_doc_no(): void
    {
        $this->seedCore();
        $indexes = collect(Schema::getIndexes('fin_documents'));
        $unique = $indexes->first(fn ($i) => in_array('doc_no', $i['columns']) && $i['unique']);
        $this->assertNotNull($unique, 'لا فهرسَ فريدٌ على doc_no');
    }

    public function test_raw_duplicate_doc_no_is_rejected_by_db(): void
    {
        $this->seedCore();
        $no = 'INV-TEST-9001';
        FinDocument::create(['doc_no' => $no, 'kind' => 'مصروف', 'total' => 100, 'date' => now()->toDateString()]);

        // إدراجٌ خامٌ يتجاوز `save()` — الفهرسُ الفريدُ يردّه
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('fin_documents')->insert([
            'id' => (string) Str::uuid(), 'doc_no' => $no, 'kind' => 'مصروف',
            'total' => 200, 'date' => now()->toDateString(),
        ]);
    }

    public function test_save_regenerates_on_doc_no_collision(): void
    {
        $this->seedCore();
        $no = 'INV-TEST-9002';
        $a = FinDocument::create(['doc_no' => $no, 'kind' => 'مصروف', 'total' => 100, 'date' => now()->toDateString()]);

        // نفسُ الرقم عبر النموذج: `save()` يلتقط التصادمَ ويولّد بديلاً
        $b = FinDocument::create(['doc_no' => $no, 'kind' => 'مصروف', 'total' => 200, 'date' => now()->toDateString()]);

        $this->assertSame($no, $a->doc_no);
        $this->assertNotSame($no, $b->doc_no, 'الرقمُ المكرّرُ لم يُستبدَل — سيسقط الطلبُ بـ٥٠٠');
        $this->assertNotNull($b->doc_no);
    }

    public function test_null_doc_no_allows_multiple(): void
    {
        $this->seedCore();
        // الفراغُ NULL: عدّةُ مستنداتٍ بلا رقمٍ بعدُ لا تتصادم
        $a = FinDocument::create(['doc_no' => null, 'kind' => 'مصروف', 'total' => 10, 'date' => now()->toDateString()]);
        $b = FinDocument::create(['doc_no' => null, 'kind' => 'مصروف', 'total' => 20, 'date' => now()->toDateString()]);
        $this->assertNull($a->doc_no);
        $this->assertNull($b->doc_no);
        $this->assertNotSame($a->id, $b->id);
    }

    /**
     * **إثباتُ ترحيلِ إزالة التكرار**: يُحاكى الوضعُ قبل الفهرس (تكرارٌ خامٌ) ثم
     * يُعاد تشغيلُ الترحيل، فيُثبَت: الأقدمُ يبقى برقمه، والأحدثُ يُوسَم مع حفظِ
     * الرقم الأصليّ في meta، والفهرسُ يُعاد. (يمسّ بياناتٍ ماليةً فيُختبَر لا يُدّعى.)
     */
    public function test_dedup_migration_keeps_oldest_and_suffixes_the_rest(): void
    {
        $this->seedCore();

        // إسقاطُ الفهرس كي تُحاكى قاعدةٌ قبل التفرّد
        Schema::table('fin_documents', function ($t) {
            $t->dropUnique('fin_documents_doc_no_unique');
        });

        $no = 'DUP-2026-1';
        $old = (string) Str::uuid(); $mid = (string) Str::uuid(); $new = (string) Str::uuid();
        DB::table('fin_documents')->insert([
            ['id' => $old, 'doc_no' => $no, 'kind' => 'مصروف', 'total' => 1, 'date' => '2026-01-01', 'created_at' => '2026-01-01 08:00:00'],
            ['id' => $mid, 'doc_no' => $no, 'kind' => 'مصروف', 'total' => 2, 'date' => '2026-02-01', 'created_at' => '2026-02-01 08:00:00'],
            ['id' => $new, 'doc_no' => $no, 'kind' => 'مصروف', 'total' => 3, 'date' => '2026-03-01', 'created_at' => '2026-03-01 08:00:00'],
        ]);
        // فراغٌ يجب أن يُوحَّد إلى NULL
        $blank = (string) Str::uuid();
        DB::table('fin_documents')->insert([
            ['id' => $blank, 'doc_no' => '', 'kind' => 'مصروف', 'total' => 4, 'date' => '2026-01-01', 'created_at' => '2026-01-01 09:00:00'],
        ]);

        // إعادةُ تشغيل الترحيل نفسِه
        $file = glob(database_path('migrations/*fin_doc_no_unique.php'))[0];
        $migration = require $file;
        $migration->up();

        // الأقدمُ يبقى برقمه؛ الأحدثان يُوسَمان بلاحقةٍ ظاهرة
        $this->assertSame($no, DB::table('fin_documents')->where('id', $old)->value('doc_no'));
        $this->assertSame($no . '-2', DB::table('fin_documents')->where('id', $mid)->value('doc_no'));
        $this->assertSame($no . '-3', DB::table('fin_documents')->where('id', $new)->value('doc_no'));

        // الرقمُ الأصليُّ محفوظٌ في meta للتتبّع
        $meta = json_decode((string) DB::table('fin_documents')->where('id', $mid)->value('meta'), true);
        $this->assertSame($no, $meta['doc_no_original'] ?? null);

        // الفراغُ صار NULL
        $this->assertNull(DB::table('fin_documents')->where('id', $blank)->value('doc_no'));

        // الفهرسُ أُعيد، فلا تكرارَ بعد اليوم
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('fin_documents')->insert([
            'id' => (string) Str::uuid(), 'doc_no' => $no, 'kind' => 'مصروف', 'total' => 9, 'date' => '2026-04-01',
        ]);
    }

    public function test_next_doc_no_is_sequential_and_unique(): void
    {
        $this->seedCore();
        $year = now()->format('Y');
        $this->assertSame("INV-{$year}-0001", FinDocument::nextDocNo());
        FinDocument::create(['doc_no' => FinDocument::nextDocNo(), 'kind' => 'مصروف', 'total' => 5, 'date' => now()->toDateString()]);
        $this->assertSame("INV-{$year}-0002", FinDocument::nextDocNo());
    }
}
