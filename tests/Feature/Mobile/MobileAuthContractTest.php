<?php

namespace Tests\Feature\Mobile;

use Tests\TestCase;

/**
 * **عقدُ سطحِ الجوال** — Mobile Readiness · الطور B.
 *
 * يثبّت ما يجب ألّا ينزلق: خنقٌ ضيّقٌ لكلِّ نقطة (F4)، فصلُ المجموعتين عامّةٌ/مُصادَقة
 * (F5)، حلُّ كلِّ مسارٍ حرفيٍّ إلى معالجه (F9)، صدقُ `app-config`/`health` بلا أسرار،
 * و**عدمُ مساسِ `/api/v1`** (ApiAuth/ApiToken كما هي — التكاملُ يعمل byte-for-byte).
 */
class MobileAuthContractTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function routeMiddleware(string $name): array
    {
        return app('router')->getRoutes()->getByName($name)->gatherMiddleware();
    }

    // ════════════════════════════ الخنقُ الضيّق (F4) والفصلُ (F5) ════════════════════════════

    public function test_public_auth_routes_carry_tight_per_endpoint_throttles_not_the_loose_api_limit(): void
    {
        $this->seedCore();

        $login = $this->routeMiddleware('mobile.auth.login');
        $this->assertContains('throttle:10,1', $login, 'الدخول 10,1 (نظيرُ الويب)');
        $this->assertNotContains('throttle:api', $login, 'ليس الخنقَ الفضفاض 300/دقيقة');
        $this->assertNotContains('mobile.session', $login, 'الدخولُ عامٌّ — لا رمزَ بعد');

        $mfa = $this->routeMiddleware('mobile.auth.mfa_verify');
        $this->assertContains('throttle:6,1', $mfa, 'التحقّق الثنائي 6,1 (نظيرُ الويب)');
        $this->assertNotContains('mobile.session', $mfa);

        $refresh = $this->routeMiddleware('mobile.auth.refresh');
        $this->assertContains('throttle:20,1', $refresh, 'التحديثُ خنقٌ ضيّق');
        $this->assertNotContains('throttle:api', $refresh);
        // F5: التدويرُ يصادِق بـrefresh في معالجه — لا يمرّ بـmobile.session (تصادِق الوصول)
        $this->assertNotContains('mobile.session', $refresh);
    }

    public function test_authed_routes_sit_behind_mobile_session_middleware(): void
    {
        $this->seedCore();

        foreach ([
            'mobile.auth.logout', 'mobile.auth.logout_all',
            'mobile.auth.sessions.index', 'mobile.auth.sessions.destroy', 'mobile.auth.step_up',
        ] as $name) {
            $mw = $this->routeMiddleware($name);
            $this->assertContains('mobile.session', $mw, "{$name} خلف mobile.session");
            $this->assertContains('throttle:api', $mw, "{$name} يحمل الخنقَ (قبل المصادقة كنمط v1)");
        }
    }

    public function test_authed_routes_reject_calls_without_an_access_token(): void
    {
        $this->seedCore();

        // بلا رمزٍ — UNAUTHENTICATED (لا SESSION_REVOKED: لا جلسةَ يُنظَر إليها أصلاً)
        $this->postJson('/api/mobile/v1/auth/logout')
            ->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->getJson('/api/mobile/v1/auth/sessions')
            ->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    // ════════════════════════════ المساراتُ الحرفيّة تُحلّ (F9) ════════════════════════════

    public function test_every_literal_route_resolves_to_the_mobile_auth_controller(): void
    {
        $this->seedCore();
        $routes = app('router')->getRoutes();

        $map = [
            'mobile.auth.login'            => 'login',
            'mobile.auth.mfa_verify'       => 'mfaVerify',
            'mobile.auth.refresh'          => 'refresh',
            'mobile.app_config'            => 'appConfig',
            'mobile.health'                => 'health',
            'mobile.auth.logout'           => 'logout',
            'mobile.auth.logout_all'       => 'logoutAll',
            'mobile.auth.sessions.index'   => 'sessions',
            'mobile.auth.sessions.destroy' => 'destroySession',
            'mobile.auth.step_up'          => 'stepUp',
        ];
        foreach ($map as $name => $method) {
            $action = $routes->getByName($name)->getActionName();
            $this->assertSame(
                \App\Http\Controllers\Api\MobileAuthController::class . '@' . $method,
                $action, "المسار {$name} يحلّ إلى {$method}"
            );
        }
    }

    // ════════════════════════════ app-config / health: صدقٌ بلا أسرار ════════════════════════════

    public function test_app_config_is_public_truthful_and_carries_no_secrets(): void
    {
        $this->seedCore();

        $res = $this->getJson('/api/mobile/v1/app-config');
        $res->assertOk();
        $data = $res->json('data');

        $this->assertSame('1', $data['mobile_api_version']);
        $this->assertTrue($data['login_available']);
        $this->assertFalse($data['maintenance']);
        $this->assertFalse($data['lockdown']);
        // بوّابةُ الإصدار فارغةٌ عمداً في الطور B — لا تُحجَب نسخُ التطوير
        $this->assertNull($data['version_gate']['ios']['min']);
        $this->assertFalse($data['version_gate']['force_update']);

        // لا أثرَ لأيّ سرٍّ في الحمولة كلِّها
        $blob = json_encode($data, JSON_UNESCAPED_UNICODE);
        foreach (['secret', 'cipher', 'password', 'token_hash', 'private'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, (string) $blob, "لا {$needle} في app-config");
        }
    }

    public function test_health_reports_status_without_infra_telemetry(): void
    {
        $this->seedCore();

        $res = $this->getJson('/api/mobile/v1/health');
        $res->assertOk();
        $data = $res->json('data');
        $this->assertSame('ok', $data['status']);
        $this->assertFalse($data['update_required']);
        $this->assertArrayHasKey('server_time', $data);
    }

    // ════════════════════════════ /api/v1 لم يُمَسّ (توافقٌ خلفيّ) ════════════════════════════

    public function test_existing_api_token_still_reaches_v1_me_exactly_as_before(): void
    {
        $this->seedCore();
        $token = $this->apiToken($this->owner);

        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->getJson('/api/v1/me');
        $res->assertOk();
        $this->assertSame($this->owner->id, $res->json('id'));
        $this->assertSame($this->owner->email, $res->json('email'));
        $this->assertTrue($res->json('is_owner'));
        $res->assertHeader('X-API-Version', '1');
    }

    public function test_existing_api_token_still_reaches_v1_module_index_exactly_as_before(): void
    {
        $this->seedCore();
        $token = $this->apiToken($this->owner);

        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->getJson('/api/v1/tasks');
        $res->assertOk();
        $body = $res->json();
        // الغلافُ القديمُ للقوائم كما هو (data/total/page/last_page)
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('total', $body);
        $this->assertArrayHasKey('last_page', $body);
    }

    public function test_mobile_access_token_cannot_be_used_on_the_v1_integration_surface(): void
    {
        $this->seedCore();
        // رمزُ وصولِ الجوال مفهومٌ مستقلٌّ عن مفتاح التكامل — لا يُقبَل على /api/v1
        $data = $this->mobileLogin($this->employee);

        $this->withHeaders($this->bearer($data['access_token']))->getJson('/api/v1/me')
            ->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
