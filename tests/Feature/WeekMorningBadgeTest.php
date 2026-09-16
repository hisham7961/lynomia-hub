<?php

namespace Tests\Feature;

use App\Models\Task;
use Tests\TestCase;

/**
 * **W-1 · الشارةُ تعدّ المشكلةَ لا مساحةَ العرض** (الطور ١٦٧ · اليوم ١).
 *
 * شاشةُ `/morning` تقصّ كلَّ بطاقةٍ إلى سقفٍ للعرض (`limit(8)`)، **والشارةُ
 * كانت تعدّ المقصوصَ** (`$c['rows']->count()`): فسِتَّ عشرةَ مهمّةً متأخّرةً
 * تُقرأ «٨».
 *
 * **والخطأُ في اتّجاهِ التهوينِ دائماً.** لا يُبالغ في الإنذارِ بل يُسكته:
 * كلّما ازدادت المتأخّراتُ ثبتت الشارةُ على سقفِها، **فيعمى المؤشّرُ كلّما
 * ازدادت الحاجةُ إليه**. ورقمٌ يكذب أخطرُ من فجوةٍ ظاهرة — الفجوةُ تدفع إلى
 * الفحص، والرقمُ الكاذبُ يمنع منه لأنّ قارئَه يظنّ أنّه يعرف.
 *
 * والعقدُ المُثبَّت هنا **بطرفَيه**: الشارةُ تقول الحقيقةَ كاملةً، والمسرودُ
 * يبقى مقصوصاً كما هو (فالإصلاحُ صدقٌ في العدّاد لا إغراقٌ للصفحة).
 */
class WeekMorningBadgeTest extends TestCase
{
    /** الشارةُ المعروضةُ بجانب عنوانِ بطاقةٍ بعينها */
    private function badge(string $html, string $title): ?int
    {
        // <h3>🔥 العنوان <span class="bdg">N</span></h3>
        $q = preg_quote($title, '/');
        if (preg_match('/' . $q . '\s*<span class="bdg">\s*(\d+)\s*<\/span>/u', $html, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /** عددُ الصفوفِ المسرودةِ داخلَ البطاقة (لإثباتِ أنّ القصَّ باقٍ) */
    private function listed(string $html, string $title): int
    {
        $q = preg_quote($title, '/');
        if (! preg_match('/' . $q . '.*?<table class="mini">(.*?)<\/table>/su', $html, $m)) return 0;

        return substr_count($m[1], '<tr>');
    }

    public function test_the_overdue_badge_counts_every_overdue_task_not_only_the_shown_ones(): void
    {
        $this->seedCore();

        // ستَّ عشرةَ مهمّةً متأخّرة — ضِعفا السقفِ المعروض
        for ($i = 1; $i <= 16; $i++) {
            Task::create([
                'title'  => "مهمّةٌ متأخّرة {$i}",
                'status' => 'جديدة',
                'due'    => now()->subDays($i)->format('Y-m-d'),
            ]);
        }

        $html = $this->actingAs($this->owner)->get('/morning')->assertOk()->getContent();

        $badge = $this->badge($html, 'مهام تجاوزت موعدها');
        $this->assertNotNull($badge, 'بطاقةُ المهامِّ المتأخّرةِ غائبةٌ عن الشاشة');

        $this->assertSame(16, $badge,
            'الشارةُ تعدّ ما عُرض لا ما وقع — ستَّ عشرةَ متأخّرةً تُقرأ «' . $badge . '»');

        // والطرفُ الآخر: القصُّ باقٍ فلا تُغرق الصفحةُ بالصفوف
        $this->assertLessThanOrEqual(8, $this->listed($html, 'مهام تجاوزت موعدها'),
            'الإصلاحُ أغرق البطاقةَ بالصفوفِ بدل أن يُصلح العدّادَ وحدَه');
    }

    /**
     * **وحين لا يتجاوز الواقعُ السقفَ تبقى الشارةُ كما كانت** — فالإصلاحُ لا
     * يُغيّر السلوكَ السليم، وهذا ما يمنع «إصلاحاً» يكسر الحالةَ الشائعة.
     */
    public function test_a_count_below_the_cap_is_unchanged(): void
    {
        $this->seedCore();

        for ($i = 1; $i <= 3; $i++) {
            Task::create([
                'title'  => "متأخّرةٌ صغيرة {$i}",
                'status' => 'جديدة',
                'due'    => now()->subDays($i)->format('Y-m-d'),
            ]);
        }

        $html = $this->actingAs($this->owner)->get('/morning')->assertOk()->getContent();

        $this->assertSame(3, $this->badge($html, 'مهام تجاوزت موعدها'));
        $this->assertSame(3, $this->listed($html, 'مهام تجاوزت موعدها'));
    }
}
