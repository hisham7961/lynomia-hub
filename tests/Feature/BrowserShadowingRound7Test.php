<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * الموجة السابعة — ما كشفه **الفحص الحيّ وحده**.
 *
 * الحزمةُ كلُّها تُشغّل PHP ولا تُشغّل متصفحاً، فخطأُ جافاسكربت لا يُحمّرها
 * مهما عطّل ميزةً كاملة. وفتحُ الشاشة في متصفحٍ حقيقيّ أظهر استثناءً في كل
 * ضغطة مفتاح على شاشة المستند المالي:
 *
 *     TypeError: (f.method || "").toLowerCase is not a function
 *
 * **العلّة**: DOM يجعل عناصرَ النموذج خصائصَ عليه بأسمائها، فحقلٌ اسمه `method`
 * — وهو موجودٌ فعلاً: «طريقة الدفع» عمودُها `method` في المالية والمشتريات —
 * **يُظلّل** الخاصيةَ المبنية `form.method` فتُعيد العنصرَ لا النصّ.
 *
 * **والأثر ليس ضجيجاً في الكونسول**: المستمعُ ينقطع عند الاستثناء قبل بلوغ
 * `dirty = true`، فحارسُ المغادرة يُطفأ على تلك الشاشة بعينها — يكتب المستخدم
 * مستنداً ماليّاً كاملاً ثم يغادر الصفحة فلا يُسأل ولا يُحذَّر، ويضيع ما كتب
 * صامتاً. وهو بالضبط ما وُضع الحارسُ لأجله.
 *
 * الحارسُ هنا مصدريّ لأن الحزمة بلا متصفح: يمنع **عودة** النمط لا يُثبت غيابه.
 */
class BrowserShadowingRound7Test extends TestCase
{
    /** أسماءُ خصائصِ `HTMLFormElement` التي يُظلّلها حقلٌ يحمل الاسمَ نفسه */
    private const SHADOWING = ['method', 'action', 'target', 'elements', 'name',
                               'reset', 'submit', 'length', 'enctype'];

    /**
     * الخصائصُ التي تُقرأ **على النموذج** فعلاً في هذا الملف — وهي وحدها
     * موضعُ الحراسة. `length` و`name` عامّتان تُقرآن على مصفوفاتٍ وعناصرَ
     * أخرى، فحراستُهما هنا ضجيجٌ لا إشارة.
     */
    private const FORM_ONLY = ['method', 'action', 'elements', 'enctype'];

    /** يُزيل التعليقات كي لا يُحمَّر الاختبارُ بشرحِ العلّة نفسِه */
    private function code(): string
    {
        $js = (string) file_get_contents(public_path('js/app.js'));
        $js = (string) preg_replace('~/\*.*?\*/~s', '', $js);

        return (string) preg_replace('~^\s*//.*$~m', '', $js);
    }

    public function test_the_leave_guard_reads_the_attribute_not_the_shadowable_property(): void
    {
        $js = $this->code();

        $this->assertStringNotContainsString("(f.method || '')", $js,
            'حارسُ المغادرة يقرأ `form.method` خاصيةً — وحقلٌ اسمه method يُظلّلها '
            . 'فيرمي استثناءً في كل ضغطة مفتاح، ويُطفأ الحارسُ على شاشة المستند المالي');

        $this->assertStringContainsString("f.getAttribute('method')", $js,
            'السمةُ لا تُظلَّل — فتُقرأ منها');
    }

    public function test_no_form_property_is_read_where_a_field_can_shadow_it(): void
    {
        $js = $this->code();

        foreach (self::FORM_ONLY as $prop) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(?:f|form)\.' . $prop . '\b(?!\s*=)/', $js,
                "قراءةُ `form.{$prop}` خاصيةً — وحقلٌ يحمل الاسمَ نفسَه يُظلّلها "
                . 'فيصير السلوكُ رهناً بمحتوى النموذج. اقرأ السمةَ أو استعلم عن العناصر');
        }
    }

    /** والأسماءُ الثلاثةُ الخطرة موجودةٌ فعلاً في السجل — فالخطرُ واقعٌ لا نظريّ */
    public function test_the_registry_really_contains_shadowing_field_names(): void
    {
        $cols = [];
        foreach (config('hub.modules') as $md) {
            foreach (($md['fields'] ?? []) as $f) {
                if (! empty($f['col'])) $cols[] = (string) $f['col'];
            }
        }

        $risky = array_values(array_intersect(array_unique($cols), self::SHADOWING));
        $this->assertNotEmpty($risky,
            'لا حقلَ بأسماء الخصائص في السجل — الاختبارُ يحرس خطراً لا وجودَ له بعد');
        $this->assertContains('method', $risky,
            'عمودُ «طريقة الدفع» هو method — وهو مصدرُ العطل المرصود حيّاً');
    }
}
