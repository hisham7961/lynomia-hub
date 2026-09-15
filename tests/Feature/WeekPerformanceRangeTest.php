<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **W-2 · المدى مقبضٌ مقطوعٌ ولافتةٌ لا تتبع** (الطور ١٦٧ · اليوم ٣).
 *
 * سؤالُ الخاتمةِ الذي «لا يُجاب من المنتج»: **«ماذا أُنجز هذا الأسبوعَ مقابلَ
 * الماضي؟»**. وتبيّن أنّ كلَّ قطعِه موجودة:
 *
 *  · `tasks.completed_at` قائمٌ ومُفهرَسٌ و**مملوءٌ ٤٣ من ٤٣**.
 *  · `ExecutionStats::people($ids, $range)` يقبل **أيَّ مدى** ويقرأ منه.
 *  · `hub_range()` تفهم `?range=7d|30d|90d` وتقرأ الطلبَ الحيَّ حين تُعطى `null`.
 *
 * **والوصلةُ وحدَها مقطوعة** — في سطرَين:
 *
 *  ١) `PerformanceController` كان يُنشئ **طلباً فارغاً جديداً**
 *     (`hub_range(new Request(), '30d')`) بدل الطلبِ الوارد، فلا يبلغ `?range=`
 *     الشاشةَ أبداً. والموضعُ كان **وحيداً في المستودع كلِّه**.
 *
 *  ٢) والعنوانُ نصٌّ ثابتٌ في القالب («آخر ٣٠ يوماً»)، فلو عمل المدى **لكذبت
 *     اللافتة**. وهو صنفُ W-1 نفسُه: لافتةٌ لا تتبع ما تصفه.
 *
 * فالعقدُ المُثبَّت هنا: **المدى يُقرأ، واللافتةُ تقول المدى المقروءَ لا نصّاً
 * محفوظاً** — والافتراضُ يبقى ثلاثين يوماً فلا تنكسر عادةُ أحد.
 */
class WeekPerformanceRangeTest extends TestCase
{
    private function heading(string $html): string
    {
        return preg_match('/أداء الموظفين — ([^<]{1,40})/u', $html, $m) ? trim($m[1]) : '';
    }

    /** بلا وسيطٍ يبقى الافتراضُ كما كان — إصلاحٌ لا يكسر عادة */
    public function test_the_default_window_is_still_thirty_days(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/performance')->assertOk()->getContent();

        $this->assertStringContainsString('٣٠', $this->heading($html),
            'الافتراضُ تغيّر — والإصلاحُ لا يُفترض أن يمسّ الحالةَ الشائعة');
    }

    /**
     * **المقبضُ موصول:** `?range=7d` يُغيّر النافذةَ فعلاً، واللافتةُ تتبعها.
     * وهذا ما يجعل «هذا الأسبوع» سؤالاً يُجاب من الشاشةِ لا من إكسل.
     */
    public function test_an_explicit_range_is_honoured_and_the_label_follows_it(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/performance?range=7d')->assertOk()->getContent();
        $h = $this->heading($html);

        $this->assertStringNotContainsString('٣٠', $h,
            'طُلب مدى ٧ أيّامٍ وبقيت اللافتةُ تقول ٣٠ — المقبضُ مقطوعٌ أو اللافتةُ نصٌّ ثابت');
        $this->assertStringContainsString('٧', $h,
            'اللافتةُ لا تذكر المدى المطلوب');
    }

    /** ومدىً ثالثٌ — لئلّا يُثبَّت العقدُ على قيمةٍ واحدةٍ بالصدفة */
    public function test_a_third_range_is_honoured_too(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/performance?range=90d')->assertOk()->getContent();

        $this->assertStringContainsString('٩٠', $this->heading($html));
    }
}
