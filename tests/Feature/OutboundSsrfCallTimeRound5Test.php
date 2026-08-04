<?php

namespace Tests\Feature;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Odoo;
use App\Support\WebhookDispatcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الموجة الخامسة — دفعة v2.282.0: تصليب المسارات الصادرة ضد SSRF وقت النداء.
 *
 * حارسُ `hub_outbound_ok` كان يفحص الوجهة **وقت الحفظ/الإنشاء فقط**، ثم يتصل بها
 * لاحقاً بلا إعادة فحصٍ ولا تثبيتِ عنوان:
 * (٧) `Odoo::rpc` — يُعيد curl تحليلَ الاسم ويتّبع إعادة التوجيه وقت النداء.
 * (٨) `WebhookDispatcher::send` — يُسلّم بلا إعادة فحصٍ ولا تثبيتِ عنوان.
 * فوجهةٌ أُجيزت عامّةً ثم بدّلها DNS متقلّبٌ إلى داخليٍّ (169.254.169.254) وقت
 * النداء تلتفّ حول الحارس فيصير النظامُ مِجَسّاً على شبكته.
 *
 * المُحلِّل الافتراضيّ في TestCase يُعيد عنواناً عامّاً، وهنا نُبدّله لعنوانٍ داخليّ
 * (يحاكي إعادةَ الربط بعد اجتياز فحص الإنشاء) فيجب أن تُرفَض المحاولةُ قبل أيّ طلب.
 */
class OutboundSsrfCallTimeRound5Test extends TestCase
{
    /** (٨) تسليمُ الويبهوك يُعيد الفحص وقت التسليم فيرفض الوجهة الداخلية */
    public function test_webhook_delivery_gate_blocks_rebinding_to_private(): void
    {
        $this->seedCore();
        $this->app->instance('hub.dns', fn (string $h) => ['169.254.169.254']);
        Http::fake(['*' => Http::response('ok', 200)]);

        $h = Webhook::create(['name' => 'w', 'url' => 'http://rebind.test/h',
            'secret' => 's', 'events' => '*', 'active' => true]);
        $d = WebhookDelivery::create(['webhook_id' => $h->id, 'event' => 'clients.created',
            'event_id' => 'e1', 'payload' => '{}', 'state' => 'queued', 'tries' => 0, 'created_at' => now()]);

        $ok = WebhookDispatcher::send($d);

        $this->assertFalse($ok, 'سُلِّم ويبهوك لوجهةٍ داخلية وقت التسليم');
        Http::assertNothingSent();   // البوابة منعت الطلب قبل إرساله
        $this->assertStringContainsString('مرفوضة', (string) $d->fresh()->error);
    }

    /** (٧) نداء أودو يُعيد الفحص وقت النداء فيرفض الخادم الداخلي */
    public function test_odoo_rpc_gate_blocks_private_host_at_call_time(): void
    {
        $this->seedCore();
        $this->app->instance('hub.dns', fn (string $h) => ['169.254.169.254']);
        Http::fake(['*' => Http::response(['result' => 1], 200)]);

        $this->hubSetting('odoo.url', 'http://odoo.test');
        $this->hubSetting('odoo.db', 'db1');
        $this->hubSetting('odoo.user', 'admin');
        $this->hubSetting('odoo.key', 'apikey123');
        $cli = Odoo::for('default');

        $ref = new \ReflectionMethod($cli, 'rpc');
        $ref->setAccessible(true);
        try {
            $ref->invoke($cli, 'common', 'authenticate', ['db1', 'admin', 'apikey123', []]);
            $this->fail('نداء أودو لخادمٍ يُحلّ داخليّاً مرّ — SSRF');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('مرفوض', $e->getMessage());
        }
        Http::assertNothingSent();   // البوابة منعت النداء قبل لمس الشبكة
    }

    /** حارسا Uptime/التسليم يمنعان إعادة التوجيه (allow_redirects=false) — إثباتُ المصدر */
    public function test_outbound_paths_do_not_follow_redirects(): void
    {
        foreach (['Support/Odoo.php', 'Support/WebhookDispatcher.php', 'Support/Uptime.php'] as $f) {
            $src = file_get_contents(app_path($f));
            $this->assertMatchesRegularExpression("/'allow_redirects'\\s*=>\\s*false/", $src,
                "{$f}: مسارٌ صادرٌ يتّبع إعادة التوجيه — وجهةٌ تردّ 302 نحو الداخل تلتفّ حول الحارس");
        }
    }
}
