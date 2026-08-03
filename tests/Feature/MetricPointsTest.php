<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use Tests\TestCase;

/**
 * نواة المقاييس الزمنية: كل رقمٍ في النظام كان **لقطةً واحدة** تُدهس بالتي بعدها
 * — «المتابعون ١٢٠٠٠» بلا ذاكرةٍ لما كانوا أمس، فلا نمو ولا اتجاه ولا رسم.
 * وكل تحديثٍ يدوي. هنا سلسلةٌ زمنية عامة لأي وحدةٍ وأي مقياس، تُغذّى آلياً
 * من n8n أو أي مصدرٍ عبر مفاتيح الـAPI القائمة، بلا تكرارٍ عند إعادة الدفع.
 */
class MetricPointsTest extends TestCase
{
    protected function acc(): SocialAccount
    {
        return SocialAccount::create(['platform' => 'Instagram', 'handle' => '@lynomia',
                                      'followers' => 100, 'status' => 'نشط']);
    }

    public function test_series_remembers_history_and_computes_growth(): void
    {
        $this->seedCore();
        $a = $this->acc();

        hub_metric_put('social', $a->id, 'followers', 1000, now()->subDays(30));
        hub_metric_put('social', $a->id, 'followers', 1200, now()->subDays(15));
        hub_metric_put('social', $a->id, 'followers', 1500, now());

        $this->assertSame(1500.0, hub_metric_latest('social', $a->id, 'followers'));
        $this->assertCount(3, hub_metric_series('social', $a->id, 'followers', 60));

        $g = hub_metric_growth('social', $a->id, 'followers', 60);
        $this->assertSame(1000.0, $g['from']);
        $this->assertSame(1500.0, $g['to']);
        $this->assertSame(500.0, $g['delta']);
        $this->assertSame(50.0, $g['pct']);
    }

    public function test_repushing_the_same_point_updates_not_duplicates(): void
    {
        $this->seedCore();
        $a = $this->acc();
        $at = now()->startOfHour();

        hub_metric_put('social', $a->id, 'followers', 900, $at, 'n8n');
        hub_metric_put('social', $a->id, 'followers', 950, $at, 'n8n');

        $this->assertSame(1, \App\Models\MetricPoint::count(), 'النقطة نفسها تُحدَّث لا تتكرر');
        $this->assertSame(950.0, hub_metric_latest('social', $a->id, 'followers'));
    }

    public function test_api_ingests_a_batch_and_is_idempotent(): void
    {
        $this->seedCore();
        $a = $this->acc();
        $tok = $this->apiToken($this->owner);

        $body = ['points' => [
            ['module' => 'social', 'record_id' => $a->id, 'metric' => 'followers', 'value' => 2000,
             'at' => now()->subDay()->toIso8601String()],
            ['module' => 'social', 'record_id' => $a->id, 'metric' => 'followers', 'value' => 2100,
             'at' => now()->toIso8601String()],
        ]];

        $this->withToken($tok)->postJson('/api/v1/metrics', $body)
            ->assertOk()->assertJsonPath('saved', 2);
        $this->withToken($tok)->postJson('/api/v1/metrics', $body)
            ->assertOk()->assertJsonPath('saved', 2);

        $this->assertSame(2, \App\Models\MetricPoint::count(), 'إعادة الدفع لا تضاعف النقاط');
    }

    public function test_api_reads_the_series_back(): void
    {
        $this->seedCore();
        $a = $this->acc();
        hub_metric_put('social', $a->id, 'followers', 300, now()->subDays(2));
        hub_metric_put('social', $a->id, 'followers', 400, now());

        $this->withToken($this->apiToken($this->owner))
            ->getJson('/api/v1/metrics/social/' . $a->id . '?metric=followers')
            ->assertOk()
            ->assertJsonPath('growth.delta', 100)
            ->assertJsonCount(2, 'series');
    }

    public function test_ingestion_respects_permission_and_scope(): void
    {
        $this->seedCore();
        $a = $this->acc();

        // مفتاحٌ بنطاق قراءةٍ فقط لا يكتب مقياساً
        $ro = $this->apiToken($this->owner, 'social:v');
        $this->withToken($ro)->postJson('/api/v1/metrics', ['points' => [
            ['module' => 'social', 'record_id' => $a->id, 'metric' => 'followers', 'value' => 1],
        ]])->assertForbidden();

        // ووحدةٌ لا يملك صاحب المفتاح تعديلها ترفض أيضاً
        $viewer = $this->apiToken($this->viewer);
        $this->withToken($viewer)->postJson('/api/v1/metrics', ['points' => [
            ['module' => 'social', 'record_id' => $a->id, 'metric' => 'followers', 'value' => 1],
        ]])->assertForbidden();

        $this->assertSame(0, \App\Models\MetricPoint::count());
    }

    public function test_editing_a_measured_field_records_a_point_by_itself(): void
    {
        $this->seedCore();

        // إنشاءٌ عادي من الواجهة — بلا n8n وبلا إدخالٍ للمقاييس
        $this->actingAs($this->owner)->post('/m/social', [
            'platform' => 'Instagram', 'handle' => '@auto', 'followers' => 500, 'status' => 'نشط',
        ])->assertRedirect();

        $a = SocialAccount::where('handle', '@auto')->firstOrFail();
        $this->assertSame(500.0, hub_metric_latest('social', $a->id, 'followers'),
            'الحفظ العادي يبني السلسلة — الحقل وحده كان يدهس ما قبله');

        // والتحديث في ساعةٍ لاحقة يضيف نقطةً لا يستبدل التاريخ
        $this->travel(2)->hours();
        $this->actingAs($this->owner)->put('/m/social/' . $a->id, [
            'platform' => 'Instagram', 'handle' => '@auto', 'followers' => 800, 'status' => 'نشط',
        ])->assertRedirect();

        $this->assertCount(2, hub_metric_series('social', $a->id, 'followers'));
        $this->assertSame(300.0, hub_metric_growth('social', $a->id, 'followers')['delta']);
    }

    public function test_daily_snapshot_builds_history_without_any_external_source(): void
    {
        $this->seedCore();
        $a = $this->acc();
        \App\Models\MetricPoint::query()->delete();          // نبدأ من فراغٍ متعمَّد

        $this->artisan('hub:metrics-snapshot')->assertSuccessful();
        $this->assertSame(100.0, hub_metric_latest('social', $a->id, 'followers'));

        // تشغيلٌ ثانٍ في اليوم نفسه لا يضاعف نقطة اليوم
        $this->artisan('hub:metrics-snapshot')->assertSuccessful();
        $this->assertSame(1, \App\Models\MetricPoint::where('metric', 'followers')->count());
    }

    public function test_unknown_module_or_record_is_rejected_not_silently_stored(): void
    {
        $this->seedCore();
        $tok = $this->apiToken($this->owner);

        $this->withToken($tok)->postJson('/api/v1/metrics', ['points' => [
            ['module' => 'لا-وحدة', 'record_id' => 'x', 'metric' => 'followers', 'value' => 1],
        ]])->assertStatus(422);

        $this->withToken($tok)->postJson('/api/v1/metrics', ['points' => [
            ['module' => 'social', 'record_id' => '00000000-0000-0000-0000-000000000000',
             'metric' => 'followers', 'value' => 1],
        ]])->assertStatus(422);

        $this->assertSame(0, \App\Models\MetricPoint::count());
    }
}
