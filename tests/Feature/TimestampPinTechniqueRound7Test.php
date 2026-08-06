<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة السابعة — **الترحيلُ الذي لا يُثبّت شيئاً.**
 *
 * `2026_08_06_000001` و`_000004` نفّذا `ALTER … MODIFY col TIMESTAMP NOT NULL`
 * ظنّاً أن إعادةَ التعريف تنزع `ON UPDATE CURRENT_TIMESTAMP` الضمنيّ. وهي **لا
 * تنزعه**: القاعدةُ أنّ أوّلَ عمود `TIMESTAMP NOT NULL` **لا يُذكَر له `DEFAULT`
 * ولا `ON UPDATE`** ينال الاثنين ضمنيّاً — والتعريفُ المكتوب في الترحيل هو
 * بالضبط ذلك التعريفُ المعطوب، فيُعاد تطبيقُ الضمنيّ من جديد.
 *
 * **ولماذا لم تحمرّ الحزمة؟** الاختبارُ السابق يقرأ `information_schema` على
 * خادمِ الفحص، وفيه `explicit_defaults_for_timestamp = 1` — فالإسنادُ الضمنيّ
 * لا يقع أصلاً هنا، ويمرّ الاختبارُ **فراغاً** بينما العطلُ حيٌّ في الإنتاج
 * (‏MySQL 5.7 وMariaDB دون 10.10: الافتراضيُّ صفر).
 *
 * فالاختبارُ هنا لا يقرأ حالةَ خادمٍ سليم، بل **يصنع الظرفَ المعطوب بيده** في
 * جدولِ مسبارٍ مؤقّت، ثم يُجرّب التقنيةَ الحقيقية عليه.
 *
 * **وعلى اتصالٍ خاصٍّ به.** ‏MySQL 8 يرفض `SET SESSION
 * explicit_defaults_for_timestamp` داخل معاملةٍ مفتوحة (خطأ 1766)، وحزمةُ
 * الاختبار تلفّ كلَّ اختبارٍ في معاملة — بينما MariaDB تسمح بها. فمن يُشغّل
 * محلياً على MariaDB يراه أخضر ويسقط على MySQL في الـCI. الظرفُ يُصنَع هنا
 * على **جلسةٍ ثانية** لا معاملةَ فيها؛ والجدولُ مسبارٌ مؤقّتٌ باسمٍ عشوائيّ
 * يُحذف في `finally`، فلا يمسّ معاملةَ الاختبار ولا جداولَه.
 */
class TimestampPinTechniqueRound7Test extends TestCase
{
    public function test_the_pinning_technique_actually_strips_the_implicit_on_update(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->assertTrue(true, 'الخاصيّة الضمنية سلوكُ MySQL/MariaDB وحدهما');

            return;
        }

        // جلسةٌ ثانية على القاعدة نفسِها: خارج معاملةِ الاختبار
        $conn = 'ts_probe_conn';
        config(['database.connections.' . $conn => config(
            'database.connections.' . DB::getDefaultConnection())]);
        $db = DB::connection($conn);

        // الإثباتُ لا الادّعاء: الاتصالُ الافتراضيّ داخل معاملةِ الحزمة، وجلسةُ
        // المسبار خارجها. لو عاد أحدٌ بالمسبار إلى الاتصال الافتراضيّ سقط هذا
        // السطرُ فوراً — لا بعد رحلةٍ إلى الـCI على محرّكٍ آخر
        $this->assertGreaterThan(0, DB::connection()->transactionLevel(),
            'حزمةُ الاختبار لا تلفّ الاختبارَ في معاملة — فالحراسةُ هنا بلا معنى');
        $this->assertSame(0, $db->transactionLevel(),
            'جلسةُ المسبار داخل معاملة، و MySQL 8 يرفض ضبطَ المتغيّر حينها (خطأ 1766)');

        $t = 'ts_probe_' . Str::random(8);
        $extra = fn () => strtolower((string) $db->table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $db->getDatabaseName())
            ->where('TABLE_NAME', $t)->where('COLUMN_NAME', 'stamp')->value('EXTRA'));

        try {
            // الظرفُ المعطوب كما هو في معظم الاستضافات المشتركة
            $db->statement('SET SESSION explicit_defaults_for_timestamp = 0');
            $db->statement("CREATE TABLE `{$t}` (id INT PRIMARY KEY, stamp TIMESTAMP NOT NULL, x INT)");

            $this->assertStringContainsString('on update', $extra(),
                'الظرفُ المعطوب لم يتحقّق على هذا الخادم — الاختبارُ سيمرّ فراغاً لا حراسة');

            // التقنيةُ القديمة: تُعيد كتابة التعريف المعطوب نفسِه
            $db->statement("ALTER TABLE `{$t}` MODIFY `stamp` TIMESTAMP NOT NULL");
            $this->assertStringContainsString('on update', $extra(),
                'إن نجحت التقنيةُ القديمة فقد تغيّر سلوكُ المحرّك — راجع الترحيلات');

            // والتقنيةُ الصحيحة: ذكرُ DEFAULT صراحةً يُلغي الإسنادَ الضمنيّ بشقّيه
            $db->statement("ALTER TABLE `{$t}` MODIFY `stamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
            $this->assertStringNotContainsString('on update', $extra(),
                'التقنيةُ المعتمدة في الترحيلات لا تنزع `ON UPDATE` — فالأختامُ تُدهَس '
                . 'في الإنتاج والترحيلُ يبدو ناجحاً');
        } finally {
            $db->statement("DROP TABLE IF EXISTS `{$t}`");
            DB::purge($conn);
        }
    }

    /** والترحيلاتُ الثلاثة تستعمل التقنيةَ الصحيحة نصّاً */
    public function test_every_pinning_migration_states_an_explicit_default(): void
    {
        $files = glob(base_path('database/migrations/*pin*timestamp*.php'))
               + glob(base_path('database/migrations/*repin*.php'));
        $this->assertNotEmpty($files, 'لا ترحيلَ تثبيتٍ أصلاً — الاختبارُ يحرس فراغاً');

        foreach ($files as $f) {
            $src = (string) file_get_contents($f);
            if (! str_contains($src, 'MODIFY')) continue;
            $this->assertMatchesRegularExpression(
                '/MODIFY[^"\']*TIMESTAMP[^"\']*(DEFAULT CURRENT_TIMESTAMP|NULL DEFAULT NULL)/', $src,
                basename($f) . ': `MODIFY … TIMESTAMP NOT NULL` بلا `DEFAULT` صريح '
                . 'يُعيد الإسنادَ الضمنيّ — فالترحيلُ بلا أثر');
        }
    }

    /** و`record_acks`: المثبَّتُ هو ختمُ الدليل `ack_at` لا `created_at` القابل للفراغ */
    public function test_the_right_column_is_pinned_in_record_acks(): void
    {
        $src = (string) file_get_contents(
            base_path('database/migrations/2026_08_06_000001_pin_evidence_timestamps.php'));

        $this->assertStringContainsString("'record_acks'  => ['ack_at']", $src,
            '`ack_at` هو أوّلُ TIMESTAMP NOT NULL في جدوله وهو ختمُ الدليل — '
            . 'و`created_at` هناك nullable فمعفًى بطبعه، فتثبيتُه حراسةٌ في غير موضعها');
    }
}
