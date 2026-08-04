<?php

namespace Tests\Feature;

use App\Support\Uptime;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الموجة الثالثة (مؤجّل) — TOCTOU على DNS للمسبار:
 * `hub_outbound_ok` يحلّ الاسمَ ويتأكّد أنّ عنوانه عامّ، لكنّ المُتّصلَ بعده
 * (`Http::get($url)`) يمرّر **الاسمَ** فيُعيد curl تحليلَه وقت الاتصال — فـDNS
 * متقلّبٌ (rebinding) يردّ عنواناً عامّاً للفحص ثم داخليّاً (169.254.169.254 /
 * 127.0.0.1) للاتصال، فيلتفّ حول الحارس ويصير النظام مِجَسّاً على شبكته.
 *
 * الإصلاح: تثبيتُ الاتصال على **العنوان الذي أجازه الحارس** عبر `CURLOPT_RESOLVE`،
 * فلا يُعيد curl التحليل ولا يُبدّله DNS بين الفحص والاتصال.
 */
class OutboundDnsPinRound3Test extends TestCase
{
    /** الوسمُ يثبّت المضيفَ على العنوان المُجاز — مع منفذ https الافتراضي */
    public function test_pin_targets_the_gate_validated_ip(): void
    {
        $this->assertSame(
            [CURLOPT_RESOLVE => ['evil.test:443:93.184.216.34']],
            hub_resolve_pin('https://evil.test/latest/meta-data?x=1', '93.184.216.34'),
            'العلامة لم تثبّت المضيفَ على العنوان الذي أجازه الحارس'
        );
    }

    /** يحترم المخطّط والمنفذ: http الافتراضي ٨٠، والمنفذ الصريح يُحفَظ */
    public function test_pin_respects_scheme_and_custom_port(): void
    {
        $this->assertSame(
            [CURLOPT_RESOLVE => ['evil.test:80:1.2.3.4']],
            hub_resolve_pin('http://evil.test/x', '1.2.3.4')
        );
        $this->assertSame(
            [CURLOPT_RESOLVE => ['evil.test:8443:1.2.3.4']],
            hub_resolve_pin('https://evil.test:8443/x', '1.2.3.4')
        );
    }

    /** بلا عنوانٍ مُجاز (السماح بالخاص) لا تثبيت — يُترك الاتصال طبيعيّاً */
    public function test_no_pin_without_a_validated_ip(): void
    {
        $this->assertSame([], hub_resolve_pin('https://evil.test/x', null));
        $this->assertSame([], hub_resolve_pin('not a url', '1.2.3.4'));
    }

    /**
     * دفاعُ إعادة الربط عند الحلقة كاملةً: الحارس يحلّ الاسمَ إلى عنوانٍ عامّ
     * فيُجيزه، والوسمُ يثبّت الاتصالَ على **ذلك** العنوان — فلو ردّ DNS بعده
     * عنواناً داخليّاً لظلّ الاتصالُ مربوطاً بالعامّ المُجاز لا الداخليّ.
     */
    public function test_rebinding_defense_pins_the_ip_the_gate_approved(): void
    {
        $this->app->instance('hub.dns', fn (string $h) => ['93.184.216.34']);

        $gate = hub_outbound_ok('https://rebind.test/probe');
        $this->assertTrue($gate['ok']);
        $this->assertSame('93.184.216.34', $gate['ip'], 'الحارس لم يُعِد العنوانَ المُجاز للتثبيت');

        $pin = hub_resolve_pin('https://rebind.test/probe', $gate['ip']);
        $this->assertSame([CURLOPT_RESOLVE => ['rebind.test:443:93.184.216.34']], $pin);
    }

    /** المسبار يبقى يعمل بعد التثبيت — لا انحدار في المسار الشرعيّ */
    public function test_probe_still_works_with_pinning(): void
    {
        $this->seedCore();
        $this->app->instance('hub.dns', fn (string $h) => ['93.184.216.34']);
        Http::fake(['api.example.com/*' => Http::response('ok', 200)]);

        $s = \App\Models\Server::create([
            'name' => 'خادم', 'provider' => 'Hetzner', 'status' => 'نشط',
            'monitor_url' => 'https://api.example.com/health', 'monitor_on' => true,
        ]);

        $r = Uptime::check('servers', $s);
        $this->assertTrue($r['up'], 'المسبار توقّف بعد إضافة التثبيت');
        $this->assertSame(200, $r['code']);
    }
}
