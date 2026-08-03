<?php

namespace Tests\Feature;

use App\Support\Pricing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الباقات والتسعير — من جدولٍ مسطّح إلى **قرارِ تسعير**.
 *
 * الوحدة تحمل منذ البداية كل ما يلزم قراراً: سعرٌ وتكلفةُ وحدةٍ وسعرٌ سابق
 * وتاريخُ تغييرٍ وحدودٌ ومزايا وسوق — وبجانبها المنافسون بأسعارهم ومددِ
 * تجاربهم. ثم تُعرض كلها صفوفاً في جدول، فلا يظهر أن باقةً **تُباع تحت
 * تكلفتها**، ولا أن سعراً لم يُمسّ منذ سنتين، ولا أين نقف من السوق.
 */
class PricingCenterTest extends TestCase
{
    protected function scene(): array
    {
        $this->seedCore();
        // القارئات صارت محروسةً بذاتها — تُقرأ بهويّة صاحب صلاحية
        $this->actingAs($this->owner);

        $p = \App\Models\Project::create(['name' => 'مشروع المنصة', 'status' => 'قيد التنفيذ']);
        DB::table('applications')->insert(['id' => (string) Str::uuid(), 'name' => 'تطبيق ليونوميا',
            'project_id' => $p->id, 'logo_id' => 'hub/logo-x.png', 'status' => 'منشور',
            'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $svc = \App\Models\Service::create(['name' => 'اشتراك المنصة', 'kind' => 'اشتراك',
            'status' => 'نشطة', 'project_id' => $p->id, 'cost' => 900]);

        // إدراجٌ صفّاً صفّاً: الإدراج الجماعي يشترط تطابق المفاتيح
        $plan = fn (array $a) => DB::table('pricing_plans')->insert($a + [
            'id' => (string) Str::uuid(), 'service_id' => $svc->id, 'currency' => 'د.ك',
            'cycle' => 'شهري', 'status' => 'نشطة', 'free_tier' => false, 'unit_cost' => null,
            'features' => null, 'limits' => null, 'prev_price' => null, 'price_changed_at' => null,
            'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $plan(['name' => 'المجانية', 'price' => 0, 'free_tier' => true]);
        $plan(['name' => 'الأساسية', 'price' => 10, 'unit_cost' => 14,
               'features' => "تقارير\nدعم بالبريد", 'limits' => 'خمسة مستخدمين']);
        $plan(['name' => 'الاحترافية', 'price' => 40, 'unit_cost' => 12, 'prev_price' => 30,
               'price_changed_at' => now()->subYears(3)->toDateString()]);

        $rival = fn (array $a) => DB::table('competitors')->insert($a + [
            'id' => (string) Str::uuid(), 'service_id' => $svc->id, 'currency' => 'د.ك',
            'billing' => 'شهري', 'free_tier' => true, 'status' => 'يُراقب',
            'our_edge' => null, 'their_edge' => null,
            'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $rival(['name' => 'منافس أ', 'price_from' => 25, 'price_to' => 60, 'trial_days' => 14,
                'our_edge' => 'دعمٌ عربي', 'their_edge' => 'تكاملات أكثر']);
        $rival(['name' => 'منافس ب', 'price_from' => 30, 'price_to' => 30, 'trial_days' => 30]);

        Cache::flush();

        return ['service' => $svc];
    }

    public function test_plans_are_grouped_by_service_with_its_product_face(): void
    {
        $this->scene();

        $cat = Pricing::catalog();
        $this->assertCount(1, $cat);
        $this->assertSame('اشتراك المنصة', $cat[0]['service']);
        $this->assertSame('hub/logo-x.png', $cat[0]['logo'], 'شعار المنتج لا يصل البطاقة');
        $this->assertSame('تطبيق ليونوميا', $cat[0]['appName']);

        // المجانية أولاً ثم تصاعدياً بالسعر
        $this->assertSame(['المجانية', 'الأساسية', 'الاحترافية'], array_column($cat[0]['plans'], 'name'));
    }

    /** الهامش لكل باقة — والخسارة تُسمَّى خسارة */
    public function test_margin_and_below_cost_are_computed(): void
    {
        $this->scene();
        $plans = collect(Pricing::catalog()[0]['plans'])->keyBy('name');

        $this->assertTrue($plans['الأساسية']['belowCost'],
            'باقةٌ سعرها ١٠ وتكلفتها ١٤ ولا يقول النظام إنها تُباع بخسارة');
        $this->assertSame(70, $plans['الاحترافية']['margin']);
        $this->assertFalse($plans['الاحترافية']['belowCost']);
    }

    public function test_it_reads_the_market_band_from_competitors(): void
    {
        $this->scene();
        $s = Pricing::catalog()[0];

        $this->assertSame(2, $s['market']['n']);
        $this->assertSame(25.0, $s['market']['min']);
        $this->assertSame(60.0, $s['market']['max']);
        $this->assertCount(2, $s['rivals']);
    }

    /** القرارات المستحقة: الخسارة أولاً، ثم الجمود، ثم موقعنا من السوق */
    public function test_insights_name_what_needs_a_decision(): void
    {
        $this->scene();
        $ins = collect(Pricing::insights());

        $this->assertNotNull($ins->firstWhere('title', 'تُباع تحت تكلفتها'));
        $this->assertNotNull($ins->firstWhere('title', 'سعرٌ لم يُمسّ منذ سنتين'));
        $this->assertNotNull($ins->firstWhere('title', 'أرخص من السوق كلّه'),
            'باقتنا الأدنى ١٠ وأدنى منافسٍ ٢٥ — مالٌ متروك على الطاولة ولا أحد يقوله');

        // الأحمر أولاً
        $this->assertSame('bad', $ins->first()['tone']);
    }

    public function test_a_service_with_no_plan_is_surfaced(): void
    {
        $this->scene();
        \App\Models\Service::create(['name' => 'خدمةٌ بلا سعر', 'kind' => 'استشارة', 'status' => 'نشطة']);
        Cache::flush();

        $this->assertNotNull(collect(Pricing::insights())->firstWhere('what', 'خدمةٌ بلا سعر'),
            'خدمةٌ نشطة بلا باقة — كل بيعةٍ تفاوضٌ من الصفر');
    }

    public function test_the_screen_renders_cards_and_the_rival_table(): void
    {
        $this->scene();

        $html = $this->actingAs($this->owner)->get('/pricing')->assertOk()->getContent();

        $this->assertStringContainsString('اشتراك المنصة', $html);
        $this->assertStringContainsString('تحت التكلفة', $html, 'الخسارة لا تُوسَم في البطاقة');
        $this->assertStringContainsString('أين نقف', $html, 'لا مقارنة بالمنافسين');
        $this->assertStringContainsString('هامش 70٪', $html);
    }

    public function test_the_plans_table_points_to_the_card_view(): void
    {
        $this->scene();

        $this->actingAs($this->owner)->get('/m/plans')->assertOk()
            ->assertSee('افتح مركز التسعير');
    }

    public function test_pricing_needs_permission_on_plans(): void
    {
        $this->seedCore();
        $role = \App\Models\Role::create(['name' => 'بلا باقات', 'scope' => 'all',
            'flags' => [], 'matrix' => ['tasks' => ['v' => 1]]]);
        $u = \App\Models\User::create(['name' => 'ضيّق', 'email' => 'np@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($u)->get('/pricing')->assertForbidden();
    }
}
