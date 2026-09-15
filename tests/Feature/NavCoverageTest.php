<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **لا وحدةَ تختفي بلا قرارٍ مكتوب** (بلاغُ المالك · «المحطات غير ظاهرة»).
 *
 * الشريطُ الجانبيُّ يبني قسمَ «الوحدات» من `config/hub_nav.php` — قائمةٌ **منفصلةٌ**
 * عن سجلِّ الوحدات `config/hub.php`. فوحدةٌ غائبةٌ عن القائمةِ لا تظهر أبداً مهما
 * كانت صلاحيّاتُ المستخدم؛ لا حارسَ يمنع ذلك، ولا رسالةَ خطأ — تختفي صامتةً.
 *
 * وهكذا اختفت **ثلاثُ** وحداتٍ: `stations` (اكتشفها المالكُ صدفةً) و`endpoints`
 * و`restores` — وكلُّها مسجّلةٌ في خريطةِ المعلومات، لكنّ الشريطَ يعرض
 * **المساحاتِ وأقسامَها** لا الوحداتِ داخلَها، فالوصولُ إليها ثلاثُ خطواتٍ بلا اسم.
 *
 * هذا الاختبارُ يجعل الاختفاءَ **مستحيلاً بلا قرارٍ مُعلَن**: كلُّ وحدةٍ إمّا في
 * الشريطِ أو في الجدولِ أدناه **بسببها المكتوب**.
 */
class NavCoverageTest extends TestCase
{
    /**
     * وحداتٌ خارجَ قسمِ «الوحدات» **عمداً** — ولكلٍّ سببٌ وبابٌ بديلٌ مذكور.
     *
     * @return array<string,string>
     */
    private const DELIBERATE = [
        // بابُها «الإدارة ← المستخدمون والوصول» (مقصدٌ من نوع admin في خريطةِ المعلومات)
        'users' => 'تُدار من صفحةِ الإدارة لا من قائمةِ الوحدات',
        // مؤرشفةٌ صراحةً — وتسميتُها في السجلِّ تقولها: «الأتمتة (مؤرشفة — انظر مسارات العمل)»
        'autos' => 'مؤرشفةٌ لصالحِ «مسارات العمل» — والتسميةُ تُعلن ذلك',
        // أُخرجت من التنقّل **بطلبِ المالك** نفسِه، ويحرس القرارَ `RestoresRetiredTest`:
        // «المسارُ والبياناتُ باقيان — من يحتاجه يفتحه برابطِه المباشر»
        'restores' => 'أُخرجت من التنقّل بطلبِ المالك (RestoresRetiredTest) — لا سهواً',
        // لها **مركزٌ كامل** في خريطةِ المعلومات (`endpoints_center`) لا مجرّدَ قائمةِ
        // صفوف؛ وإدراجُ الوحدةِ الخامِ يخالف تناظرَ hub_nav ↔ IA (IaNavParityTest)
        'endpoints' => 'بابُها مركزُ النقاطِ الطرفيّة — سطحٌ أغنى من قائمةِ الوحدة',
    ];

    public function test_every_registered_module_is_reachable_from_the_sidebar(): void
    {
        $modules = array_keys(config('hub.modules', []));
        $this->assertNotEmpty($modules, 'سجلُّ الوحداتِ غيرُ فارغ');

        $inNav = [];
        foreach (config('hub_nav', []) as $g) {
            foreach (($g['items'] ?? []) as $k) $inNav[$k] = true;
        }

        $orphans = [];
        foreach ($modules as $m) {
            if (isset($inNav[$m]) || isset(self::DELIBERATE[$m])) continue;
            $orphans[] = $m . ' («' . config("hub.modules.$m.label", '?') . '»)';
        }

        sort($orphans);
        $this->assertSame([], $orphans,
            "وحداتٌ مسجَّلةٌ لا تظهر في الشريطِ الجانبيّ ولا سببَ مكتوبٌ لغيابِها:\n  - "
            . implode("\n  - ", $orphans)
            . "\nأضِفها إلى config/hub_nav.php، أو إلى DELIBERATE بسببٍ صريح.");
    }

    /** وكلُّ مفتاحٍ في الشريطِ يشير إلى وحدةٍ حقيقيّة — لا رابطَ لِما لا وجودَ له. */
    public function test_the_sidebar_lists_no_module_that_does_not_exist(): void
    {
        $modules = config('hub.modules', []);
        $ghosts = [];
        foreach (config('hub_nav', []) as $g) {
            foreach (($g['items'] ?? []) as $k) {
                if (! isset($modules[$k])) $ghosts[] = $g['g'] . ' ⇒ ' . $k;
            }
        }
        $this->assertSame([], $ghosts, 'مفاتيحُ في الشريطِ بلا وحدةٍ مقابلة: ' . implode(' · ', $ghosts));
    }

    /** وكلُّ استثناءٍ مكتوبٍ يخصّ وحدةً قائمةً — فلا يتضخّم الجدولُ بأشباح. */
    public function test_the_exception_table_has_no_stale_entries(): void
    {
        foreach (self::DELIBERATE as $k => $why) {
            $this->assertArrayHasKey($k, config('hub.modules', []),
                "استثناءٌ مكتوبٌ لوحدةٍ لم تعد موجودة: {$k}");
            $this->assertNotSame('', trim($why), "الاستثناءُ {$k} بلا سبب");
        }
    }
}
