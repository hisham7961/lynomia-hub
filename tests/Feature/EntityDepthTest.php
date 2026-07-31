<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Service;
use Tests\TestCase;

/**
 * ثلاث وحداتٍ كانت أضيق من عملها:
 *  • المنافسون: خانةٌ **واحدة** نصية لكل حساباتهم على منصات التواصل، ولا سعرٌ
 *    مُقارَن، ولا تطبيقٌ لهم، ولا موعدَ مراجعةٍ فينسى المنافس سنةً كاملة.
 *  • العلامات: لا رقمَ تسجيلٍ بالملكية الفكرية ولا فئاتٍ ولا تاريخَ تجديد —
 *    والعلامة تسقط بانقضاء مدتها صمتاً. ولا ربطَ بمشروعٍ ولا بنطاقٍ ولا بحساب.
 *  • الخدمات: سعرٌ وتكلفة ولا شيء عن قيمتها ولا مخرجاتها ولا من ينافسها ولا
 *    الأفكار التي تطوّرها — والابتكار في وادٍ والخدمة في وادٍ.
 */
class EntityDepthTest extends TestCase
{
    public function test_competitor_has_a_channel_per_platform_not_one_box(): void
    {
        $keys = collect(hub_mod('competitors')['fields'])->pluck('key');
        foreach (['instagram', 'tiktok', 'x', 'linkedin', 'youtube', 'facebook'] as $k) {
            $this->assertTrue($keys->contains($k), "قناة المنافس $k غائبة — كانت خانةً واحدة للكل");
        }
        foreach (['priceFrom', 'priceTo', 'billing', 'freeTier', 'play', 'appstore', 'followers'] as $k) {
            $this->assertTrue($keys->contains($k), "حقل المنافس $k غائب");
        }
    }

    public function test_competitor_review_date_reaches_the_radar(): void
    {
        $this->seedCore();
        Competitor::create(['name' => 'منافس السوق', 'status' => 'يُراقب',
                            'next_review' => now()->addDays(9)->toDateString()]);

        $items = hub_expiry(true);
        $this->assertStringContainsString('منافس السوق', collect($items)->pluck('name')->implode(' | '),
            'موعد مراجعة المنافس لم يصل الرادار — فيُنسى سنةً كاملة');
    }

    public function test_competitor_followers_build_a_series(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/m/competitors', [
            'name' => 'منافس نامٍ', 'status' => 'نشط', 'followers' => 5000,
        ])->assertRedirect();

        $c = Competitor::where('name', 'منافس نامٍ')->firstOrFail();
        $this->assertSame(5000.0, hub_metric_latest('competitors', $c->id, 'followers'),
            'متابعو المنافس يُسجَّلون سلسلةً — الرقم وحده لا يقول أينمو أم ينكمش');
    }

    public function test_brand_carries_its_intellectual_property(): void
    {
        $keys = collect(hub_mod('brands')['fields'])->pluck('key');
        foreach (['projectId', 'tmNo', 'tmStatus', 'tmClasses', 'tmCountries', 'tmRegAt', 'tmExpiry',
                  'domains', 'socialIds', 'slogan'] as $k) {
            $this->assertTrue($keys->contains($k), "حقل العلامة $k غائب");
        }
    }

    public function test_brand_registration_expiry_reaches_the_radar(): void
    {
        $this->seedCore();
        Brand::create(['name' => 'علامة ليونوميا', 'status' => 'نشطة',
                       'tm_status' => 'مسجّلة', 'tm_expiry' => now()->addDays(12)->toDateString()]);

        $this->assertStringContainsString('علامة ليونوميا',
            collect(hub_expiry(true))->pluck('name')->implode(' | '),
            'تجديد تسجيل العلامة لم يصل الرادار — والعلامة تسقط بانقضاء مدتها صمتاً');
    }

    public function test_service_links_competitors_and_ideas(): void
    {
        $keys = collect(hub_mod('services')['fields'])->pluck('key');
        foreach (['brandId', 'competitorIds', 'ideaIds', 'valueProp', 'deliverables',
                  'stage', 'ownerId', 'priceReview', 'unit'] as $k) {
            $this->assertTrue($keys->contains($k), "حقل الخدمة $k غائب");
        }
    }

    public function test_service_page_compares_our_price_to_the_market(): void
    {
        $this->seedCore();
        $s = Service::create(['name' => 'باقة الاستضافة', 'price' => 500, 'cost' => 120,
                              'cycle' => 'شهري', 'status' => 'نشطة']);
        Competitor::create(['name' => 'المنافس الأرخص', 'service_id' => $s->id, 'status' => 'نشط',
                            'price_from' => 300, 'price_to' => 400, 'threat' => 'مرتفع']);
        Competitor::create(['name' => 'المنافس الأغلى', 'service_id' => $s->id, 'status' => 'نشط',
                            'price_from' => 800, 'price_to' => 900]);

        $html = $this->actingAs($this->owner)->get('/m/services/' . $s->id)->assertOk()->getContent();
        $this->assertStringContainsString('موقعنا من السوق', $html);
        $this->assertStringContainsString('المنافس الأرخص', $html);
        $this->assertStringContainsString('الهامش', $html, 'الهامش محسوب من السعر والتكلفة');
    }

    public function test_service_page_shows_the_ideas_that_improve_it(): void
    {
        $this->seedCore();
        $s = Service::create(['name' => 'خدمة قابلة للتطوير', 'status' => 'نشطة']);
        \App\Models\Idea::create(['title' => 'أتمتة التسليم', 'status' => 'معتمدة',
                                  'service_id' => $s->id]);

        $html = $this->actingAs($this->owner)->get('/m/services/' . $s->id)->assertOk()->getContent();
        $this->assertStringContainsString('أفكار تطوّر هذه الخدمة', $html);
        $this->assertStringContainsString('أتمتة التسليم', $html);
    }

    public function test_new_fields_respect_field_permissions(): void
    {
        $this->seedCore();
        $s = Service::create(['name' => 'خدمة محمية', 'price' => 900, 'cost' => 100, 'status' => 'نشطة']);

        $role = $this->viewer->role;
        $role->forceFill(['field_rules' => ['services' => ['cost' => 'hide']]])->save();

        // المالك يرى الهامش محسوباً من السعر والتكلفة
        $this->actingAs($this->owner)->get('/m/services/' . $s->id)
            ->assertOk()->assertSee('الهامش · تكلفة', false);

        $html = $this->actingAs($this->viewer->fresh())->get('/m/services/' . $s->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('تكلفة التشغيل الشهرية', $html,
            'حقلٌ مخفيٌّ بصلاحية الحقول ظهر');
        $this->assertStringNotContainsString('الهامش · تكلفة', $html,
            'الهامش يفضح التكلفة المخفية — لا يُعرض لمن حُجبت عنه');
    }
}
