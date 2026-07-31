<?php

namespace Tests\Feature;

use App\Models\Application;
use Tests\TestCase;

/**
 * التطبيقات: ٤٠ حقلاً عن الروابط والشهادات وأرقام البناء — ولا حقلَ **واحداً**
 * عن أهمّ ثلاثة أرقام: التحميلات، التقييم، عدد المراجعات. فمركز التطبيق يخبرك
 * برقم Build ولا يخبرك أَحيٌّ هو أم ميت. وحقل «مزامنة بيانات المتجر تلقائياً»
 * كان مربّعاً لا شيء خلفه.
 */
class AppStoreMetricsTest extends TestCase
{
    protected function app_(array $extra = []): Application
    {
        return Application::create(array_merge([
            'name' => 'تطبيق ليونوميا', 'platform' => 'Android + iOS', 'status' => 'منشور',
            'ver' => '3.2.0', 'published' => true,
        ], $extra));
    }

    public function test_store_numbers_exist_as_fields(): void
    {
        $this->seedCore();
        $a = $this->app_(['downloads' => 51200, 'rating' => 4.6, 'reviews' => 840]);

        $this->assertSame(51200.0, (float) $a->fresh()->downloads);
        $this->assertSame(4.6, (float) $a->fresh()->rating);

        $keys = collect(hub_mod('apps')['fields'])->pluck('key');
        foreach (['downloads', 'rating', 'reviews'] as $k) {
            $this->assertTrue($keys->contains($k), "الحقل $k غائب عن سجل الوحدة");
        }
    }

    public function test_saving_builds_the_series_by_itself(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/m/apps', [
            'name' => 'تطبيق آلي', 'platform' => 'Android', 'status' => 'منشور',
            'downloads' => 1000, 'rating' => 4.1, 'reviews' => 20,
        ])->assertRedirect();

        $a = Application::where('name', 'تطبيق آلي')->firstOrFail();
        $this->assertSame(1000.0, hub_metric_latest('apps', $a->id, 'downloads'));
        $this->assertSame(4.1, hub_metric_latest('apps', $a->id, 'rating'));
    }

    public function test_n8n_can_push_store_numbers(): void
    {
        $this->seedCore();
        $a = $this->app_();

        $this->withToken($this->apiToken($this->owner))->postJson('/api/v1/metrics', ['points' => [
            ['module' => 'apps', 'record_id' => $a->id, 'metric' => 'downloads', 'value' => 60000,
             'at' => now()->subDays(7)->toIso8601String(), 'source' => 'n8n'],
            ['module' => 'apps', 'record_id' => $a->id, 'metric' => 'downloads', 'value' => 66000,
             'at' => now()->toIso8601String(), 'source' => 'n8n'],
            ['module' => 'apps', 'record_id' => $a->id, 'metric' => 'rating', 'value' => 4.8,
             'source' => 'n8n'],
        ]])->assertOk()->assertJsonPath('saved', 3);

        $g = hub_metric_growth('apps', $a->id, 'downloads', 30);
        $this->assertSame(6000.0, $g['delta'], 'نمو التحميلات يُقرأ من السلسلة');
    }

    public function test_app_center_shows_store_performance(): void
    {
        $this->seedCore();
        $a = $this->app_(['downloads' => 66000, 'rating' => 4.8, 'reviews' => 900, 'auto_store' => true]);
        hub_metric_put('apps', $a->id, 'downloads', 60000, now()->subDays(7), 'n8n');
        hub_metric_put('apps', $a->id, 'downloads', 66000, now(), 'n8n');
        hub_metric_put('apps', $a->id, 'rating', 4.8, now(), 'n8n');

        $html = $this->actingAs($this->owner)->get('/app/' . $a->id)->assertOk()->getContent();
        $this->assertStringContainsString('أداء المتجر', $html);
        $this->assertStringContainsString('66,000', $html, 'التحميلات');
        $this->assertStringContainsString('4.8', $html, 'التقييم');
        $this->assertStringContainsString('+6,000', $html, 'النمو من السلسلة لا من الحقل');
        $this->assertStringContainsString('آلي', $html, 'حال التغذية معلن');
    }

    public function test_app_center_names_apps_with_no_store_feed(): void
    {
        $this->seedCore();
        $a = $this->app_();

        $html = $this->actingAs($this->owner)->get('/app/' . $a->id)->assertOk()->getContent();
        $this->assertStringContainsString('غير مربوط', $html,
            'تطبيقٌ بلا أرقام متجر يُقال له ذلك صراحةً بدل فراغٍ صامت');
        $this->assertStringContainsString('/api/v1/metrics', $html, 'وطريق الربط مذكور');
    }

    public function test_quality_page_ranks_by_store_reality(): void
    {
        $this->seedCore();
        $good = $this->app_(['name' => 'المحبوب', 'downloads' => 90000, 'rating' => 4.9, 'reviews' => 1200]);
        $bad = $this->app_(['name' => 'المكروه', 'downloads' => 300, 'rating' => 2.1, 'reviews' => 40]);
        hub_metric_put('apps', $good->id, 'downloads', 80000, now()->subDays(20), 'n8n');
        hub_metric_put('apps', $good->id, 'downloads', 90000, now(), 'n8n');

        $html = $this->actingAs($this->owner)->get('/app-quality')->assertOk()->getContent();
        $this->assertStringContainsString('أرقام المتاجر', $html);
        $this->assertStringContainsString('المكروه', $html);
        $this->assertStringContainsString('2.1', $html, 'التقييم المتدني ظاهر لا مخفي');
    }

    public function test_store_numbers_respect_scope(): void
    {
        $this->seedCore();
        $a = $this->app_();
        $this->actingAs($this->employee)->get('/app/' . $a->id)->assertOk();

        $role = $this->employee->role;
        $matrix = $role->matrix;
        $matrix['apps'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->forceFill(['matrix' => $matrix])->save();

        $this->actingAs($this->employee->fresh())->get('/app/' . $a->id)->assertForbidden();
    }
}
