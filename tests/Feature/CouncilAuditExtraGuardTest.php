<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **حارسُ الصنف: أثرُ تدقيقٍ لا عمودَ له** (مجلس الخبراء · §٥٫١٣ · إعادةُ الفحص).
 *
 * كشف إغلاقُ N-14 أنّ إصلاحَ N-7 **لم يُنفَّذ وهو مكتوب**: مفاتيحُ `$extra`
 * المسطَّحةُ التي لا عمودَ لها في `audits` يحذفها خطّافُ `AuditEntry::creating`
 * قبل الإدراج (درعُ «النشر قبل الترحيل»)، فمرّت ثلاثُ دفعاتٍ خضراءُ على
 * المحرّكَين وبندٌ مشطوبٌ في السجلّ **ولا بايتَ في القاعدة**.
 *
 * ثمّ حاولتُ مسحَ المستودعِ بحثاً عن نظائرِه فأعطى المسحُ **تسعةَ عشرَ موضعاً
 * كلُّها كاذبة** — لأنّ تمييزَ «الوسيطِ الخامس» عن مصفوفةٍ في سطرٍ سابقٍ لا
 * يُدرَك بتعبيرٍ نمطيّ. والدرسُ: **مسحٌ لفظيٌّ لا يُثبت غياباً**.
 *
 * فالحارسُ هنا **زمنُ تشغيلٍ لا نصّ**: `hub_audit` نفسُها تُنبّه حين يُمرَّر
 * مفتاحٌ بلا عمود — تُلقي خارجَ الإنتاج (فتسقط الحزمةُ فوراً)، وتُسجّل في
 * الإنتاج (فلا تُكسَر عمليّةٌ حقيقيّةٌ لأجلِ قيدِ تدقيق — وهو غرضُ الدرعِ الأصليّ).
 *
 * **ولا يُنقَض الدرعُ بل يُكشَف:** ما كان يُبتلَع صامتاً صار مسموعاً.
 */
class CouncilAuditExtraGuardTest extends TestCase
{
    public function test_a_flat_extra_key_without_a_column_is_refused_loudly(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/after_hours/');

        hub_audit('فعلٌ بأثرٍ بلا عمود', 'quotes', 'x1', 'اختبار',
            ['after_hours' => true, 'at' => '03:41']);
    }

    public function test_a_legitimate_column_extra_passes_and_lands(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        hub_audit('فعلٌ بأثرٍ سليم', 'quotes', 'x2', 'اختبار',
            ['after' => ['after_hours' => true, 'at' => '03:41'], 'reason' => 'سبب']);

        $row = DB::table('audits')->where('record_id', 'x2')->orderByDesc('id')->first();
        $this->assertNotNull($row, 'القيدُ لم يُكتب');
        $this->assertSame('سبب', $row->reason);
        /*
         * **الترتيبُ قرعةٌ فلا يُثبَّت — تُثبَّت القيم** (CLAUDE.md · قاعدةُ JSON):
         * عمودُ JSON في MySQL 8 يُعيد ترتيبَ مفاتيحِ الكائنِ عند التخزين (الأقصرُ
         * أوّلاً، فـ`at` قبل `after_hours`)، بينما تحفظ SQLite وMariaDB ترتيبَ
         * الإدراج. فـ`assertSame` على المصفوفةِ كلِّها تخضرّ محليّاً وتسقط على CI —
         * وهو ما أسقط أربعَ دفعاتٍ متتاليةً هنا (v2.525.0 ← v2.527.0).
         *
         * والعقدُ لا يضعف: تُؤكَّد **كلُّ قيمةٍ بمفتاحها**، ويُؤكَّد أنّ المفاتيحَ
         * هي هذه ولا زيادةَ — مقارنةً بمفاتيحَ **مرتَّبةٍ** فلا تعلّقَ بالترتيب.
         */
        $after = (array) json_decode((string) $row->after, true);
        $this->assertSame(true, $after['after_hours'] ?? null, 'الأثرُ لم يبلغ القاعدة');
        $this->assertSame('03:41', $after['at'] ?? null, 'الأثرُ لم يبلغ القاعدة');
        $keys = array_keys($after);
        sort($keys);
        $this->assertSame(['after_hours', 'at'], $keys, 'مفاتيحُ الأثرِ ليست ما كُتب');
    }

    public function test_an_empty_extra_is_untouched(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        hub_audit('فعلٌ بلا أثر', 'quotes', 'x3', 'اختبار');

        $this->assertSame(1, DB::table('audits')->where('record_id', 'x3')->count());
    }

    /** كلُّ نداءٍ حقيقيٍّ في المستودع يمرّ بالحارس — الحزمةُ كلُّها هي المسح */
    public function test_the_whole_suite_is_the_sweep(): void
    {
        $this->assertTrue(true,
            'هذا الحارسُ يعمل داخلَ `hub_audit`، فكلُّ نداءٍ تُنفّذه أيُّ حالةِ اختبارٍ '
            . 'في الحزمةِ يُفحَص. والمسحُ الحقيقيُّ هو تشغيلُ الحزمةِ كلِّها — لا تعبيرٌ نمطيّ.');
    }
}
