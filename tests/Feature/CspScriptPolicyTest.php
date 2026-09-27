<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\ErrorEvent;
use App\Models\Setting;
use App\Support\Platform\Settings;
use App\Support\Security\ContentSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * **سياسةُ السكربتات في CSP** (بند الدَّين #12 · FE-03) — مفروضةٌ افتراضياً منذ v2.617.
 *
 *  · `enforce` (الافتراضي): `script-src 'self' 'nonce-…'; script-src-attr 'none'` داخل
 *    Content-Security-Policy، ولا Report-Only — لا معالجاتِ سماتٍ ولا نصوصَ بلا nonce.
 *  · `report`: السياسةُ نفسُها في Report-Only، والمفروضةُ كما كانت حرفياً — لا يُحجب شيء.
 *  · `off`: لا `script-src` في أيّ ترويسة.
 *  · والـnonce الذي تطبعه القوالبُ (`@cspNonce`) هو الذي تكتبه الترويسة، وكلُّ سكربتٍ مضمَّنٍ
 *    في صفحاتٍ حقيقيّةٍ يحمله.
 *  · واستثناءٌ مسمّى واحد (`allowLegacyInline` — QuoteFlow) لا يتسرّب إلى طلبٍ آخر.
 *  · ومستقبِلُ التقارير عامٌّ بلا CSRF، يقبل صفحاتِنا وحدها، ويسجّل بلا إشعار.
 */
class CspScriptPolicyTest extends TestCase
{
    protected function mode(?string $v): void
    {
        if ($v === null) Setting::where('key', 'security.csp_script')->delete();
        else Setting::updateOrCreate(['key' => 'security.csp_script'], ['value' => $v]);
        Cache::forget('settings:all');
    }

    protected function pass(Request $req, $resp = null)
    {
        $resp ??= new Response('<html>ok</html>');
        app()->instance('request', $req);

        return (new SecurityHeaders())->handle($req, fn () => $resp);
    }

    public function test_default_is_enforce_with_no_inline_attribute_handlers(): void
    {
        $this->mode(null);
        $this->assertSame('enforce', ContentSecurity::mode());
        $out = $this->pass(Request::create('http://localhost/dashboard', 'GET'));

        $csp = (string) $out->headers->get('Content-Security-Policy');
        $this->assertStringStartsWith("base-uri 'self'; object-src 'none'; frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
        $this->assertStringContainsString("script-src-attr 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp, 'السياسةُ الصارمة ما زالت تسمح بالنصوص المضمَّنة');
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringContainsString('report-uri /csp-report', $csp);
        $this->assertNull($out->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_report_keeps_the_enforced_policy_unchanged_and_reports_the_strict_one(): void
    {
        $this->mode('report');
        $out = $this->pass(Request::create('http://localhost/dashboard', 'GET'));

        $this->assertSame("base-uri 'self'; object-src 'none'; frame-ancestors 'self'",
            $out->headers->get('Content-Security-Policy'), 'وضعُ التقرير غيّر السياسةَ المفروضة');
        $ro = (string) $out->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("script-src 'self' 'nonce-", $ro);
        $this->assertStringContainsString("script-src-attr 'none'", $ro);
        $this->assertStringContainsString('report-uri /csp-report', $ro);
    }

    public function test_enforce_puts_script_src_into_the_enforced_header(): void
    {
        $this->mode('enforce');
        $out = $this->pass(Request::create('http://localhost/dashboard', 'GET'));

        $csp = (string) $out->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
        $this->assertStringContainsString('report-uri /csp-report', $csp);
        $this->assertNull($out->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_the_named_legacy_exception_is_per_request_only(): void
    {
        $this->mode('enforce');
        $req = Request::create('http://localhost/quoteflow', 'GET');
        app()->instance('request', $req);
        ContentSecurity::allowLegacyInline(['https://cdn.example/lib/x.min.js', "https://evil.example/ 'unsafe-eval'", '*']);
        $out = (new SecurityHeaders())->handle($req, fn () => new Response('<html>qf</html>'));
        $csp = (string) $out->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' https://cdn.example/lib/x.min.js; script-src-attr 'unsafe-inline'", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp, 'مصدرٌ مُمرَّرٌ حقن كلمةً مفتاحيّة');
        $this->assertStringNotContainsString('evil.example', $csp);
        $this->assertStringNotContainsString('nonce-', $csp, 'nonce مع unsafe-inline يُبطلها في المتصفّح');

        // الطلبُ التالي لا يرث الاستثناء
        $next = $this->pass(Request::create('http://localhost/dashboard', 'GET'));
        $this->assertStringContainsString("script-src-attr 'none'", (string) $next->headers->get('Content-Security-Policy'));
    }

    public function test_off_emits_no_script_policy_at_all(): void
    {
        $this->mode('off');
        $out = $this->pass(Request::create('http://localhost/dashboard', 'GET'));

        $this->assertStringNotContainsString('script-src', (string) $out->headers->get('Content-Security-Policy'));
        $this->assertNull($out->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_an_unknown_value_never_enforces(): void
    {
        $this->mode('ENFORCE-ALL');
        $this->assertSame('report', ContentSecurity::mode());
        $this->assertNotNull(Settings::validate('security.csp_script', 'ENFORCE-ALL'), 'الشاشة قبلت قيمةً مجهولة');
        $this->assertNull(Settings::validate('security.csp_script', 'enforce'));
    }

    public function test_json_and_controller_locked_responses_are_untouched(): void
    {
        $this->mode('enforce');
        $json = $this->pass(Request::create('http://localhost/api/x', 'GET'), new JsonResponse(['ok' => true]));
        $this->assertStringNotContainsString('script-src', (string) $json->headers->get('Content-Security-Policy'));

        $this->mode('report');
        $locked = new Response('file');
        $locked->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'");
        $out = $this->pass(Request::create('http://localhost/files/hub/x.png', 'GET'), $locked);
        $this->assertSame("default-src 'none'; style-src 'unsafe-inline'", $out->headers->get('Content-Security-Policy'));
        $this->assertNull($out->headers->get('Content-Security-Policy-Report-Only'), 'سياسةُ المرفق المقفلة أُلحق بها تقرير');
    }

    public function test_the_page_nonce_matches_the_header_nonce(): void
    {
        $this->seedCore();
        $this->mode('report');

        $res = $this->actingAs($this->owner)->get('/')->assertOk();
        $ro = (string) $res->headers->get('Content-Security-Policy-Report-Only');
        $this->assertMatchesRegularExpression("/'nonce-([A-Za-z0-9+\/=]{24})'/", $ro);
        preg_match("/'nonce-([A-Za-z0-9+\/=]{24})'/", $ro, $m);

        // سكربتُ السِّمة في رأس القشرة يحمل nonce الطلب نفسَه
        $this->assertStringContainsString('<script nonce="' . $m[1] . '">', (string) $res->getContent());
    }

    /**
     * الصفحاتُ الحقيقيّة (لا القوالبُ وحدها) مفروضةً: كلُّ `<script>` تنفيذيٍّ مضمَّنٍ يحمل
     * nonce الترويسة، ولا سمةَ `on…=` ولا `javascript:` في الناتج — ما يراه المتصفّح فعلاً.
     */
    public function test_rendered_pages_are_clean_under_enforce(): void
    {
        $this->seedCore();
        $this->mode('enforce');

        $pages = ['/', '/m/clients', '/m/clients/create', '/settings', '/notifications', '/system-map', '/kpis', '/esign'];
        $seen = 0;
        foreach ($pages as $uri) {
            $res = $this->actingAs($this->owner)->get($uri);
            if ($res->getStatusCode() !== 200) continue;
            $seen++;
            $csp = (string) $res->headers->get('Content-Security-Policy');
            $this->assertMatchesRegularExpression("/'nonce-([A-Za-z0-9+\/=]{24})'/", $csp, $uri);
            preg_match("/'nonce-([A-Za-z0-9+\/=]{24})'/", $csp, $m);
            $html = (string) $res->getContent();

            preg_match_all('/<script\b([^>]*)>/i', $html, $tags);
            foreach ($tags[1] as $attrs) {
                if (preg_match('/\bsrc=|application\/(ld\+)?json/i', $attrs)) continue;
                $this->assertStringContainsString('nonce="' . $m[1] . '"', $attrs, "$uri: سكربتٌ مضمَّنٌ بلا nonce الطلب");
            }
            $body = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]{3,}\s*=\s*["\']/i', $body, "$uri: معالجُ حدثٍ في سمة");
            $this->assertStringNotContainsStringIgnoringCase('javascript:', $body, "$uri: رابطُ javascript:");
        }
        $this->assertGreaterThanOrEqual(4, $seen, 'الصفحاتُ المفحوصة لم تُعرض — الاختبارُ لا يقيس شيئاً');
    }

    public function test_report_endpoint_is_public_csrf_exempt_and_logs_same_origin_reports(): void
    {
        $this->seedCore();
        $body = json_encode(['csp-report' => [
            'document-uri' => url('/m/clients') . '?token=secret123',
            'effective-directive' => 'script-src-elem',
            'blocked-uri' => 'https://evil.example/x.js?k=v',
            'source-file' => url('/m/clients'),
            'line-number' => 12,
            'disposition' => 'report',
        ]]);

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $body)
            ->assertNoContent();

        $rows = ErrorEvent::where('kind', 'js')->orderBy('id')->get();
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('CSP script-src-elem', $rows[0]->message);
        $this->assertStringContainsString('https://evil.example/x.js', $rows[0]->message);
        $this->assertStringNotContainsString('secret123', $rows[0]->message, 'سلسلةُ الاستعلام وصلت السجلّ');
        $this->assertStringNotContainsString('k=v', $rows[0]->message);
    }

    public function test_report_endpoint_drops_foreign_pages_garbage_and_oversize(): void
    {
        $send = fn (string $b) => $this->call('POST', '/csp-report', [], [], [],
            ['CONTENT_TYPE' => 'application/csp-report'], $b)->assertNoContent();

        $send(json_encode(['csp-report' => ['document-uri' => 'https://other.example/page', 'blocked-uri' => 'inline']]));
        $send('not json');
        $send(json_encode(['csp-report' => ['document-uri' => url('/x'), 'blocked-uri' => str_repeat('a', 20000)]]));

        $this->assertSame(0, ErrorEvent::where('kind', 'js')->count(), 'تقريرٌ أجنبيّ أو فاسدٌ أو ضخمٌ سُجِّل');
    }

    public function test_reporting_api_format_is_accepted(): void
    {
        $this->seedCore();
        $body = json_encode([['type' => 'csp-violation', 'body' => [
            'documentURL' => url('/field/sessions'), 'effectiveDirective' => 'script-src-elem',
            'blockedURL' => 'inline', 'lineNumber' => 3,
        ]]]);
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], $body)
            ->assertNoContent();

        $row = ErrorEvent::where('kind', 'js')->orderBy('id')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('inline', $row->message);
        $this->assertStringContainsString('/field/sessions', $row->message);
    }

    public function test_daily_cap_bounds_what_is_stored(): void
    {
        Cache::put('cspreport:' . now()->toDateString(), ContentSecurity::REPORT_DAILY_CAP, now()->endOfDay());
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'],
            json_encode(['csp-report' => ['document-uri' => url('/x'), 'blocked-uri' => 'inline']]))
            ->assertNoContent();

        $this->assertSame(0, ErrorEvent::where('kind', 'js')->count());
    }
}
