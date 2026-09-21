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
 *
 * وأكثرُ ما هنا **يُحاكي** السلوكَ الفارقَ بدل انتظارِ محرّك — إعادةُ صياغةِ
 * وثائقِ JSON مثلاً — كي يقع البلاغُ في كلِّ تشغيلٍ لا في تشغيلِ المحرّكِ
 * وحدَه. وذلك مكسبٌ لا عجز: خطأٌ يُكشَف على SQLite أرخصُ بمرّاتٍ من خطأٍ
 * يُكشَف على المحرّكِ في CI.
 *
 * **وحزمةُ المحرّكِ نفسِها تُشغَّل فعلاً** — محلّيّاً بـ`phpunit.mysql.xml`
 * وفي CI على `mysql:8.0` و`mariadb:10.11` معاً. فالمحاكاةُ هنا طبقةٌ أولى
 * لا بديلٌ عن المحرّك.
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
                  ['inbox_documents', 'inbox_list_idx'],
                  // (WP-2.2) دلاءُ RED: الفريدُ تقوم عليه الكتابةُ الذرّية
                  // (UPDATE ثم insertOrIgnore) وتقودُ مقدّمتُه تقليمَ bucket_at،
                  // والثنائيُّ يخدم رسمَ مسارٍ واحدٍ عبر الزمن — dropIndex لاحقٌ
                  // كان سيمرّ صامتاً بلا هذين السطرين.
                  ['http_metric_buckets', 'hmb_bucket_unique'],
                  ['http_metric_buckets', 'hmb_route_at_idx'],
                  // (WP-4.1 · critic #39) نتائجُ الأمن: الفريدُ (code, entity_type, entity_id)
                  // هو ما يمنع تكرارَ النتيجة الواحدة كلَّ تشغيلٍ لـreconcile — بقيمتَي
                  // الكيان الحارستين ('org','') لا NULL، فـNULL متمايزٌ في الفريد على المحرّكين.
                  ['security_findings', 'sf_code_entity_unique'],
                  // (WP-6.3 · critic #39) ذاكرةُ التنبيه: الفريدُ على dedup_key هو ما
                  // يجعل الشرطَ الواحد صفّاً واحداً (عدّادٌ وإقرارٌ وتعافٍ) لا سيلاً —
                  // dropIndex لاحقٌ كان سيمرّ صامتاً بلا هذا السطر.
                  ['alert_instances', 'ai_dedup_unique']] as [$table, $index]) {
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

    /**
     * **ترتيبُ السلسلة روابطُها لا ساعتُها.**
     *
     * `audits.created_at` طابعُ وقتٍ بدقّة **الثانية**: عشراتُ السجلات في الثانية
     * الواحدة تحمل القيمة نفسها حرفياً. و`ORDER BY created_at` على قيمٍ متساوية
     * **بلا ترتيبٍ مضمون** — لا في المعيار ولا في المحرّكات: MariaDB أعادتها
     * بترتيب الإدراج صدفةً فمرّت الحزمةُ محلياً، وMySQL 8 أعادها بترتيبٍ آخر
     * فانكسر إعادةُ بناء السلسلة في الاختبار وسقط CI.
     *
     * القاعدة: ترتيبُ السلسلة يُستخرج من **روابط `prev_hash`** (أو من `id`
     * التزايديّ)، ولا يُبنى على طابع وقتٍ أبداً.
     */
    public function test_audit_chain_order_is_links_not_timestamps(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        foreach (['س١', 'س٢', 'س٣', 'س٤'] as $n) Client::create(['name' => $n]);

        // الطابعُ مخزّنٌ بدقّة الثانية على المحرّكين — فالتساوي وارد، والترتيبُ عليه قرعة
        $stamp = (string) DB::table('audits')->orderBy('id')->value('created_at');
        $this->assertStringNotContainsString('.', $stamp,
            'لو صار للطابع كسرُ ثانيةٍ لتغيّر أساسُ هذا الحارس — راجعه بدل حذفه');

        // الترتيبُ الحقّ: مشيٌ على الروابط من الصفر — ولا بد أن يطابق ترتيب id
        $rows = DB::table('audits')->whereNotNull('hash')->get(['id', 'prev_hash', 'hash']);
        $byPrev = $rows->keyBy('prev_hash');
        $walk = []; $prev = str_repeat('0', 64);
        while ($r = $byPrev->get($prev)) { $walk[] = $r->id; $prev = $r->hash; }

        $this->assertSame($rows->count(), count($walk), 'السلسلة لم تُمشَ كاملةً من الصفر');
        $this->assertSame($rows->pluck('id')->sort()->values()->all(), $walk,
            'ترتيبُ الروابط خالف ترتيب الإدراج — ولا يجوز الاستعاضة عنهما بـcreated_at');
    }

    /**
     * وحارسُ المصدر يمنع عودةَ العادة: لا شيفرةَ ولا اختبارَ يرتّب سجلّ التدقيق
     * بالساعة — والشيفرةُ اليوم نظيفةٌ منها (السلسلةُ تُبنى من `audit_chain.head`
     * وتُتحقَّق بمشي الروابط)، فالحارسُ يحفظ نظافتها لا يُصلحها.
     */
    public function test_no_test_orders_the_audit_chain_by_timestamp(): void
    {
        $bad = [];
        $it = new \AppendIterator();
        foreach ([base_path('tests'), app_path()] as $root) {
            $it->append(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)));
        }
        foreach ($it as $f) {
            if (! $f->isFile() || ! str_ends_with($f->getFilename(), '.php')) continue;
            // تُحفظ الأسطر عند إزالة التعليق الكتليّ: بلاغٌ برقم سطرٍ خاطئ يُضيّع وقت قارئه
            $src = (string) preg_replace_callback('/\/\*.*?\*\//s',
                fn ($m) => str_repeat("\n", substr_count($m[0], "\n")),
                (string) preg_replace('/\/\/[^\n]*/', ' ',
                    (string) file_get_contents($f->getPathname())));
            foreach (explode("\n", $src) as $i => $line) {
                if (! preg_match('/AuditEntry|[\'"]audits[\'"]/', $line)) continue;
                if (preg_match('/(orderBy|orderByDesc|sortBy|sortByDesc)\([\'"]created_at[\'"]\)/', $line)) {
                    $bad[] = $f->getFilename() . ':' . ($i + 1);
                }
            }
        }

        $this->assertSame([], $bad,
            'ترتيبُ سجلّ التدقيق بطابع الوقت — قرعةٌ على المحرّكات عند تساوي الثانية: ' . implode(' · ', $bad));
    }

    /**
     * **بوّابةُ CI تمتحن المحرّكَ الذي يعمل عليه الخادمُ فعلاً.**
     *
     * ── **الفجوةُ التي أُغلقت** (البند #32 في `docs/TECH_DEBT.md`) ──
     *
     * الخادمُ على **MariaDB**، وبوّابةُ CI كانت تمتحن **`mysql:8.0` وحدَها**.
     * فبقي محرّكُ الإنتاجِ ممتحَناً على الجهازِ المحلّيِّ لا غير — أي **بذاكرةِ
     * من يتذكّر أن يُشغّل الأمر**، وهو بالضبط ما وُجدت البوّابةُ لتُغنيَ عنه.
     *
     * وليسا محرّكاً واحداً باسمَين: `explicit_defaults_for_timestamp` يختلف
     * افتراضُه، ودلالةُ `ORDER BY` عند تساوي القيمِ تختلف — وهي **القرعةُ**
     * التي يحذّر منها `CLAUDE.md` نصّاً: تخضرُّ الحزمةُ على أحدِهما وتسقط على
     * الآخر. وبندٌ آخرُ في السجلِّ (#2 · عشرون عمودَ `timestamp` بلا
     * `nullable` ولا افتراض) **خطرُه كلُّه في هذا الفرقِ نفسِه**، فامتحانُ
     * المحرّكَين يُحوّله من خطرٍ مجهولٍ إلى نتيجةٍ مقيسةٍ في كلِّ دفعة.
     *
     * **والحارسُ يسأل عن الصورتَين معاً** — لا عن وجودِ صورةٍ ما: إسقاطُ
     * إحداهما لاحقاً يُعيد الفجوةَ صامتةً كما نشأت أوّلَ مرّة.
     */
    public function test_بوّابةُ_CI_تمتحن_المحرّكَين_لا_واحداً(): void
    {
        $path = base_path('.github/workflows/ci.yml');
        $this->assertFileExists($path, 'بوّابةُ CI غائبةٌ — لا شيء يفرض خضرةَ الحزمة');

        $ci = (string) file_get_contents($path);

        /*
         * **والتأكيدُ على منطوقٍ لا على الملفّ** — `assertMatchesRegularExpression`
         * تطبع الملفَّ كلَّه عند السقوط، فيغرق البلاغُ في مئتَي سطرِ YAML.
         * والرسالةُ التي لا تُقرأ لا تُصلَح.
         */
        $this->assertTrue((bool) preg_match('/image:\s*[\'"]?mariadb:/', $ci),
            'صورةُ MariaDB غائبةٌ عن مصفوفةِ CI — ومحرّكُ الإنتاجِ هو MariaDB (البند #32)');

        $this->assertTrue((bool) preg_match('/image:\s*[\'"]?mysql:/', $ci),
            'صورةُ MySQL غائبةٌ عن مصفوفةِ CI — وصرامتُها هي التي تكشف ما تتساهل فيه SQLite');

        /*
         * **وكلُّ صفٍّ غيرِ sqlite يمتحن بإعدادِ المحرّكِ لا بإعدادِ SQLite.**
         *
         * صفٌّ يُقلِع قاعدةً ثمّ يُشغّل `phpunit.xml` يبدو أخضرَ وهو لم يمسَّ
         * المحرّكَ بحرف — **وهذا أسوأُ من غيابِه**، لأنّه يشهد زوراً.
         */
        $rows = [];
        preg_match_all('/^\s*- \{[^}]*\}\s*$/m', $ci, $m);
        foreach ($m[0] as $row) {
            if (! str_contains($row, 'image:')) continue;
            $rows[] = $row;
            if (preg_match('/image:\s*[\'"]{2}|image:\s*[\'"]?\s*[,}]/', $row)) continue; // صفُّ sqlite
            $this->assertStringContainsString('phpunit.mysql.xml', $row,
                'صفٌّ يُقلِع محرّكَ خادمٍ ثمّ يمتحن بإعدادِ SQLite — خضرةٌ لا تعني شيئاً: ' . trim($row));
        }

        $this->assertGreaterThanOrEqual(4, count($rows),
            'مصفوفةُ CI أقصرُ ممّا يلزم — المحرّكان والإصداران معاً أربعةُ صفوفٍ على الأقلّ');
    }

    /**
     * **لا عمودَ زمنيٍّ يُكتَب فوقه بلا أن يطلبه أحد** (البند #2 · DI-05/06).
     *
     * ── **البندُ كما كُتب، والقياسُ كما جاء** ──
     *
     * السجلُّ يقول: عشرون عمودَ `timestamp` بلا `nullable` ولا افتراض، «يسقط
     * تنصيبٌ جديدٌ على `explicit_defaults_for_timestamp=OFF`». **والعشرون
     * صحيحة** — لكنّ الآليّةَ المخوفةَ لم تقع على المحرّكِ المقيس:
     *
     * | ما خافه البند | القياس |
     * |---|---|
     * | `ON UPDATE CURRENT_TIMESTAMP` ضمنيّاً | **٠** عموداً غيرَ `updated_at` |
     * | افتراضٌ صفريٌّ `0000-00-00` | **٠** |
     * | `NOT NULL` بلا افتراض | **٢٠** — صرامةٌ يملؤها التطبيقُ دائماً |
     *
     * ── **فلماذا حارسٌ إذن؟** ──
     *
     * لأنّ الفرقَ **ليس في الهجرةِ بل في إعدادِ الخادم**: المتغيّرُ
     * `explicit_defaults_for_timestamp` يُضبَط في `my.cnf` لا في المستودع.
     * فنفسُ الهجرةِ تُنتج مخطَّطاً سليماً على خادمٍ ومخطَّطاً يكتب فوقَ
     * أعمدتِه على آخر. **وتحريرُ خمسةٍ وثلاثين موضعاً في الهجرات لا يمسُّ
     * ذلك بحرف.**
     *
     * وهذا الحارسُ يسأل **المخطَّطَ المُنشأَ فعلاً** لا نصَّ الهجرة: فإن
     * أُنشئ على خادمٍ بإعدادٍ آخرَ ظهرت الأعمدةُ هنا فوراً. وهو يعمل على
     * المحرّكَين في CI، أي على `mysql:8.0` و`mariadb:10.11` معاً.
     *
     * **والعمودُ الذي يُكتَب فوقه بلا طلبٍ أخبثُ من عمودٍ يسقط إدراجُه:**
     * السقوطُ يُرى، والكتابةُ فوقَ `started_at` أو `first_seen_at` **تُفسِد
     * التاريخَ صامتةً** — فيصير «أوّلُ رصدٍ» هو آخرَ تحديث.
     */
    public function test_لا_عمودَ_زمنيٍّ_يُكتَب_فوقه_ضمنيّاً(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('سلوكُ الأعمدةِ الزمنيّةِ الضمنيُّ خاصّيّةُ MySQL/MariaDB');
        }

        $schema = DB::connection()->getDatabaseName();

        /*
         * **و`updated_at` وحدَها مستثناةٌ بحقّ** — الكتابةُ فوقها عند كلِّ
         * تحديثٍ هي معناها، وLaravel يتولّاها من التطبيقِ أصلاً.
         */
        $overwritten = DB::select(
            "SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS col
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND DATA_TYPE = 'timestamp'
                AND EXTRA LIKE '%on update%' AND COLUMN_NAME <> 'updated_at'
              ORDER BY TABLE_NAME, COLUMN_NAME",
            [$schema]
        );

        $this->assertSame([], array_map(fn ($r) => $r->col, $overwritten),
            "أعمدةٌ زمنيّةٌ تُكتَب فوقها عند كلِّ تحديثٍ بلا أن يطلب ذلك أحد.\n"
            . 'المحرّكُ رقّاها ضمنيّاً لأنّ `explicit_defaults_for_timestamp=0` — '
            . 'صرّح بـ`nullable()` أو `useCurrent()` في هجرةِ كلٍّ منها.');

        /*
         * **والتاريخُ الصفريُّ ليس تاريخاً.** عمودٌ افتراضُه `0000-00-00`
         * يمرّ في القاعدةِ ويسقط عند قراءتِه في PHP — أو أسوأُ: يُقرَأ
         * «١ يناير سنةَ صفر» فيُفسد كلَّ فرزٍ ومدىً زمنيّ.
         */
        $zeroDated = DB::select(
            "SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS col
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND DATA_TYPE = 'timestamp'
                AND COLUMN_DEFAULT LIKE '0000%'
              ORDER BY TABLE_NAME, COLUMN_NAME",
            [$schema]
        );

        $this->assertSame([], array_map(fn ($r) => $r->col, $zeroDated),
            'أعمدةٌ زمنيّةٌ افتراضُها تاريخٌ صفريّ — تمرّ في القاعدةِ وتُفسد كلَّ فرزٍ ومدى');
    }
}
