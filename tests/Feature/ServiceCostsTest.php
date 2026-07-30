<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceCostsTest extends TestCase
{
    public function test_monthly_normalization_shared_server_and_tool_matching(): void
    {
        $this->seedCore();

        $sid = (string) Str::uuid();
        DB::table('servers')->insert(['id' => $sid, 'name' => 'سيرفر', 'cost' => 60, 'cycle' => 'شهري',
            'created_at' => now(), 'updated_at' => now()]);

        DB::table('services')->insert([
            ['id' => (string) Str::uuid(), 'name' => 'منصة البيع', 'price' => 1200, 'cycle' => 'سنوي',
             'cost' => 20, 'server_id' => $sid, 'status' => 'نشطة', 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'name' => 'خدمة الدعم', 'price' => 15, 'cycle' => 'شهري',
             'cost' => null, 'server_id' => $sid, 'status' => 'نشطة', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // أداة تطابق «منصة البيع» بالاسم — ٣٠ ربع سنوي = ١٠ شهرياً
        DB::table('subscriptions')->insert(['id' => (string) Str::uuid(), 'service' => 'منصة البيع — Stripe',
            'amount' => 30, 'cycle' => 'ربع سنوي', 'renew' => now()->addMonths(3), 'status' => 'نشط',
            'created_at' => now(), 'updated_at' => now()]);

        $d = hub_service_costs(true);
        $r = collect($d['rows'])->firstWhere('name', 'منصة البيع');

        $this->assertSame(100.0, $r['priceM']);           // ١٢٠٠ ÷ ١٢
        $this->assertSame(30.0, $r['serverM']);            // ٦٠ ÷ خدمتين
        $this->assertSame(2, $r['serverShared']);
        $this->assertSame(10.0, $r['toolsM']);             // ٣٠ ÷ ٣
        $this->assertSame(60.0, $r['costM']);              // ٢٠ + ٣٠ + ١٠
        $this->assertSame(40.0, $r['margin']);
        $this->assertSame(40, $r['marginPct']);

        // الدعم: سعر ١٥ وكلفة ٣٠ (حصة السيرفر) → تحت الماء
        $s = collect($d['rows'])->firstWhere('name', 'خدمة الدعم');
        $this->assertSame(-15.0, $s['margin']);
        $this->assertSame(1, $d['totals']['underwater']);

        // الأسوأ هامشاً أولاً
        $this->assertSame('خدمة الدعم', $d['rows'][0]['name']);
    }

    public function test_one_off_price_has_no_monthly_and_missing_cost_is_null_not_zero(): void
    {
        $this->seedCore();
        DB::table('services')->insert(['id' => (string) Str::uuid(), 'name' => 'تطوير مخصص',
            'price' => 5000, 'cycle' => 'مرة واحدة', 'status' => 'نشطة',
            'created_at' => now(), 'updated_at' => now()]);

        $d = hub_service_costs(true);
        $r = collect($d['rows'])->firstWhere('name', 'تطوير مخصص');

        $this->assertNull($r['priceM'], '«مرة واحدة» لا شهرية لها');
        $this->assertNull($r['costM'], 'كلفة غير مسجلة null لا صفر — الصفر كذبة');
        $this->assertNull($r['margin']);
        $this->assertSame(1, $d['totals']['unpriced']);
        $this->assertSame(1, $d['totals']['uncosted']);
    }

    public function test_plan_selling_under_cost_is_flagged_and_free_tier_excused(): void
    {
        $this->seedCore();
        DB::table('pricing_plans')->insert([
            ['id' => (string) Str::uuid(), 'name' => 'خاسرة', 'price' => 5, 'unit_cost' => 8, 'free_tier' => 0,
             'status' => 'نشطة', 'price_changed_at' => now()->subDays(400), 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'name' => 'مجانية', 'price' => 0, 'unit_cost' => 2, 'free_tier' => 1,
             'status' => 'نشطة', 'price_changed_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $d = hub_service_costs(true);
        $this->assertSame(1, $d['totals']['plansUnder'], 'المجانية المتعمدة لا تُحسب خسارة');

        $p = collect($d['plans'])->firstWhere('name', 'خاسرة');
        $this->assertSame(-3.0, $p['margin']);
        $this->assertGreaterThan(365, $p['priceAgeDays']);
    }

    public function test_page_gated_and_renders(): void
    {
        $this->seedCore();
        $this->actingAs($this->viewer)->get('/service-costs')->assertForbidden();
        $this->actingAs($this->owner)->get('/service-costs')->assertOk()
            ->assertSee('تكلفة الخدمات والتسعير');
    }
}
