<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Service;
use Tests\TestCase;

/**
 * الموجة السابعة — **بقايا خيط «رقمٌ واحد من عملاتٍ شتّى».**
 *
 * صُلّبت في v2.331 مسالكُ MRR والربحية والسوق، وبقيت ثلاثةُ منافذ:
 *
 *  ١) `hub_mrr` — العقودُ «مرة واحدة» تُجمع في `oneTime` **قبل** أن تُفصَل
 *     بالعملة، وعلمُ `mixed` مشتقٌّ من `byCurrency` المتكرّرة وحدها. فقاعدةٌ
 *     كلُّ متكرّرها بالدينار وعقودُها المرة‑واحدة بالدولار واليورو تُعلن
 *     `mixed = false` وتعرض `oneTime` رقماً واحداً بلا لصيقةٍ أصلاً.
 *  ٢) `costs/project.blade.php` — `hub_project_pl` صار يُرجع `mixed`
 *     و`byCurrency` (v2.331)، والشاشةُ تقرأ `currency` وحدها: صفحةُ ربحية
 *     المشروع تعرض إيراداً وتكلفةً وربحاً بلصيقةٍ واحدة ولا تقول إنّ خلفَها
 *     عملتين — وهي **بالضبط** الشاشة التي بُني لها `_mixedcur`.
 *  ٣) `pricing_body.blade.php` — بطاقةُ السوق كلُّها مشروطةٌ بـ`market.n`،
 *     وهو عددُ المنافسين **بعملتنا**. فخدمةٌ كلُّ منافسيها بعملةٍ أخرى تُخفي
 *     البطاقةَ بتمامها: لا نطاقَ ولا حتّى سطرُ «س منافساً خارج المقارنة» —
 *     فيقرأ صاحبُ القرار «لا منافس» حيث الحقيقةُ «منافسون لا يُقارَنون».
 */
class CurrencyHonestyRound7Test extends TestCase
{
    /* ── ١) عقودُ «مرة واحدة» تُفصَل بالعملة كأخواتها ── */

    public function test_one_time_contracts_are_split_by_currency(): void
    {
        $this->seedCore();
        $svc = Service::create(['name' => 'خدمة مرة واحدة', 'cycle' => 'مرة واحدة']);

        foreach ([['د.ك', 1000], ['$', 2000], ['د.ك', 500]] as $i => [$cur, $val]) {
            Contract::create(['title' => 'عقد ' . $i, 'type' => 'عقد عميل', 'status' => 'ساري',
                'value' => $val, 'currency' => $cur, 'service_id' => $svc->id,
                'date_start' => now()->toDateString()]);
        }

        $this->actingAs($this->owner);
        $mrr = hub_mrr(true);

        $this->assertSame(3500.0, (float) $mrr['oneTime'], 'المجموعُ الخام لم يتغيّر');
        $this->assertTrue($mrr['oneTimeMixed'] ?? false,
            '`oneTime` جمعَ ديناراً ودولاراً في رقمٍ واحد ولم يرفع علماً — '
            . 'ولا محرّكَ تحويلٍ في النظام، فهو رقمٌ لا معنى له بلا وسم');

        $by = collect($mrr['oneTimeByCurrency'] ?? [])->keyBy('currency');
        $this->assertSame(1500.0, (float) ($by['د.ك']['total'] ?? 0));
        $this->assertSame(2000.0, (float) ($by['$']['total'] ?? 0));
    }

    /** والعودةُ المبكرة تحمل المفاتيحَ الجديدة كذلك — لا `Undefined array key` بقاعدةٍ فارغة */
    public function test_the_empty_shape_carries_the_new_one_time_keys(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $mrr = hub_mrr(true);
        $this->assertArrayHasKey('oneTimeMixed', $mrr);
        $this->assertArrayHasKey('oneTimeByCurrency', $mrr);
        $this->assertFalse($mrr['oneTimeMixed']);
    }

    /* ── ٢) شاشةُ ربحية المشروع تعترف بالاختلاط ── */

    public function test_the_project_pl_screen_admits_mixed_currencies(): void
    {
        $src = (string) file_get_contents(base_path('resources/views/costs/project.blade.php'));

        $this->assertStringContainsString('_mixedcur', $src,
            'شاشةُ ربحية المشروع تعرض إيراداً وتكلفةً وربحاً بلصيقةِ عملةٍ واحدة '
            . 'بينما `hub_project_pl` يُرجع `mixed` — فالوسمُ محسوبٌ ولا يُعرض');
        $this->assertStringContainsString("\$pl['mixed']", $src,
            'الوسمُ يجب أن يكون مشروطاً بعلم `mixed` لا معروضاً دائماً');
    }

    /* ── ٣) بطاقةُ السوق لا تختفي حين يكون المنافسون بعملةٍ أخرى ── */

    public function test_the_market_card_still_names_rivals_in_another_currency(): void
    {
        $src = (string) file_get_contents(base_path('resources/views/pricing_body.blade.php'));

        // شرطُ البطاقة يجب أن يقبل الحالتين: نطاقٌ قابلٌ للمقارنة **أو** منافسون خارجه
        $this->assertMatchesRegularExpression(
            "/@if \(\(\\\$s\['market'\]\['n'\].*others.*\)\)/s", $src,
            'بطاقةُ السوق مشروطةٌ بـ`n` وحده — فخدمةٌ كلُّ منافسيها بعملةٍ أخرى '
            . 'تُخفي البطاقةَ كلَّها، ويُقرأ غيابُها «لا منافس» لا «لا يُقارَنون»');
    }

    /** والشرطُ يعمل حيّاً لا نصّاً: خدمةٌ بمنافسٍ بعملةٍ أخرى تُظهر السطر */
    public function test_a_rival_in_another_currency_is_still_shown_on_screen(): void
    {
        $this->seedCore();
        $this->hubSetting('app.currency', 'د.ك');

        $svc = Service::create(['name' => 'خدمة منافَسة', 'cycle' => 'شهري']);
        \App\Models\PricingPlan::create(['service_id' => $svc->id, 'name' => 'باقة',
            'price' => 100, 'currency' => 'د.ك', 'cycle' => 'شهري']);
        \App\Models\Competitor::create(['name' => 'منافس أجنبي', 'service_id' => $svc->id,
            'price_from' => 300, 'price_to' => 400, 'currency' => '$']);

        $res = $this->actingAs($this->owner)->get('/pricing');
        $res->assertOk();
        $res->assertSee('خارج المقارنة', false);
    }
}
