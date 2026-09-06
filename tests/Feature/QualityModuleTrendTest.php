<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Project;
use App\Support\DataQuality;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **اتّجاهُ كل وحدة** (spec §6.11 · §47 «أيُّ الوحدات تتدهور؟ ما الذي تحسّن؟») — WP-8.2.
 *
 * اللقطةُ اليومية كانت تُكتب لـ`org` وحدها: درجةٌ واحدة لثلاثٍ وسبعين وحدة.
 * فالسؤالُ «أيُّ وحدةٍ تتدهور» لم يكن له **مصدرٌ يُقاس**، وأيُّ جوابٍ عنه ادّعاء.
 * هنا تُكتب نقطةٌ لكل وحدة، فيصير الجوابُ فرقاً بين نقطتين في السلسلة نفسِها.
 *
 * والقاعدةُ الثانية: **بلا لقطتين لا اتّجاه**. «صفر تغيّر» جوابٌ كاذبٌ عن
 * سؤالٍ لم يُقَس بعد — فالحالةُ الفارغة تصارح ولا تخترع صفراً.
 */
class QualityModuleTrendTest extends TestCase
{
    /** بلا لقطاتٍ لا اتّجاه — والشاشةُ تصارح ولا تعرض صفراً ملفَّقاً */
    public function test_without_snapshots_the_trend_is_an_honest_empty_state(): void
    {
        $this->seedCore();
        Client::create(['name' => 'عميلٌ يتيم']);

        $this->assertSame([], DataQuality::moduleTrend(),
            'اتّجاهٌ بلا لقطةٍ واحدة — رقمٌ مخترَع');

        // (WP-8.1) اتّجاهُ الوحدات صار تبويبَ «الاتّجاهات» في المركز المبوَّب
        $html = $this->actingAs($this->owner)->get('/admin/quality?tab=trends')->assertOk()->getContent();
        $this->assertStringContainsString('لا لقطات بعد', $html,
            'الشاشةُ لا تصارح بأنّ الاتّجاه لم يُقَس بعد');
    }

    /** لقطةٌ واحدة ليست اتّجاهاً: الفرقُ `null` لا صفر */
    public function test_a_single_snapshot_is_not_a_zero_delta(): void
    {
        $this->seedCore();
        Client::create(['name' => 'عميلٌ يتيم']);

        DataQuality::snapshot();
        $t = DataQuality::moduleTrend();

        $this->assertArrayHasKey('clients', $t, 'اللقطةُ لا تُكتب لكل وحدة — «أيُّ الوحدات تتدهور؟» بلا مصدر');
        $this->assertSame(1, $t['clients']['points']);
        $this->assertNull($t['clients']['delta'], 'لقطةٌ واحدة تُقرأ «لا تغيّر» — وهي «لم يُقَس بعد»');
        $this->assertNull($t['clients']['dir']);
    }

    /** لقطتان بقيمتين ⇒ وسمُ تحسّنٍ للنازل وتدهورٍ للصاعد، بالفرق المحسوب */
    public function test_two_snapshots_label_improvement_and_deterioration(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركة', 'status' => 'نشطة']);

        // الحالُ قبل عشرة أيام: أربعةُ عملاءَ بلا شركة، ومشروعٌ مكتملُ البيانات
        $ids = collect(range(1, 4))->map(fn ($i) => Client::create(['name' => "عميل {$i}"])->id);
        $p1 = Project::create(['name' => 'مشروعٌ سليم', 'status' => 'قيد التنفيذ']);
        DB::table('projects')->where('id', $p1->id)
            ->update(['company_id' => $co->id, 'manager_id' => $this->owner->id]);

        DataQuality::snapshot(now()->subDays(10)->toDateString());

        // واليوم: أُصلح ثلاثةُ عملاء، ودخل المشاريعَ مشروعٌ بلا شركةٍ ولا مسؤول
        DB::table('clients')->whereIn('id', $ids->take(3)->all())->update(['company_id' => $co->id]);
        Project::create(['name' => 'مشروعٌ ناقص', 'status' => 'قيد التنفيذ']);

        DataQuality::snapshot();
        $t = DataQuality::moduleTrend();

        $this->assertSame('improving', $t['clients']['dir'] ?? null,
            'ثلاثةُ نواقصَ أُغلقت ولا يظهر التحسّن');
        $this->assertSame(4, $t['clients']['was']);
        $this->assertSame(1, $t['clients']['defects']);
        $this->assertSame(-3, $t['clients']['delta']);

        $this->assertSame('worsening', $t['projects']['dir'] ?? null,
            'وحدةٌ دخلها نقصان ولا تُوسَم متدهورة');
        $this->assertSame(0, $t['projects']['was']);
        $this->assertSame(2, $t['projects']['delta'], 'الفرقُ ليس عدَّ النواقص اليوم بل التغيّرَ عن اللقطة الأولى');

        // والشاشةُ تُظهر الوجهين — ما تدهور وما تحسّن (تبويب «الاتّجاهات»، WP-8.1)
        $html = $this->actingAs($this->owner)->get('/admin/quality?tab=trends')->assertOk()->getContent();
        $this->assertStringContainsString('تدهورت', $html);
        $this->assertStringContainsString('تحسّنت', $html);
    }

    /**
     * كلفةُ القراءة **لا تنمو بعدد الوحدات ولا بطول المدى**: طرفا كل سلسلة
     * بالتجميع، لا ستّون يوماً × مئةُ سلسلةٍ تُسحب لقراءة نقطتين.
     */
    public function test_the_trend_read_costs_a_fixed_number_of_queries(): void
    {
        $this->seedCore();
        Client::create(['name' => 'عميلٌ يتيم']);
        Project::create(['name' => 'مشروعٌ ناقص', 'status' => 'قيد التنفيذ']);

        foreach (range(1, 6) as $i) DataQuality::snapshot(now()->subDays($i)->toDateString());

        $n = 0;
        $count = false;
        DB::listen(function () use (&$n, &$count) { if ($count) $n++; });
        $count = true;
        $t = DataQuality::moduleTrend();
        $count = false;

        $this->assertGreaterThan(1, count($t));
        $this->assertLessThanOrEqual(4, $n,
            "قراءةُ الاتّجاه كلّفت {$n} استعلاماً — استعلامٌ لكل وحدةٍ أو لكل يوم");
    }

    /** أوّلُ رصدٍ وعمرُ النقص من **أوّل نقطةٍ** في السلسلة لا من تاريخ اليوم */
    public function test_first_seen_and_age_come_from_the_first_point(): void
    {
        $this->seedCore();
        Client::create(['name' => 'عميلٌ يتيم']);

        DataQuality::snapshot(now()->subDays(10)->toDateString());
        DataQuality::snapshot();

        $t = DataQuality::moduleTrend();

        $this->assertSame(now()->subDays(10)->toDateString(),
            substr((string) ($t['clients']['first_seen'] ?? ''), 0, 10),
            'أوّلُ رصدٍ يُقرأ من آخر لقطةٍ لا من أوّلها — فكلُّ نقصٍ يبدو وليدَ اليوم');
        $this->assertSame(10, $t['clients']['age_days']);

        // ووحدةٌ لا نقصَ فيها قطُّ لا عمرَ لها — لا صفرَ يوماً
        $clean = collect($t)->first(fn ($m) => $m['defects'] === 0 && $m['was'] === 0);
        if ($clean) $this->assertNull($clean['age_days'], 'وحدةٌ نظيفةٌ يُنسب إليها عمرُ نقص');
    }
}
