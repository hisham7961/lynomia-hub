<?php

namespace Tests\Feature\Mobile;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * **حارسُ عزلِ المسارات — Critic F9** (Mobile Readiness · الطور C).
 *
 * المساراتُ الحرفيّةُ في `api/mobile/v1` (السياق/الإقلاع/المخطّط + العامّتان
 * app-config/health) يجب أن يحلَّ كلٌّ منها إلى **معالجه الخاصّ** — لا إلى أيّ
 * catch-all. الطورُ D سيُضيف `GET {module}` في **نفسِ الملفّ**، وترتيبُ التسجيل
 * (الحرفيُّ قبل العامّ) هو وحدَه ما يمنع ابتلاعَه إيّاها (نظيرُ انضباط
 * `routes/api.php:15,21,26` على `/api/v1`). هذا الحارسُ يثبّت العقدَ الآن: إن
 * سبَقَ لاحقاً catch-all المساراتِ الحرفيّةَ فستحلُّ إليه — فيسقط هذا الاختبارُ
 * فوراً بدل أن يمرّ التسريبُ صامتاً (نظيرُ حذرِ CLAUDE.md: الفحصُ يمرّ على الكلّ).
 *
 * فحصٌ على مستوى جدولِ التوجيه (URI+فعل) لا يمرّ بالوسائط — فلا يحتاج جلسةً.
 */
class MobileRouteIsolationTest extends TestCase
{
    /** يحلّ مسارَ GET إلى الطريقِ المطابق (اسمُه + معالجه) عبر جدولِ التوجيه وحدَه */
    private function resolve(string $uri): \Illuminate\Routing\Route
    {
        return Route::getRoutes()->match(Request::create($uri, 'GET'));
    }

    public function test_literal_context_routes_each_resolve_to_their_own_handler(): void
    {
        // كلُّ مسارٍ حرفيٍّ ⇒ اسمُه المتوقّعُ ومعالجُه المتوقّع (لا catch-all يبتلعه)
        $expected = [
            '/api/mobile/v1/context'        => ['mobile.context',        'App\\Http\\Controllers\\Api\\MobileContextController@context'],
            '/api/mobile/v1/bootstrap'      => ['mobile.bootstrap',      'App\\Http\\Controllers\\Api\\MobileContextController@bootstrap'],
            '/api/mobile/v1/schema'         => ['mobile.schema',         'App\\Http\\Controllers\\Api\\MobileContextController@schema'],
            '/api/mobile/v1/schema/modules' => ['mobile.schema.modules', 'App\\Http\\Controllers\\Api\\MobileContextController@schemaModules'],
        ];

        foreach ($expected as $uri => [$name, $action]) {
            $route = $this->resolve($uri);
            $this->assertSame($name, $route->getName(), "المسارُ «{$uri}» حُلّ إلى «{$route->getName()}» لا «{$name}» — هل ابتلعه catch-all؟");
            $this->assertSame($action, $route->getActionName(), "المسارُ «{$uri}» يجب أن يحلَّ إلى معالجه الخاصّ (F9)");
        }
    }

    public function test_public_status_routes_resolve_to_mobile_auth_controller(): void
    {
        // العامّتان (app-config/health) خارجَ المجموعةِ المُصادَقة — لكلٍّ معالجُه
        $appConfig = $this->resolve('/api/mobile/v1/app-config');
        $this->assertSame('mobile.app_config', $appConfig->getName());
        $this->assertSame('App\\Http\\Controllers\\Api\\MobileAuthController@appConfig', $appConfig->getActionName());

        $health = $this->resolve('/api/mobile/v1/health');
        $this->assertSame('mobile.health', $health->getName());
        $this->assertSame('App\\Http\\Controllers\\Api\\MobileAuthController@health', $health->getActionName());
    }

    public function test_authed_context_routes_carry_session_and_context_middleware(): void
    {
        // العقدُ الأمنيّ: القراءةُ خلف mobile.session (الهويّة) + mobile.context (التضييق)
        foreach (['/api/mobile/v1/context', '/api/mobile/v1/bootstrap', '/api/mobile/v1/schema', '/api/mobile/v1/schema/modules'] as $uri) {
            $mw = $this->resolve($uri)->gatherMiddleware();
            $this->assertContains('mobile.session', $mw, "«{$uri}» يجب أن تكون خلف mobile.session");
            $this->assertContains('mobile.context', $mw, "«{$uri}» يجب أن تحمل mobile.context (تضييقُ العرض · SF-4)");
        }

        // والعامّتان لا تحملان mobile.session (ما قبل الدخول · F5)
        foreach (['/api/mobile/v1/app-config', '/api/mobile/v1/health'] as $uri) {
            $mw = $this->resolve($uri)->gatherMiddleware();
            $this->assertNotContains('mobile.session', $mw, "«{$uri}» عامّةٌ — لا رمزَ وصولٍ بعد");
        }
    }
}
