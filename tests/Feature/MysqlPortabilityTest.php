<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Client;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * انحدارات «يعمل على sqlite ويكسر على MySQL».
 * لا نملك MySQL في الاختبارات، فنحاكي سلوكه الفارق: إعادة صياغة وثائق JSON.
 */
class MysqlPortabilityTest extends TestCase
{
    public function test_audit_seal_survives_json_renormalisation(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $c = Client::create(['name' => 'أحمد', 'email' => 'a@b.com']);
        $c->update(['name' => 'محمد']);

        $this->assertSame(0, Artisan::call('hub:audit-verify'));

        // ما يفعله MySQL بعمود json: مسافات بعد النقطتين، يونيكود غير مهروب، ترتيب مفاتيح مختلف
        foreach (AuditEntry::whereNotNull('hash')->whereNotNull('before')->get() as $row) {
            $data = json_decode($row->getAttributes()['before'], true);
            if (! is_array($data)) continue;
            $shuffled = array_reverse($data, true);
            DB::table('audits')->where('id', $row->id)->update([
                'before' => json_encode($shuffled, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ]);
        }

        // نفس المحتوى بصياغة أخرى: يجب ألا يُعتبر عبثاً
        $this->assertSame(0, Artisan::call('hub:audit-verify'),
            'إعادة صياغة JSON — كما يفعل MySQL — كانت تُطلق إنذار عبث كاذباً على قاعدة سليمة');
    }

    public function test_audit_seal_still_catches_real_content_change(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $c = Client::create(['name' => 'أصلي']);
        $c->update(['name' => 'معدّل']);
        $this->assertSame(0, Artisan::call('hub:audit-verify'));

        // تغيير حقيقي في المحتوى (لا مجرد صياغة) لا بد أن يُكشف
        $row = AuditEntry::whereNotNull('hash')->whereNotNull('after')->first();
        $data = json_decode($row->getAttributes()['after'], true);
        $data['name'] = 'قيمة مزوّرة';
        DB::table('audits')->where('id', $row->id)->update(['after' => json_encode($data)]);

        $this->assertSame(1, Artisan::call('hub:audit-verify'));
    }

    public function test_demo_purge_matches_json_regardless_of_formatting(): void
    {
        $this->seedCore();
        Artisan::call('hub:demo');
        $seeded = DB::table('clients')->where('meta->demo', 1)->count();
        $this->assertGreaterThan(0, $seeded);

        // صف كتبه MySQL بصياغته (مسافة بعد النقطتين) — كان LIKE النصي يفوته
        DB::table('clients')->insert(['id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'صف بصياغة MySQL', 'meta' => '{"demo": 1}',
            'created_at' => now(), 'updated_at' => now()]);

        $real = Client::create(['name' => 'عميل حقيقي']);

        Artisan::call('hub:demo', ['--purge' => true]);

        $this->assertSame(0, DB::table('clients')->where('meta->demo', 1)->count());
        $this->assertSame(0, DB::table('clients')->where('name', 'صف بصياغة MySQL')->count());
        $this->assertNotNull(Client::find($real->id));       // والحقيقي سليم
    }

    /**
     * **واختبارُ قابلية النقل كان هو نفسه غير قابلٍ للنقل**: `PRAGMA index_list`
     * لهجةُ SQLite وحدها، فيسقط الاختبارُ على MySQL بخطأ صياغة — وهو المحرّك
     * الذي يعمل عليه النظام فعلاً. المخطّطُ يُقرأ الآن بواجهة Laravel المحايدة.
     */
    public function test_hot_query_indexes_exist(): void
    {
        $this->seedCore();
        foreach ([['idempotency_keys', 'idem_gc_idx'],
                  ['webhook_deliveries', 'wd_due_idx'],
                  ['inbox_documents', 'inbox_list_idx']] as [$table, $index]) {
            $found = collect(Schema::getIndexes($table))->pluck('name');
            $this->assertTrue($found->contains($index), "الفهرس {$index} مفقود على {$table}");
        }
    }

    /**
     * **لا لهجةَ محرّكٍ في الشيفرة ولا في الاختبارات.**
     *
     * كشفَ CI على MySQL 8 ما لم تُظهره MariaDB محلياً: النوعُ الأصليّ لـJSON
     * **يُعيد ترتيب مفاتيح الكائن** ويُعيد صياغته بمسافةٍ بعد النقطتين. فكلُّ
     * مطابقةٍ نصّية على محتوى عمود JSON (`LIKE '%"demo":1%'`) تفشل هناك وتنجح
     * هنا — والفرقُ لا يظهر إلا على الخادم.
     *
     * القاعدة: **محتوى JSON يُقرأ بمساره (`col->key`) لا بمطابقة نصّ**،
     * و`PRAGMA` لهجةُ SQLite وحدها.
     */
    public function test_no_engine_dialect_leaks_into_code_or_tests(): void
    {
        $bad = [];
        foreach ([app_path(), base_path('tests')] as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $f) {
                if (! $f->isFile() || ! str_ends_with($f->getFilename(), '.php')) continue;
                // بلا تعليقات: حارسٌ يسقط على **توثيق نفسه** حارسٌ رديء —
                // وصفُ اللهجة المرفوضة ليس استعمالاً لها
                $src = (string) preg_replace(['/\/\*.*?\*\//s', '/\/\/[^\n]*/'], ' ',
                    (string) file_get_contents($f->getPathname()));
                // مطابقةُ نصٍّ على بنية JSON: «"مفتاح":قيمة» داخل LIKE
                if (preg_match('/LIKE[^;]{0,60}%\\?"[a-z_]+\\?":/i', $src)) {
                    $bad[] = $f->getFilename() . ' → مطابقةُ نصٍّ على بنية JSON';
                }
                // الكلمةُ مُجزّأةٌ عمداً: لو كُتبت حرفيةً لطابقت **شيفرة الحارس
                // نفسها** فسقط على نفسه أبداً
                if (str_contains($src, 'PRA' . 'GMA ')) {
                    $bad[] = $f->getFilename() . ' → استعلامُ مخطّطٍ بلهجة SQLite';
                }
            }
        }

        $this->assertSame([], $bad,
            'لهجةُ محرّكٍ بعينه — تنجح على واحدٍ وتفشل على الآخر: ' . implode(' · ', array_unique($bad)));
    }
}
