<?php

namespace Tests\Feature\UltimateReview;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * **شرطُ المخطّطِ لا يكذب صامتاً** (المراجعةُ الشاملة · الطبقة ١ · F-16).
 *
 * التشخيصُ الجذريُّ في التدقيق كان: «الحارسُ المشروطُ بالمخطّط اصطلاحٌ فاشلٌ
 * مفتوحاً بالبناء — ١٠٩ مواضع». والاصطلاحُ هو:
 *
 * ```php
 * if (Schema::hasColumn('audits', 'company_id')) { $q->whereIn('company_id', $cids); }
 * ```
 *
 * غيابُ العمودِ ⇒ لا مرشِّح ⇒ **نتيجةٌ أوسع**. وقُلب الاصطلاحُ في أخطرِ موضعٍ
 * (`hub_scope` يفشل مغلقاً بـ`1 = 0`)، وبقي الباقي.
 *
 * **والقياسُ يعيد تأطيرَ البلاغ.** مسحٌ لكلِّ شرطٍ بوسيطَين حرفيَّين في `app/`:
 *
 * ```
 * جداولُ مشروطة:  88 فريدة  (273 موضعاً)
 * أعمدةٌ مشروطة:  83 فريدة  (167 موضعاً)
 * ما يكذب على قاعدةٍ مُرحَّلةٍ كاملة:  صفر (عدا مدخلِ استشرافٍ واحدٍ مُعلَن)
 * ```
 *
 * فليس في المنشأةِ المُرحَّلةِ فرعٌ مختفٍ اليوم. **والخطرُ ليس ثقباً قائماً بل
 * الصمت:** لو أسقطت هجرةٌ عموداً، أو كُتب اسمٌ بخطأٍ مطبعيّ، لاختفى المرشِّحُ
 * **بلا صوت** — لا استثناء، ولا سطرَ سجلّ، ولا اختبارٌ أحمر. وهذا ما يجعل
 * الاصطلاحَ خطراً: لا أنّه مفتوحٌ الآن، بل أنّ انفتاحَه لن يُسمَع.
 *
 * **فهذا الحارسُ يجعله مسموعاً**: أربعُ مئةٍ وأربعون شرطاً تُفحَص في كلِّ تشغيلٍ
 * للحزمة. وهو **لا يقلب الاصطلاح** في خمسةٍ وثمانين موضعاً — ذاك تعديلٌ كبيرٌ
 * بمخاطرَ حقيقية — بل يقطع الصمتَ الذي يجعل قلبَه ضروريّاً.
 */
class SchemaConditionsAreNotSilentlyFalseTest extends TestCase
{
    /**
     * **استشرافٌ مُعلَنٌ لا سهو.** شرطٌ يذكر عموداً لم يُرحَّل بعد، ومسارُه
     * مُعطَّلٌ بقصدٍ حتى يُرحَّل. لكلِّ مدخلٍ سببٌ مكتوبٌ كما في `hub_tenancy`.
     */
    private const FORWARD_LOOKING = [
        'tasks.client_id' => 'المهامُّ ليست منطَّقةً بالعميل (`hub_client_col(\'tasks\')` = null)، '
            . 'فسطرُ توريثِ العميلِ في `CommentController` محروسٌ بالعمودِ نفسِه ولا يسري. '
            . 'يبقى مكتوباً ليسري تلقائيّاً إن صارت المهامُّ منطَّقةً بالعميلِ يوماً.',
    ];

    /** مسحُ المصدر: كلُّ شرطِ مخطّطٍ بوسيطَين حرفيَّين */
    private function scan(): array
    {
        $root = app_path();
        $tables = $cols = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $f) {
            if (! $f->isFile() || $f->getExtension() !== 'php') continue;
            $rel = 'app/' . str_replace($root . '/', '', $f->getPathname());
            foreach (explode("\n", (string) @file_get_contents($f->getPathname())) as $i => $ln) {
                if (preg_match_all("/(?:Schema::hasColumn|hub_has_col)\s*\(\s*'([a-z0-9_]+)'\s*,\s*'([a-z0-9_]+)'/", $ln, $m, PREG_SET_ORDER)) {
                    foreach ($m as $x) $cols["{$x[1]}.{$x[2]}"][] = $rel . ':' . ($i + 1);
                }
                if (preg_match_all("/(?:Schema::hasTable|hub_has_table)\s*\(\s*'([a-z0-9_]+)'/", $ln, $m, PREG_SET_ORDER)) {
                    foreach ($m as $x) $tables[$x[1]][] = $rel . ':' . ($i + 1);
                }
            }
        }

        return [$tables, $cols];
    }

    /** أوّلاً: المِقياسُ يجد ما يقيس — وإلّا كان أخضرَ على فراغ */
    public function test_the_scan_actually_finds_the_conditions(): void
    {
        [$tables, $cols] = $this->scan();

        $this->assertGreaterThan(50, count($tables), 'المسحُ لم يجد شروطَ الجداول — المِقياسُ عطلانُ لا الشيفرة');
        $this->assertGreaterThan(50, count($cols), 'المسحُ لم يجد شروطَ الأعمدة');
        $this->assertArrayHasKey('audits.company_id', $cols,
            'موضعٌ معلومٌ بعينه غاب عن المسح — الصيغةُ تغيّرت والحارسُ يحرس فراغاً');
    }

    public function test_no_conditioned_table_is_missing_from_the_migrated_schema(): void
    {
        [$tables] = $this->scan();
        $dead = [];
        foreach ($tables as $t => $sites) {
            if (! Schema::hasTable($t)) $dead[] = "{$t}  ← " . implode('، ', array_slice($sites, 0, 3));
        }

        $this->assertSame([], $dead,
            'الكودُ يشترط جدولاً لا تملكه القاعدةُ المُرحَّلة — فالفرعُ كلُّه يُتخطّى صامتاً');
    }

    public function test_no_conditioned_column_is_missing_from_the_migrated_schema(): void
    {
        [, $cols] = $this->scan();
        $dead = [];
        foreach ($cols as $key => $sites) {
            if (isset(self::FORWARD_LOOKING[$key])) continue;
            [$t, $c] = explode('.', $key, 2);
            if (! Schema::hasTable($t) || ! Schema::hasColumn($t, $c)) {
                $dead[] = "{$key}  ← " . implode('، ', array_slice($sites, 0, 3));
            }
        }

        $this->assertSame([], $dead,
            'شرطٌ على عمودٍ لا تملكه القاعدة: المرشِّحُ المحروسُ به يختفي بلا صوت — '
            . 'لا استثناءَ ولا سطرَ سجلٍّ ولا اختبارٌ أحمر. أضِف الهجرة، أو أعلِنه استشرافاً بسببٍ مكتوب');
    }

    /** والاستشرافُ يبقى صغيراً ومُبرَّراً — لا سلّةَ استثناءاتٍ تنمو */
    public function test_the_forward_looking_list_stays_small_and_justified(): void
    {
        $this->assertLessThanOrEqual(3, count(self::FORWARD_LOOKING),
            'قائمةُ الاستشرافِ تنمو — وكلُّ مدخلٍ فيها فرعٌ معطَّلٌ في الإنتاج');

        foreach (self::FORWARD_LOOKING as $key => $why) {
            $this->assertGreaterThan(60, mb_strlen($why), "مدخلُ «{$key}» بلا سببٍ مكتوبٍ كافٍ");
            [$t, $c] = explode('.', $key, 2);
            $this->assertTrue(Schema::hasTable($t), "جدولُ «{$key}» نفسُه غائب — المدخلُ يخفي عطلاً أكبر");
            $this->assertFalse(Schema::hasColumn($t, $c),
                "«{$key}» صار مُرحَّلاً — احذفه من قائمةِ الاستشراف ليعود تحت الحراسة");
        }
    }
}
