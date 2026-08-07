<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * تصليبُ الترويسات الأمنية (v2.357) — **أقصى الأمان بلا كسر الواجهة**.
 *
 *  · HSTS يُثبّت HTTPS على اتصالٍ آمن فقط (لا على http محليّ).
 *  · CSP دفاعيّة (base-uri/object-src/frame-ancestors) تُضاف حين لا سياسةَ أخصّ.
 *  · وحين يضبط متحكّمٌ سياسةً أقفلَ (خدمةُ المرفقات: `default-src 'none'`)،
 *    **لا تُضعَّف** بالأوسع — الحارسُ يحترم الأخصّ.
 */
class SecurityHeadersHardeningTest extends TestCase
{
    protected function pass(Request $req, ?Response $resp = null): Response
    {
        $resp ??= new Response('ok');

        return (new SecurityHeaders())->handle($req, fn () => $resp);
    }

    public function test_conservative_csp_is_added_when_absent(): void
    {
        $out = $this->pass(Request::create('http://localhost/dashboard', 'GET'));

        $csp = (string) $out->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
    }

    public function test_a_stricter_controller_csp_is_not_weakened(): void
    {
        // كخدمة المرفقات: تقفل default-src 'none' — يجب أن تبقى كما هي
        $locked = new Response('file');
        $locked->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'");

        $out = $this->pass(Request::create('http://localhost/files/hub/x.png', 'GET'), $locked);

        $this->assertSame("default-src 'none'; style-src 'unsafe-inline'",
            $out->headers->get('Content-Security-Policy'),
            'السياسةُ المقفلة للمرفق ضُعِّفت بالسياسة العامّة الأوسع');
    }

    public function test_hsts_only_over_https(): void
    {
        $secure = $this->pass(Request::create('https://localhost/dashboard', 'GET'));
        $this->assertStringContainsString('max-age=31536000',
            (string) $secure->headers->get('Strict-Transport-Security'));

        // على http لا تُرسَل — كي لا تُثبَّت على تطويرٍ/اختبارٍ غير آمن
        $plain = $this->pass(Request::create('http://localhost/dashboard', 'GET'));
        $this->assertNull($plain->headers->get('Strict-Transport-Security'),
            'HSTS أُرسلت على اتصالٍ غير آمن');
    }
}
