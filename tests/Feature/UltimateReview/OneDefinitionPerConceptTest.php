<?php

namespace Tests\Feature\UltimateReview;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **المفهومُ الواحدُ تعريفٌ واحد.**
 *
 * وجدت المراجعةُ الشاملةُ ثلاثةَ أعراضٍ لمرضٍ واحد:
 *  - **F-05**: «متوقّف» عُرِّف مرّتين في ملفٍّ واحد — `hub_project_is_paused`
 *    بأربعِ قيمٍ بلا «موقوف»، وكاشفُ الركودِ بستٍّ معها. وفي البياناتِ الحيّة
 *    مشروعٌ `متوقف` بميزانيّة 6,000 ومشروعٌ `موقوف` بميزانيّة **363,000** —
 *    فالمنطقُ يمسّ الأصغرَ ويعمى عن الأكبر. (وهو عيبُ دفعةِ v2.539.0.)
 *  - **F-13**: خريطةُ النظام تعرض شاراتٍ بلا روابطَ لأنّ `foreach ($x ?? [] as &$s)`
 *    يكتب في **قيمةٍ مؤقّتة** لا في المصفوفة.
 */
class OneDefinitionPerConceptTest extends TestCase
{
    #[DataProvider('pausedSpellings')]
    public function test_every_spelling_of_paused_is_one_definition(string $status): void
    {
        $this->assertTrue(hub_project_is_paused($status),
            "الحالةُ «{$status}» يعدّها كاشفُ الركودِ توقّفاً ولا يعدّها حاسبُ الصحّة — "
            . 'تعريفان لمفهومٍ واحد (F-05)');
    }

    public static function pausedSpellings(): array
    {
        // الستُّ التي يعرفها كاشفُ الركودِ في helpers.php — والحاسبُ كان يعرف أربعاً
        return [['متوقف'], ['موقوف'], ['معلّق'], ['مُعلّق'], ['مؤجل'], ['مجمّد']];
    }

    public function test_a_running_project_is_not_treated_as_paused(): void
    {
        foreach (['قيد التنفيذ', 'نشط', 'مكتمل', 'تخطيط', 'مراجعة'] as $s) {
            $this->assertFalse(hub_project_is_paused($s),
                "الحالةُ «{$s}» ليست توقّفاً — توسيعُ التعريفِ يجب ألّا يبتلع العاملَ منها");
        }
    }

    public function test_the_system_map_renders_real_links(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/system-map')->assertOk()->getContent();

        // الخريطةُ بلا رابطٍ واحدٍ حيّ = شاراتٌ ميّتة. الحارسُ القائمُ يطرق روابطَ
        // الشريطِ والترويسةِ ولا يطرق الخريطةَ إطلاقاً — فبقي العطبُ سنواتٍ خضراءَ.
        /*
         * **الطرقُ على روابطِ الصفحةِ كلِّها لا يقيس الخريطة** — وهذا بعينُه سببُ بقاءِ
         * العطبِ خضراءَ: الحارسُ القائم (`SearchAndMapOffer…Test`) يطرق ٨٣ رابطاً
         * كلُّها من الشريطِ والترويسة، فيمرّ والخريطةُ صفرُ روابط. (سقطتُ في الفخِّ
         * نفسِه في أوّلِ صياغةٍ لهذا الاختبار: مرّ وهو لا يقيس شيئاً.)
         * فالقياسُ هنا على **وسمِ الخريطةِ نفسِه**: `<a class="btn ghost xs" href=…>`.
         */
        $this->assertMatchesRegularExpression('~<a class="btn ghost xs" href="[^"]+"~', $html,
            'خريطةُ النظام تعرض شاراتٍ بلا رابطٍ حيٍّ واحد — `foreach ($x ?? [] as &$s)` '
            . 'يكتب في قيمةٍ مؤقّتةٍ فتضيع العناوين صامتةً (F-13)');
    }
}
