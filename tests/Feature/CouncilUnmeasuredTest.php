<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **مجلسُ الخبراء · PROD-10 / CEO-06 — «صفرٌ من صفرٍ = ١٠٠٪».**
 *
 * رأى المالكُ في `/ceo` ← «🩺 تقرير صحة الشركة» ستَّ درجات، أعلاها:
 * **«الامتثال ١٠٠٪ — ٠ عقد و٠ دومين متجاوز للنهاية (من ٠)»** — ووحدةُ العقودِ
 * عنده **فارغةٌ تماماً**. أي أنّ **أعلى درجةٍ في تقريرِ صحّةِ الشركةِ سببُها
 * أنّه لا توجد بياناتٌ أصلاً**.
 *
 * ووصفه الخبيرُ بأنّه **«أخطرُ كذبةٍ يقولها نظامٌ لصاحبِه»** — وهو محقّ: الصفرُ
 * هنا يعني **«لم يُقَس»** لا **«ممتاز»**.
 *
 * **وهو صنفٌ لا حادثة:** النمطُ `100 - ($tot ? … : 0)` يتكرّر في أبعادِ التقريرِ
 * كلِّها — فكلُّ بُعدٍ بلا بياناتٍ يُعطي مئةً كاملة، **وتُحسب في المتوسّطِ العامّ
 * فترفعه**.
 *
 * **ولا قدرةَ تُنزع:** البُعدُ يبقى في التقرير، ودرجتُه تبقى تُحسب حين تُوجد
 * بيانات. وإنّما يُعلَن «لا بيانات» بدل مئةٍ كاذبة، **ويخرج من متوسّطٍ لا يمثّله**.
 */
class CouncilUnmeasuredTest extends TestCase
{
    public function test_a_dimension_with_no_data_is_not_scored_a_perfect_hundred(): void
    {
        $this->seedCore();

        // لا عقودَ ولا دومينات — بُعدُ الامتثالِ بلا أيِّ بيان
        \Illuminate\Support\Facades\DB::table('contracts')->delete();
        \Illuminate\Support\Facades\DB::table('domains')->delete();

        $h = hub_health(true);
        $this->assertArrayHasKey('الامتثال', $h,
            'بُعدُ الامتثالِ اختفى من التقرير — قدرةٌ نُزعت، والمطلوبُ صدقُه لا حذفُه');

        $dim = $h['الامتثال'];
        $this->assertFalse((bool) ($dim['measured'] ?? true),
            'بُعدٌ بلا بياناتٍ يُعلَن مقيساً — فيُقرأ رقمُه بوصفِه حكماً وهو فراغ');
        $this->assertNotSame(100, (int) ($dim['score'] ?? 0),
            'صفرٌ من صفرٍ يُعطي ١٠٠٪ — «أخطرُ كذبةٍ يقولها نظامٌ لصاحبِه»');
    }

    public function test_a_dimension_with_real_data_is_still_scored(): void
    {
        $this->seedCore();
        \Illuminate\Support\Facades\DB::table('contracts')->delete();
        \Illuminate\Support\Facades\DB::table('domains')->delete();
        \Illuminate\Support\Facades\DB::table('domains')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'lynomia.test',
            'expiry' => now()->addYear()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $dim = hub_health(true)['الامتثال'] ?? [];
        $this->assertTrue((bool) ($dim['measured'] ?? false),
            'بُعدٌ فيه بياناتٌ أُعلن غيرَ مقيس — الحارسُ أوسعُ ممّا يجب وقد أطفأ مقياساً صحيحاً');
        $this->assertSame(100, (int) ($dim['score'] ?? 0),
            'دومينٌ سليمٌ لا يُنقص الدرجة — والحسابُ القديمُ يبقى كما هو حين تُوجد بيانات');
    }

    public function test_unmeasured_dimensions_do_not_inflate_the_overall_average(): void
    {
        $this->seedCore();
        \Illuminate\Support\Facades\DB::table('contracts')->delete();
        \Illuminate\Support\Facades\DB::table('domains')->delete();

        $h = hub_health(true);
        $scored = collect($h)->filter(fn ($d) => is_array($d) && ($d['measured'] ?? true));

        $this->assertTrue($scored->every(fn ($d) => ($d['measured'] ?? true)),
            'بُعدٌ غيرُ مقيسٍ تسرّب إلى المقيسة');
        $this->assertFalse(
            collect($h)->contains(fn ($d) => is_array($d) && ! ($d['measured'] ?? true) && (int) ($d['score'] ?? 0) === 100),
            'بُعدٌ غيرُ مقيسٍ ما زال يحمل مئةً — فيرفع المتوسّطَ العامَّ بلا سبب');
    }

    public function test_a_weighted_forecast_with_no_probabilities_is_not_reported_as_zero(): void
    {
        $this->seedCore();
        \Illuminate\Support\Facades\DB::table('clients')->delete();
        \Illuminate\Support\Facades\DB::table('clients')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'عميلٌ بلا احتمال',
            'stage' => 'تفاوض', 'value' => 251000, 'prob' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $pl = \App\Support\Finance\SalesBoard::data()['pipeline'] ?? [];
        $this->assertSame(251000.0, (float) ($pl['raw'] ?? 0),
            'الرقمُ الخامُ اختفى — والمطلوبُ توضيحُ المرجَّحِ لا إخفاءُ الخام');
        $this->assertFalse((bool) ($pl['weighted_measured'] ?? true),
            '«التنبّؤ المرجَّح 0.000» بجانب خطِّ أنابيبَ ٢٥١٬٠٠٠ — والرقمُ غيابُ '
            . 'مُدخَلٍ لا تنبّؤٌ بصفر');
    }

    public function test_a_weighted_forecast_with_probabilities_is_still_measured(): void
    {
        $this->seedCore();
        \Illuminate\Support\Facades\DB::table('clients')->delete();
        \Illuminate\Support\Facades\DB::table('clients')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'عميلٌ باحتمال',
            'stage' => 'تفاوض', 'value' => 100000, 'prob' => 40,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $pl = \App\Support\Finance\SalesBoard::data()['pipeline'] ?? [];
        $this->assertTrue((bool) ($pl['weighted_measured'] ?? false),
            'صفقةٌ باحتمالٍ أُعلنت غيرَ مقيسة — الحارسُ أطفأ مقياساً صحيحاً');
        $this->assertSame(40000.0, (float) ($pl['weighted'] ?? 0),
            'الحسابُ القديمُ تغيّر — والمطلوبُ صدقُ العرضِ لا تغييرُ الرياضيّات');
    }
}
