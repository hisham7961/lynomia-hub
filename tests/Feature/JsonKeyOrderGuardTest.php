<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * **حارسُ ترتيبِ مفاتيحِ JSON — البوّابةُ التي كانت ناقصة.**
 *
 * عمودُ JSON في MySQL 8 **يعيد ترتيبَ مفاتيحِ الكائن** عند التخزين (الأقصرُ
 * أوّلاً: `at` قبل `after_hours`)، بينما تحفظ SQLite وMariaDB ترتيبَ الإدراج.
 * فمقارنةُ **المصفوفةِ كلِّها** بـ`assertSame` على قيمةٍ قُرئت من عمودِ JSON
 * تخضرّ محلّيّاً وتسقط على CI.
 *
 * **ولماذا حارسٌ نصّيٌّ لا اختبارٌ يشغّل المحرّك؟** لأنّ المحرّكَ الثاني محلّيّاً
 * **MariaDB 10.11 لا MySQL 8**، وهو يحفظ الترتيبَ فلا يُظهر العيبَ أبداً. فحزمةٌ
 * خضراءُ على المحرّكَين هنا **لا تكافئ** خضرةَ CI في هذا الصنفِ وحدَه. وهذه
 * فجوةٌ في البيئةِ لا في الاصطلاح — تُقال ولا تُخفى.
 *
 * والثمنُ كان مقيساً: **أربعُ دفعاتٍ متتاليةٍ حمراءُ على CI** (v2.525.0 ←
 * v2.527.0) بسببِ موضعَين اثنين، والحزمةُ المحلّيّةُ خضراءُ في كلٍّ منها.
 *
 * فالبديلُ الوحيدُ المتاح مسحُ نصِّ الاختباراتِ عن الشكلِ الخطر. وحدُّه معروف:
 * **المسحُ اللفظيُّ لا يُثبت غياباً** (FINDINGS §5.14) — لكنّه يمنع تكرارَ
 * الشكلِ المعروفِ الذي كلّفنا فعلاً، وذاك ما يُدّعى له لا أكثر.
 */
class JsonKeyOrderGuardTest extends TestCase
{
    /** ما يدلّ على أنّ القيمةَ قُرئت من عمودِ JSON */
    private const JSON_SOURCES = [
        'json_decode', '->after', '->before', '->meta', '->custom',
        '->payload', '->evidence', '->extra',
    ];

    public function test_no_test_compares_a_whole_json_object_to_an_array_literal(): void
    {
        $bad = [];

        foreach (File::allFiles(base_path('tests')) as $f) {
            if ($f->getExtension() !== 'php') continue;
            if ($f->getFilename() === 'JsonKeyOrderGuardTest.php') continue;   // لا يُبلّغ عن نفسه

            $lines = file($f->getPathname());
            foreach ($lines as $i => $line) {
                // بدايةُ مقارنةٍ بمصفوفةٍ **ترابطيّة** (فيها `=>`) — والقوائمُ
                // المرقّمةُ خارجَ الحصر لأنّ MySQL لا يُعيد ترتيبَها.
                if (! preg_match('/assert(?:Same|Equals)\(\s*\[[^\]]*=>/', $line)) continue;

                // نافذةُ العبارةِ حتى فاصلتِها المنقوطة (أربعةُ أسطرٍ تكفي عملياً)
                $stmt = implode('', array_slice($lines, $i, 4));
                $stmt = substr($stmt, 0, ($p = strpos($stmt, ';')) === false ? null : $p);

                foreach (self::JSON_SOURCES as $src) {
                    if (str_contains($stmt, $src)) {
                        $bad[] = $f->getRelativePathname() . ':' . ($i + 1);
                        break;
                    }
                }
            }
        }

        $this->assertSame([], $bad,
            "مقارنةُ مصفوفةٍ ترابطيّةٍ كاملةٍ بقيمةٍ من عمود JSON — ترتيبُ المفاتيحِ\n"
            . "قرعةٌ على MySQL 8 وثابتٌ على MariaDB، فهذا يخضرّ محلّيّاً ويسقط على CI.\n"
            . "أكِّد **كلَّ قيمةٍ بمفتاحها**، وأكِّد المفاتيحَ بعد `sort()`:\n"
            . implode("\n", $bad));
    }

    /**
     * **وأنّ الحارسَ يحرس فعلاً** — لا يكفي أن يخضرّ، فالحارسُ الذي لا يُمسك
     * شيئاً أبداً يخضرّ كذلك. يُركَّب هنا الشكلُ الخطرُ نفسُه في ملفٍّ مؤقّت
     * ويُتحقَّق أنّ المنطقَ يُمسكه، ثمّ يُزال.
     */
    public function test_the_guard_itself_catches_the_shape_it_claims_to_catch(): void
    {
        $probe = base_path('tests/Feature/__json_order_probe.php');
        file_put_contents($probe, "<?php\n\$this->assertSame(['b' => 1, 'a' => 2], (array) \$row->after);\n");

        try {
            $caught = false;
            $lines = file($probe);
            foreach ($lines as $i => $line) {
                if (! preg_match('/assert(?:Same|Equals)\(\s*\[[^\]]*=>/', $line)) continue;
                $stmt = implode('', array_slice($lines, $i, 4));
                foreach (self::JSON_SOURCES as $src) {
                    if (str_contains($stmt, $src)) { $caught = true; break 2; }
                }
            }
            $this->assertTrue($caught, 'الحارسُ لم يُمسك الشكلَ الذي يدّعي إمساكَه — فخضرتُه بلا معنى');
        } finally {
            @unlink($probe);
        }
    }
}
