<?php

namespace Tests\Feature;

use App\Models\OutboxMessage;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Health;
use App\Support\Integrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * نموذجُ الصحّة الواحد: حياةٌ وجاهزيةٌ واعتماديات — بحالاتٍ لها معنى لا «٢٠٠ = سليم».
 */
class HealthModelTest extends TestCase
{
    public function test_healthz_keeps_legacy_shape_and_adds_canonical_status(): void
    {
        $this->seedCore();
        $res = $this->getJson('/healthz')->assertOk();
        $res->assertJsonPath('checks.db', 'ok')->assertJsonPath('checks.cache', 'ok')->assertJsonPath('checks.storage', 'ok');
        $this->assertContains($res->json('status'), ['ok', 'degraded']);
        $this->assertContains($res->json('health'), [Health::HEALTHY, Health::DEGRADED, Health::UNAVAILABLE, Health::MAINTENANCE]);
        $this->assertArrayHasKey('scheduler', $res->json('components'));
        // السطحُ العامّ لا يكشف أرقاماً ولا رسائل — حالاتٌ فقط
        $this->assertIsString($res->json('components.db'));
        $this->assertArrayNotHasKey('why', (array) $res->json('components'));
        $this->assertStringNotContainsString('last_error', $res->getContent());
    }

    public function test_probes_distinguish_liveness_from_readiness(): void
    {
        $this->seedCore();
        $this->getJson('/healthz?probe=live')->assertOk()->assertJsonPath('probe', 'live')->assertJsonPath('status', 'ok');

        $ready = $this->getJson('/healthz?probe=ready')->assertOk()->assertJsonPath('probe', 'ready')->json();
        $this->assertSame(['db', 'cache', 'storage', 'migrations', 'config'], array_keys($ready['components']));
        $this->assertArrayNotHasKey('scheduler', $ready['components'], 'الجاهزيةُ لا تشمل الاعتماديات');
    }

    public function test_database_outage_is_unavailable_and_503(): void
    {
        $this->seedCore();
        DB::partialMock()->shouldReceive('select')->andThrow(new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused password=secret'));
        $c = Health::ready();
        $this->assertSame(Health::UNAVAILABLE, $c['components']['db']['status']);
        $this->assertSame(Health::UNAVAILABLE, $c['status'], 'الأسوأ يحكم المجموع');
        $this->assertStringNotContainsString('secret', json_encode($c), 'كلمةُ سرّ DSN لا تُطبع');

        $this->getJson('/healthz?probe=ready')->assertStatus(503)->assertJsonPath('checks.db', 'fail')->assertJsonPath('health', Health::UNAVAILABLE);
    }

    public function test_scheduler_states_follow_heartbeat_age_and_result(): void
    {
        $this->seedCore();
        // لا نبضةَ قطّ: cron غير مفعّل — انقطاعٌ لا مجهول
        $s = Health::scheduler();
        $this->assertSame(Health::UNAVAILABLE, $s['status']);
        $this->assertStringContainsString('cron', $s['why']);

        $this->hubSetting('heartbeat.outbox', now()->subMinutes(3)->toIso8601String());
        $this->hubSetting('heartbeat.automation', now()->subHours(30)->toIso8601String());
        $this->hubSetting('heartbeat.backup', now()->subHours(60)->toIso8601String());
        $s = Health::scheduler();
        $jobs = $s['data']['jobs'];
        $this->assertSame(Health::HEALTHY, $jobs['outbox']['status']);
        $this->assertSame(Health::DEGRADED, $jobs['automation']['status']);
        $this->assertSame(Health::UNAVAILABLE, $jobs['backup']['status']);
        $this->assertSame(Health::UNKNOWN, $jobs['metrics']['status']);
        $this->assertSame(Health::UNAVAILABLE, $s['status']);

        // نبضةٌ في موعدها لكن نتيجتُها فشل: متدهورة لا سليمة
        Health::beat('uptime', 120, 'fail', 'x');
        $this->assertSame(Health::DEGRADED, Health::scheduler()['data']['jobs']['uptime']['status']);
        $this->assertSame(120, Health::scheduler()['data']['jobs']['uptime']['ms']);
    }

    public function test_beat_writes_legacy_key_and_meta_and_commands_beat(): void
    {
        $this->seedCore();
        Health::beat('quality', 42, 'ok');
        $this->assertNotEmpty(setting('heartbeat.quality'));
        $this->assertSame(42, setting('heartbeat.quality.meta')['ms']);

        $this->artisan('hub:metrics-snapshot')->assertExitCode(0);
        $this->assertNotEmpty(setting('heartbeat.metrics'), 'لقطةُ المقاييس صارت تنبض');
        $this->artisan('hub:quality-snapshot')->assertExitCode(0);
        $this->assertNotEmpty(setting('heartbeat.quality'));
        $this->artisan('hub:outbox')->assertExitCode(0);
        $this->assertSame('ok', setting('heartbeat.outbox.meta')['result']);
    }

    public function test_outbox_and_webhook_components_reflect_queue_state(): void
    {
        $this->seedCore();
        $this->assertSame(Health::HEALTHY, Health::check()['components']['outbox']['status']);

        OutboxMessage::create(['kind' => 'x', 'channel' => 'tg', 'text' => 't', 'state' => 'failed', 'error' => 'تلجرام رفض', 'created_at' => now()]);
        $o = Health::check()['components']['outbox'];
        $this->assertSame(Health::DEGRADED, $o['status']);
        $this->assertSame('تلجرام رفض', $o['data']['last_error']);

        OutboxMessage::create(['kind' => 'x', 'channel' => 'tg', 'text' => 't', 'state' => 'queued', 'created_at' => now()->subHours(2)]);
        $this->assertSame(Health::UNAVAILABLE, Health::check()['components']['outbox']['status'], 'طابورٌ لا يُفرَغ = العامل متوقّف');

        $w = Webhook::create(['name' => 'w', 'url' => 'https://example.com/h', 'secret' => 's', 'events' => '*', 'active' => true, 'paused_until' => now()->addHour(), 'fail_streak' => 10]);
        $this->assertSame(Health::UNAVAILABLE, Health::check()['components']['webhooks']['status'], 'كل الاشتراكات موقوفة');
        $w->forceFill(['paused_until' => null])->save();
        WebhookDelivery::create(['webhook_id' => $w->id, 'event' => 'x.created', 'event_id' => 'e1', 'payload' => '{}', 'state' => 'failed', 'created_at' => now()]);
        $this->assertSame(Health::DEGRADED, Health::check()['components']['webhooks']['status']);
    }

    public function test_integration_registry_exposes_canonical_health(): void
    {
        $this->seedCore();
        $inst = Integrations::installed();
        foreach ($inst as $i) {
            $this->assertContains($i['health'], [Integrations::CONNECTED, Integrations::DEGRADED, Integrations::FAILED,
                Integrations::DISABLED, Integrations::CONFIGURATION_REQUIRED, Integrations::UNKNOWN], $i['key']);
        }
        $this->assertSame(Integrations::CONFIGURATION_REQUIRED, $inst['webhooks']['health'], 'لا اشتراكات = يحتاج إعداداً');
        $this->assertSame(Integrations::CONFIGURATION_REQUIRED, $inst['hooks']['health']);
        $this->assertSame(Integrations::CONFIGURATION_REQUIRED, $inst['odoo']['health']);

        // أودو مضبوط: النبضُ يقرّر — فشلٌ أحدث = FAILED، ثم نجاحٌ بعده = DEGRADED (فشلٌ خلال يوم)
        foreach (['odoo.url' => 'https://odoo.example.com', 'odoo.db' => 'd', 'odoo.user' => 'u', 'odoo.key' => 'k'] as $k => $v) $this->hubSetting($k, $v);
        Integrations::pulse('odoo', false, 'HTTP 502 key=abc');
        $o = Integrations::installed()['odoo'];
        $this->assertSame(Integrations::FAILED, $o['health']);
        $this->assertStringNotContainsString('abc', (string) $o['last_error'], 'المفتاح يُطمس');
        $this->travel(1)->minutes();
        Integrations::pulse('odoo', true);
        $this->assertSame(Integrations::DEGRADED, Integrations::installed()['odoo']['health']);

        Webhook::create(['name' => 'w', 'url' => 'https://example.com/h', 'secret' => 's', 'events' => '*', 'active' => false]);
        $this->assertSame(Integrations::DISABLED, Integrations::installed()['webhooks']['health']);

        $this->hubSetting('security.lockdown', '1');
        $this->assertSame(Integrations::DISABLED, Integrations::installed()['api']['health']);
    }

    public function test_ops_and_integration_screens_render_health(): void
    {
        $this->seedCore();
        $this->hubSetting('heartbeat.outbox', now()->toIso8601String());
        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringContainsString('صحّة المنصة', $html);
        $this->assertStringContainsString('خريطة الاعتماديات', $html);
        $this->assertStringContainsString('cron', $html, 'غيابُ النبضات يُقال بسببه');

        $this->actingAs($this->owner)->getJson('/admin/ops/health')->assertOk()
            ->assertJsonStructure(['status', 'components' => ['db' => ['status', 'why', 'data']], 'dependencies']);
        $this->actingAs($this->employee)->getJson('/admin/ops/health')->assertForbidden();

        $html = $this->actingAs($this->owner)->get('/admin/integrations')->assertOk()->getContent();
        $this->assertStringContainsString('يحتاج إعداداً', $html);
        $this->assertStringContainsString('آخر نجاح', $html);
    }

    public function test_security_component_reflects_lockdown_and_chain(): void
    {
        $this->seedCore();
        $this->assertSame(Health::HEALTHY, Health::check()['components']['security']['status']);
        $this->hubSetting('security.lockdown', '1');
        $this->assertSame(Health::MAINTENANCE, Health::check()['components']['security']['status']);
        $this->hubSetting('security.lockdown', '0');
        $this->hubSetting('maintenance.on', '1');
        $this->assertSame(Health::MAINTENANCE, Health::check()['components']['config']['status']);
    }
}
