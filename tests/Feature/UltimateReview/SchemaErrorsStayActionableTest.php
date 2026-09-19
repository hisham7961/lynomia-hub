<?php

namespace Tests\Feature\UltimateReview;

use App\Support\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **عطلٌ بنيويٌّ يجب أن يبقى قابلاً للإصلاحِ من شاشتِه** (المراجعةُ الشاملة ·
 * الطبقة ٢ · L2-04).
 *
 * `ErrorLog::safeMessage` تُعلن عقدَها حرفيّاً في توثيقِها:
 *
 * > «نُبقي رمزَ الحالة **ووصفَ القيد/العمود**، ونحذف مقطعَ SQL ونطمس القيمَ
 * > المقتبسة.»
 *
 * **والتنفيذُ كان يناقض العقد**: `Redactor::sql` تطمس **كلَّ** رمزٍ مقتبس، واسمُ
 * العمودِ في رسائل المحرّك مقتبسٌ هو الآخر — فتصير
 * `Unknown column 'act_h' in 'field list'` ⇒ `Unknown column '…' in '…'`.
 * يقرؤها المشغّلُ في مركزِ الأخطاء فلا يعرف **أيَّ عمودٍ** ولا **أيَّ جدول**،
 * والعطلُ البنيويُّ غيرُ قابلٍ للإصلاحِ من شاشتِه.
 *
 * **والتمييزُ الذي يحسم الأمر:** اسمُ العمودِ والجدولِ والقيدِ **بيانُ مخطَّطٍ
 * لا بيانُ مستخدم** — لا راتبَ فيه ولا بريداً ولا سرّاً. أمّا `Duplicate entry
 * 'ahmed@example.com'` فقيمةٌ حقيقيّةٌ من صفٍّ حقيقيّ، وطمسُها هو الغاية.
 * فالقاعدةُ: **المعرّفُ يبقى والقيمةُ تُطمَس** — لا «كلُّ ما بين علامتَي اقتباس».
 */
class SchemaErrorsStayActionableTest extends TestCase
{
    // ── ① المعرّفاتُ البنيويّةُ تبقى — وإلّا فالعطلُ غيرُ قابلٍ للإصلاح ──────

    public static function structuralCases(): array
    {
        return [
            'عمودٌ مجهول' => [
                "SQLSTATE[42S22]: Unknown column 'act_h' in 'field list'",
                ['act_h', 'field list'],
            ],
            'جدولٌ غائب' => [
                "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'hub.notifications_hub' doesn't exist",
                ['hub.notifications_hub'],
            ],
            'عمودٌ لا يقبل العدم' => [
                "SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'company_id' cannot be null",
                ['company_id'],
            ],
            'حقلٌ بلا قيمةٍ افتراضيّة' => [
                "SQLSTATE[HY000]: Field 'secrecy' doesn't have a default value",
                ['secrecy'],
            ],
            'نصٌّ أطولُ من عرضِ عمودِه' => [
                "SQLSTATE[22001]: Data too long for column 'kind' at row 1",
                ['kind'],
            ],
            'عددٌ خارجَ مداه' => [
                "SQLSTATE[22003]: Out of range value for column 'lighthouse' at row 1",
                ['lighthouse'],
            ],
        ];
    }

    #[DataProvider('structuralCases')]
    public function test_a_structural_error_still_names_what_broke(string $raw, array $mustKeep): void
    {
        $out = Redactor::sql($raw);

        foreach ($mustKeep as $ident) {
            $this->assertStringContainsString($ident, $out,
                "العطلُ البنيويُّ فقد «{$ident}» فصار غيرَ قابلٍ للإصلاحِ من شاشتِه — "
                . "واسمُ العمود/الجدولِ بيانُ مخطَّطٍ لا بيانُ مستخدم.\nالناتج: {$out}");
        }
    }

    // ── ② والقيمُ الحقيقيّةُ تبقى مطموسة — الغايةُ الأصليّةُ لم تُنقَض ────────

    public function test_a_duplicate_entry_value_is_still_masked_but_the_key_is_named(): void
    {
        $out = Redactor::sql(
            "SQLSTATE[23000]: Duplicate entry 'ahmed.alqahtani@premiercare.com.kw' for key 'users_email_unique'");

        $this->assertStringNotContainsString('ahmed.alqahtani', $out,
            'قيمةُ الصفِّ الحقيقيّةُ (بريدٌ) تسرّبت إلى مركزِ الأخطاء — وهي الغايةُ الأصليّةُ للطمس');
        $this->assertStringContainsString('users_email_unique', $out,
            'اسمُ القيدِ طُمس فلا يعرف المشغّلُ **أيَّ** تفرُّدٍ انتُهك');
    }

    public function test_an_incorrect_value_is_masked_while_its_column_is_named(): void
    {
        $out = Redactor::sql(
            "SQLSTATE[HY000]: Incorrect integer value: '٩٩٩٩٩٩٩٩٩' for column 'downloads' at row 1");

        $this->assertStringNotContainsString('٩٩٩٩٩٩٩٩٩', $out, 'القيمةُ المرفوضةُ بيانُ صفٍّ — تُطمَس');
        $this->assertStringContainsString('downloads', $out, 'واسمُ العمودِ يبقى — به يُصلَح العطل');
    }

    /** ولا يعود مقطعُ SQL بقيمِه المربوطة أبداً */
    public function test_the_bound_sql_tail_is_still_removed(): void
    {
        $out = Redactor::sql(
            "SQLSTATE[42S22]: Unknown column 'act_h' in 'field list' "
            . "(Connection: mysql, SQL: update `tasks` set `act_h` = act_h + 7 where `id` = 'SECRET-ID-8831')");

        $this->assertStringNotContainsString('SECRET-ID-8831', $out, 'القيمُ المربوطةُ عادت مع مقطعِ SQL');
        $this->assertStringNotContainsString('Connection:', $out, 'مقطعُ SQL لم يُحذف');
        $this->assertStringContainsString('act_h', $out, 'واسمُ العمودِ يبقى');
    }

    /** والنصُّ الحرُّ لا يمسّه هذا — القاعدةُ لرسائلِ المحرّكِ وحدَها */
    public function test_a_free_text_quote_is_still_masked(): void
    {
        $out = Redactor::sql("فشل الحفظ للقيمة 'راتب فلان 12500'");
        $this->assertStringNotContainsString('12500', $out,
            'اقتباسٌ في نصٍّ حرٍّ ليس معرّفَ مخطَّطٍ — يبقى مطموساً');
    }
}
