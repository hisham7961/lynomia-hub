<?php

namespace Tests\Feature;

use App\Support\Platform\SchemaCache;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * **خريطةُ الأعمدة المخبوءة** (TECH_DEBT #25 · PERF-05).
 *
 * تُثبت الحزمةُ الوجهين معاً — لأنّ خبيئةً سريعةً تكذب أسوأُ من استبطانٍ بطيءٍ صادق:
 *
 *   · **السرعة**: جدولٌ واحدٌ يُستبطَن مرّةً واحدة مهما سُئل عن أعمدته، ولا استعلامَ
 *     مخطّطٍ بعدها؛ وعمليّةٌ أخرى (طلبٌ تالٍ في FPM) تقرأ الخريطةَ من الخبيئة المشتركة.
 *   · **الصدق**: كلُّ DDL في العمليّة (إضافةُ عمود/إسقاطُه/إسقاطُ جدول) يُرى فوراً،
 *     و`MigrationsEnded` يُفرّغها، ورفعُ الجيل يُسقط خرائطَ العمليّات الأخرى.
 */
class SchemaCacheTest extends TestCase
{
    /** عدّادُ استعلامات المخطّط الحقيقيّة على المحرّكين */
    private function countSchemaQueries(): \Closure
    {
        $n = 0;
        DB::listen(function ($q) use (&$n) {
            $sql = mb_strtolower($q->sql);
            if (str_contains($sql, 'pragma') || str_contains($sql, 'information_schema')) $n++;
        });

        return function () use (&$n) { return $n; };
    }

    public function test_it_tells_the_truth_like_schema_has_column(): void
    {
        $this->assertTrue(SchemaCache::hasColumn('tasks', 'title'));
        $this->assertTrue(SchemaCache::hasColumn('tasks', 'TITLE'), 'حساسيّةُ الحالة تخالف Schema::hasColumn');
        $this->assertFalse(SchemaCache::hasColumn('tasks', 'عمودٌ_لا_وجود_له'));
        $this->assertFalse(SchemaCache::hasColumn('جدولٌ_لا_وجود_له', 'id'));
        $this->assertTrue(SchemaCache::hasTable('tasks'));
        $this->assertFalse(SchemaCache::hasTable('جدولٌ_لا_وجود_له'));
        $this->assertTrue(SchemaCache::hasColumns('tasks', ['id', 'title', 'deleted_at']));
        $this->assertFalse(SchemaCache::hasColumns('tasks', ['id', 'لا_وجود']));

        // والمطابقةُ الكاملة مع الاستبطان الحيّ على كلِّ عمودٍ في جدولٍ حقيقيّ
        foreach (Schema::getColumnListing('clients') as $c) {
            $this->assertSame(Schema::hasColumn('clients', $c), SchemaCache::hasColumn('clients', $c), "clients.{$c}");
        }
    }

    public function test_one_introspection_per_table_however_many_columns_are_asked(): void
    {
        SchemaCache::flush();
        $queries = $this->countSchemaQueries();
        $before = SchemaCache::introspections();

        foreach (['id', 'title', 'status', 'deleted_at', 'project_id', 'assignee_id', 'لا_وجود'] as $c) {
            for ($i = 0; $i < 5; $i++) SchemaCache::hasColumn('tasks', $c);
        }
        hub_has_col('tasks', 'title');   // الحارسُ القائمُ يمرّ بالخريطة نفسِها

        $this->assertSame(1, SchemaCache::introspections() - $before, 'الجدولُ استُبطن أكثرَ من مرّة');
        $first = $queries();

        for ($i = 0; $i < 20; $i++) SchemaCache::hasColumn('tasks', 'title');
        $this->assertSame($first, $queries(), 'استعلامُ مخطّطٍ بعد تدفئة الخريطة');
    }

    /** عمليّةٌ أخرى (طلبٌ تالٍ في FPM تُصفَّر ساكنتُه) تقرأ الخريطةَ من الخبيئة المشتركة */
    public function test_a_fresh_process_reuses_the_shared_map(): void
    {
        SchemaCache::flush();
        SchemaCache::hasColumn('tasks', 'title');
        $before = SchemaCache::introspections();

        SchemaCache::flush(false);   // «عمليّةٌ جديدة»: الساكنةُ تُصفَّر والخبيئةُ المشتركة باقية
        $this->assertTrue(SchemaCache::hasColumn('tasks', 'title'));
        $this->assertSame($before, SchemaCache::introspections(), 'العمليّةُ الجديدة لم تنتفع بالخبيئة المشتركة');

        SchemaCache::flush();        // رفعُ الجيل (هجرةٌ في عمليّةٍ أخرى) يُسقط الخريطةَ المشتركة
        SchemaCache::hasColumn('tasks', 'title');
        $this->assertSame($before + 1, SchemaCache::introspections(), 'رفعُ الجيل لم يُسقط الخريطةَ القديمة');
    }

    /** DDL في العمليّة نفسِها يُرى فوراً — إضافةً وإسقاطاً — لا بعد خمس دقائق */
    public function test_ddl_in_this_process_is_seen_immediately(): void
    {
        $this->assertFalse(SchemaCache::hasColumn('tasks', 'zz_probe'));

        Schema::table('tasks', fn ($t) => $t->string('zz_probe', 20)->nullable());
        $this->assertTrue(SchemaCache::hasColumn('tasks', 'zz_probe'), 'العمودُ المضاف لا يُرى — الخبيئةُ تكذب');
        $this->assertTrue(hub_has_col('tasks', 'zz_probe'));

        Schema::table('tasks', fn ($t) => $t->dropColumn('zz_probe'));
        $this->assertFalse(SchemaCache::hasColumn('tasks', 'zz_probe'), 'العمودُ المُسقط ما زال «موجوداً»');

        // جدولٌ يُنشأ ويُسقط (ذاتيُّ الترميم: DDL على MySQL يُلزم المعاملةَ فلا يُمسّ جدولٌ حقيقيّ)
        $this->assertFalse(SchemaCache::hasTable('zz_probe_tbl'));
        Schema::create('zz_probe_tbl', fn ($t) => $t->id());
        $this->assertTrue(SchemaCache::hasTable('zz_probe_tbl'), 'الجدولُ المُنشأ لا يُرى');
        Schema::drop('zz_probe_tbl');
        $this->assertFalse(SchemaCache::hasTable('zz_probe_tbl'), 'الجدولُ المُسقط ما زال «موجوداً»');
    }

    /** نهايةُ الهجرات تُفرّغ الخريطة (الهجرةُ قد تمرّ بلا DDL يلتقطه المستمع — `--pretend` مثلاً) */
    public function test_migrations_ended_flushes_the_map(): void
    {
        SchemaCache::hasColumn('tasks', 'title');
        $before = SchemaCache::introspections();

        Event::dispatch(new MigrationsEnded('up'));
        SchemaCache::hasColumn('tasks', 'title');

        $this->assertSame($before + 1, SchemaCache::introspections(), 'MigrationsEnded لم يُفرّغ الخريطة');
    }

    /** استعلامُ البيانات لا يُفرّغ شيئاً — المستمعُ لا يُفسد الخبيئةَ بتفريغٍ في كلِّ طلب */
    public function test_plain_data_queries_do_not_flush(): void
    {
        $this->seedCore();
        SchemaCache::hasColumn('tasks', 'title');
        $before = SchemaCache::introspections();

        DB::table('tasks')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'title' => 'create table x', 'status' => 'جديدة']);
        DB::table('tasks')->where('title', 'create table x')->update(['status' => 'منجزة']);
        DB::table('tasks')->count();
        SchemaCache::hasColumn('tasks', 'title');

        $this->assertSame($before, SchemaCache::introspections(), 'استعلامُ بياناتٍ أسقط الخريطة');
    }
}
