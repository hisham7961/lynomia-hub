<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Website;
use App\Support\Uptime;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * السيرفرات والمواقع كانت **دفتر عناوين**: تضيف السيرفر فتعرف مزوّده ومواصفاته
 * ولا تعرف **أهو حيٌّ الآن**. وتضيف الموقع ومعه خانةُ Google Analytics بلا شيءٍ
 * خلفها — تملأ المعرّف فلا يصلك زائرٌ واحد. هنا فحصٌ حيّ يُخزَّن سلسلةً
 * (زمن الاستجابة والتوافر)، ومدخلٌ للتحليلات عبر نقطة المقاييس نفسها.
 */
class LiveMonitorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // مُحلِّل أسماءٍ ثابت: الاختبار يقيس المنطق لا حالة DNS على آلة التشغيل
        $this->app->instance('hub.dns', fn (string $h) => match ($h) {
            'api.example.com', 'lynomia.test', 'example.com' => ['93.184.216.34'],
            default => [],
        });
    }

    protected function srv(array $extra = []): Server
    {
        return Server::create(array_merge([
            'name' => 'خادم الإنتاج', 'provider' => 'Hetzner', 'status' => 'نشط',
            'monitor_url' => 'https://api.example.com/health', 'monitor_on' => true,
        ], $extra));
    }

    public function test_a_live_check_records_up_and_latency(): void
    {
        $this->seedCore();
        Http::fake(['api.example.com/*' => Http::response('ok', 200)]);
        $s = $this->srv();

        $r = Uptime::check('servers', $s);
        $this->assertTrue($r['up'], 'الفحص الحي يقول أهو حيّ');
        $this->assertSame(200, $r['code']);

        $this->assertSame(1.0, hub_metric_latest('servers', $s->id, 'up'), 'التوافر يُخزَّن سلسلةً');
        $this->assertNotNull(hub_metric_latest('servers', $s->id, 'latency'), 'وزمن الاستجابة كذلك');
    }

    public function test_a_down_target_is_recorded_as_down_not_skipped(): void
    {
        $this->seedCore();
        Http::fake(['api.example.com/*' => Http::response('boom', 503)]);
        $s = $this->srv();

        $r = Uptime::check('servers', $s);
        $this->assertFalse($r['up']);
        $this->assertSame(0.0, hub_metric_latest('servers', $s->id, 'up'),
            'العطل يُسجَّل صفراً — تخطّيه يجعل التوافر ١٠٠٪ كذباً');
    }

    public function test_uptime_percentage_comes_from_the_series(): void
    {
        $this->seedCore();
        $s = $this->srv();
        foreach ([1, 1, 0, 1] as $i => $v) {
            hub_metric_put('servers', $s->id, 'up', $v, now()->subHours(4 - $i), 'monitor');
        }

        $u = hub_uptime('servers', $s->id, 7);
        $this->assertSame(4, $u['checks']);
        $this->assertSame(75.0, $u['pct'], 'ثلاثة من أربعة = ٧٥٪');
        $this->assertSame('bad', $u['tone']);
    }

    public function test_internal_targets_are_refused(): void
    {
        $this->seedCore();
        foreach (['http://127.0.0.1/health', 'http://localhost:8080/x', 'http://169.254.169.254/latest/meta-data',
                  'file:///etc/passwd', 'http://10.0.0.5/admin'] as $bad) {
            $ok = hub_outbound_ok($bad);
            $this->assertFalse($ok['ok'], "هدفٌ داخلي قُبل: $bad");
        }
        $this->assertTrue(hub_outbound_ok('https://example.com/health')['ok']);
    }

    public function test_check_now_button_is_permission_guarded(): void
    {
        $this->seedCore();
        Http::fake(['api.example.com/*' => Http::response('ok', 200)]);
        $s = $this->srv();

        $this->actingAs($this->viewer)->post('/monitor/servers/' . $s->id . '/check')->assertForbidden();
        $this->actingAs($this->owner)->post('/monitor/servers/' . $s->id . '/check')->assertRedirect();
        $this->assertSame(1.0, hub_metric_latest('servers', $s->id, 'up'));
    }

    public function test_server_page_shows_live_state(): void
    {
        $this->seedCore();
        $s = $this->srv();
        hub_metric_put('servers', $s->id, 'up', 1, now(), 'monitor');
        hub_metric_put('servers', $s->id, 'latency', 143, now(), 'monitor');

        $html = $this->actingAs($this->owner)->get('/m/servers/' . $s->id)->assertOk()->getContent();
        $this->assertStringContainsString('المراقبة الحيّة', $html);
        $this->assertStringContainsString('143', $html, 'زمن الاستجابة معروض');
    }

    public function test_server_without_a_monitor_url_is_told_how(): void
    {
        $this->seedCore();
        $s = $this->srv(['monitor_url' => null, 'monitor_on' => false]);

        $html = $this->actingAs($this->owner)->get('/m/servers/' . $s->id)->assertOk()->getContent();
        $this->assertStringContainsString('غير مراقب', $html,
            'سيرفرٌ بلا رابط فحص يُقال له ذلك بدل صمت');
    }

    public function test_website_analytics_land_through_the_metrics_endpoint(): void
    {
        $this->seedCore();
        $w = Website::create(['name' => 'الموقع الرسمي', 'url' => 'https://lynomia.test',
                              'status' => 'يعمل', 'ga4_id' => 'G-ABC123']);

        $this->withToken($this->apiToken($this->owner))->postJson('/api/v1/metrics', ['points' => [
            ['module' => 'websites', 'record_id' => $w->id, 'metric' => 'visitors', 'value' => 4200,
             'at' => now()->subDays(7)->toIso8601String(), 'source' => 'n8n'],
            ['module' => 'websites', 'record_id' => $w->id, 'metric' => 'visitors', 'value' => 5100,
             'source' => 'n8n'],
        ]])->assertOk();

        $html = $this->actingAs($this->owner)->get('/m/websites/' . $w->id)->assertOk()->getContent();
        $this->assertStringContainsString('حركة الموقع', $html);
        $this->assertStringContainsString('5,100', $html);
        $this->assertStringContainsString('G-ABC123', $html, 'معرّف GA4 صار له معنى');
    }

    public function test_scheduled_command_checks_only_enabled_targets(): void
    {
        $this->seedCore();
        Http::fake(['api.example.com/*' => Http::response('ok', 200)]);
        $on = $this->srv(['name' => 'مراقَب']);
        $off = $this->srv(['name' => 'غير مراقَب', 'monitor_on' => false]);

        $this->artisan('hub:uptime-check')->assertSuccessful();

        $this->assertNotNull(hub_metric_latest('servers', $on->id, 'up'));
        $this->assertNull(hub_metric_latest('servers', $off->id, 'up'),
            'المطفأ لا يُفحص — المراقبة اختيارٌ لا فرض');
    }
}
