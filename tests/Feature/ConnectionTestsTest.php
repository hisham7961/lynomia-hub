<?php

namespace Tests\Feature;

use App\Models\OdooConnection;
use App\Support\ConnectionProbe;
use Tests\Concerns\FakesOdoo;
use Tests\TestCase;

/**
 * (WP-9.4 · spec §7.10) **فاحصُ الاتصال الواحد** — «هل التكاملُ يعمل؟».
 *
 * كان للاتصال الواحد فاحصان بشيفرتين: واحدٌ في شاشة الإعدادات للاتصال
 * الافتراضي وآخرُ في مركز التكاملات لصفوف الخوادم، كلٌّ برسالته وحرّاسه.
 * وكلاهما يعيد **`$e->getMessage()` خاماً** — ورسالةُ فشلِ مصادقةٍ تحمل ما
 * أرسله الخادمُ في نصّها (`password=…` في سلسلة اتصال مثلاً)، فتُطبع في
 * الشاشة وتُخزَّن في الجلسة. ولا خنقَ على أيٍّ منهما: زرٌّ يُنقر في حلقةٍ
 * يصير مسبارَ منافذٍ على شبكةٍ خارجية باسم الخادم.
 *
 * فهنا: شكلٌ واحد (`up/code/ms/error` — شكلُ `Uptime::check` نفسُه)، ورسالةٌ
 * تمرّ بالمُطهِّر الواحد، وزمنُ استجابةٍ مقيس، وخنقٌ على كل فاحص.
 */
class ConnectionTestsTest extends TestCase
{
    use FakesOdoo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('hub.dns', fn (string $h) => ['93.184.216.34']);
    }

    protected function defaultOdoo(string $url = 'https://odoo.example.com'): void
    {
        $this->hubSetting('odoo.url', $url);
        $this->hubSetting('odoo.db', 'maindb');
        $this->hubSetting('odoo.user', 'ro@example.com');
        $this->hubSetting('odoo.key', 'k-1');
    }

    /** الفاحصان يعودان بالشكل نفسِه — لا شكلَ لكل شاشة */
    public function test_both_odoo_testers_share_one_shape(): void
    {
        $this->seedCore();
        $this->defaultOdoo();
        $this->fakeOdoo('https://odoo.example.com',
            ['common.version' => ['server_version' => '17.0'], 'common.authenticate' => 2]);

        $row = OdooConnection::create(['name' => 'أودو المتجر', 'url' => 'https://odoo.example.com',
            'db' => 'shopdb', 'username' => 'ro@shop.example.com', 'key_cipher' => 'k-2', 'active' => true]);

        foreach ([ConnectionProbe::odoo(null), ConnectionProbe::odoo($row)] as $res) {
            $this->assertSame(ConnectionProbe::SHAPE, array_keys($res), 'شكلُ الفاحص انحرف');
            $this->assertTrue($res['up'], 'الفاحصُ لم ينجح على خادمٍ محاكىً سليم');
            $this->assertIsInt($res['ms'], 'لا زمنَ استجابةٍ مقيس');
            $this->assertGreaterThanOrEqual(0, $res['ms']);
            $this->assertNull($res['error']);
        }
    }

    /** رسالةُ الفشل تمرّ بالمُطهِّر — لا يخرج ما أرسله الخادمُ خاماً إلى الشاشة */
    public function test_failure_message_is_redacted_before_it_reaches_the_screen(): void
    {
        $this->seedCore();
        $this->defaultOdoo();
        $this->fakeOdoo('https://odoo.example.com',
            ['common.version' => ['#error' => 'auth refused password=Sup3rSecretPw for db']]);

        $res = ConnectionProbe::odoo(null);
        $this->assertFalse((bool) $res['up']);
        $this->assertStringNotContainsString('Sup3rSecretPw', (string) $res['error'], 'سرٌّ خرج في رسالة الفشل');
        $this->assertStringContainsString('password=***', (string) $res['error'], 'المُطهِّرُ لم يمرّ على الرسالة');

        // ونفسُ الرسالة في الشاشة — لا نصَّ خام في الجلسة
        $r = $this->actingAs($this->owner)->post('/admin/settings/odoo-test');
        $r->assertRedirect()->assertSessionHasErrors(['odoo']);
        $msg = (string) session('errors')->get('odoo')[0];
        $this->assertStringNotContainsString('Sup3rSecretPw', $msg);
    }

    /** عنوانٌ داخليّ يُرفض في الفاحصَين — النظامُ لا يصير مِجَسّاً على شبكته */
    public function test_an_internal_address_is_refused_by_both_probes(): void
    {
        $this->seedCore();
        $this->defaultOdoo('http://127.0.0.1:8069');
        $this->hubSetting('n8n.url', 'http://10.0.0.9:5678');

        $odoo = ConnectionProbe::odoo(null);
        $this->assertNotTrue($odoo['up']);
        $this->assertStringContainsString('مرفوض', (string) $odoo['error']);

        $n8n = ConnectionProbe::n8n();
        $this->assertSame(ConnectionProbe::SHAPE, array_keys($n8n), 'فاحصُ n8n بشكلٍ آخر');
        $this->assertNotTrue($n8n['up']);
        $this->assertStringContainsString('داخلي', (string) $n8n['error']);
    }

    /** فاحصُ n8n يقيس ويصنّف كأخيه — ورابطٌ غيرُ مضبوطٍ يُقال لا يُخمَّن */
    public function test_the_n8n_probe_reports_status_latency_and_missing_url(): void
    {
        $this->seedCore();

        $missing = ConnectionProbe::n8n();
        $this->assertNull($missing['up'], 'رابطٌ غير مضبوطٍ صُنِّف نجاحاً أو فشلاً');
        $this->assertNotSame('', (string) $missing['error']);

        $this->hubSetting('n8n.url', 'https://n8n.example.com');
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('ok', 200)]);

        $res = ConnectionProbe::n8n();
        $this->assertTrue($res['up']);
        $this->assertSame(200, $res['code']);
        $this->assertIsInt($res['ms']);

        $this->actingAs($this->owner)->post('/admin/integrations/n8n/test')
            ->assertRedirect()->assertSessionHas('ok');
    }

    /** خنقٌ فعّال على كل فاحص — زرُّ اختبارٍ لا يصير مسبارَ منافذ */
    public function test_every_tester_is_throttled(): void
    {
        $this->seedCore();
        $this->defaultOdoo();
        $this->fakeOdoo('https://odoo.example.com',
            ['common.version' => ['server_version' => '17.0'], 'common.authenticate' => 2]);
        $row = OdooConnection::create(['name' => 'أودو المتجر', 'url' => 'https://odoo.example.com',
            'db' => 'shopdb', 'username' => 'ro@shop.example.com', 'key_cipher' => 'k-2', 'active' => true]);

        foreach (['/admin/settings/odoo-test', '/admin/integrations/n8n/test',
                  '/admin/integrations/odoo/' . $row->id . '/test'] as $path) {
            $last = null;
            for ($i = 0; $i < 12; $i++) {
                $last = $this->actingAs($this->owner)->post($path);
                if ($last->getStatusCode() === 429) break;
            }
            $this->assertSame(429, $last->getStatusCode(), "لا خنقَ على {$path}");
        }
    }
}
