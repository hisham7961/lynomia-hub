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

    /** يحلّ مساراً بفعلٍ محدَّد (لاختبار POST/PUT/PATCH/DELETE على الـcatch-all والحرفيّات) */
    private function resolveMethod(string $uri, string $method): \Illuminate\Routing\Route
    {
        return Route::getRoutes()->match(Request::create($uri, $method));
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

    /* ══════════════════ الطور D — تكافؤُ واجهةِ الأعمال (Critic F9) ══════════════════ */

    /**
     * كلُّ مسارٍ حرفيٍّ في الطور D (اعتمادات/لوحة/بحث/تفضيلات + لاحقةُ الإجراءات) يحلّ
     * إلى معالجه الخاصّ — **لا يبتلعه** الـcatch-all `{module}` المُسجَّلُ بعده. لو سبَقَ
     * الـcatch-all لسقط هذا فوراً بدل أن يمرّ التسريبُ صامتاً.
     */
    public function test_phase_d_literal_routes_are_not_swallowed_by_the_module_catch_all(): void
    {
        $W = 'App\\Http\\Controllers\\Api\\MobileWorkController@';
        $R = 'App\\Http\\Controllers\\Api\\MobileResourceController@';

        $expected = [
            // [uri, method] => [name, action]
            ['/api/mobile/v1/approvals',            'GET',    'mobile.approvals.index',   $W . 'approvals'],
            ['/api/mobile/v1/approvals/abc-123',    'GET',    'mobile.approvals.show',     $W . 'approvalShow'],
            ['/api/mobile/v1/approvals/abc-123/approve', 'POST', 'mobile.approvals.approve', $W . 'approvalApprove'],
            ['/api/mobile/v1/approvals/abc-123/reject',  'POST', 'mobile.approvals.reject',  $W . 'approvalReject'],
            ['/api/mobile/v1/home',                 'GET',    'mobile.home',               $W . 'home'],
            ['/api/mobile/v1/search',               'GET',    'mobile.search',             $W . 'search'],
            ['/api/mobile/v1/prefs',                'GET',    'mobile.prefs.index',        $W . 'prefs'],
            ['/api/mobile/v1/prefs',                'PUT',    'mobile.prefs.update',       $W . 'prefsUpdate'],
            ['/api/mobile/v1/prefs/pin',            'POST',   'mobile.prefs.pin',          $W . 'pin'],
            // لاحقةُ الإجراءات على المورد — قبل `{module}/{id}` (المقطعُ الحرفيّ actions)
            ['/api/mobile/v1/tickets/xyz-9/actions', 'GET',   'mobile.resource.actions',   $R . 'listActions'],
            ['/api/mobile/v1/tickets/xyz-9/actions/close', 'POST', 'mobile.resource.run_action', $R . 'runAction'],
        ];

        foreach ($expected as [$uri, $method, $name, $action]) {
            $route = $this->resolveMethod($uri, $method);
            $this->assertSame($name, $route->getName(),
                "«{$method} {$uri}» حُلّ إلى «{$route->getName()}» لا «{$name}» — هل ابتلعه catch-all؟ (F9)");
            $this->assertSame($action, $route->getActionName(),
                "«{$method} {$uri}» يجب أن يحلَّ إلى معالجه الحرفيّ الخاصّ (F9)");
        }
    }

    /**
     * الـcatch-all `{module}` (CRUD) يحلّ **فقط** ما ليس حرفيّاً — مقطعٌ مفردٌ مجهولٌ
     * (وحدةٌ حقيقية) أو `{module}/{id}`. وهو مُسجَّلٌ **أخيراً** فلا يظلّل السياقَ/الإقلاع/
     * المخطّط/اللوحة/البحث (Critic F9). كما لا يظلّل الجوهرُ العامُّ الاعتماداتِ الحرفيّة.
     */
    public function test_module_catch_all_resolves_only_unknown_single_segment_and_id(): void
    {
        $R = 'App\\Http\\Controllers\\Api\\MobileResourceController@';

        // وحدةٌ حقيقيّةٌ (مقطعٌ مفردٌ ليس حرفيّاً) ⇒ الـcatch-all
        $index = $this->resolveMethod('/api/mobile/v1/tickets', 'GET');
        $this->assertSame('mobile.resource.index', $index->getName());
        $this->assertSame($R . 'listRecords', $index->getActionName());

        $show = $this->resolveMethod('/api/mobile/v1/tickets/abc-1', 'GET');
        $this->assertSame('mobile.resource.show', $show->getName());
        $this->assertSame($R . 'showRecord', $show->getActionName());

        foreach (['POST' => 'createRecord', 'GET' => 'listRecords'] as $method => $m) {
            $route = $this->resolveMethod('/api/mobile/v1/tickets', $method);
            $this->assertSame($R . $m, $route->getActionName());
        }
        foreach (['PUT' => 'replaceRecord', 'PATCH' => 'patchRecord', 'DELETE' => 'deleteRecord'] as $method => $m) {
            $route = $this->resolveMethod('/api/mobile/v1/tickets/abc-1', $method);
            $this->assertSame($R . $m, $route->getActionName(),
                "«{$method} tickets/abc-1» يجب أن يحلَّ إلى catch-all @{$m}");
        }

        // والحرفيّاتُ أُحاديّةُ المقطع لا يبتلعها `GET {module}` (السياق/الإقلاع/المخطّط/اللوحة/البحث)
        foreach ([
            '/api/mobile/v1/context', '/api/mobile/v1/bootstrap', '/api/mobile/v1/schema',
            '/api/mobile/v1/home', '/api/mobile/v1/search', '/api/mobile/v1/approvals', '/api/mobile/v1/prefs',
        ] as $uri) {
            $this->assertNotSame($R . 'listRecords', $this->resolve($uri)->getActionName(),
                "«GET {$uri}» ابتلعه catch-all `{module}` — الحرفيُّ يجب أن يسبق (F9)");
        }
    }

    /**
     * تأكيدٌ صريحٌ للعقد (نصُّ المهمّة · F9): كلُّ حرفيٍّ `GET` (context/bootstrap/schema/
     * home/search) يحلّ إلى معالجه الخاصّ **لا** إلى `{module}` CRUD، ومقطعٌ مفردٌ
     * مجهولٌ يحلّ إلى الـcatch-all. برهانٌ على أنّ `GET {module}` لا يظلّل أياً منها.
     */
    public function test_f9_literal_gets_never_shadowed_and_unknown_segment_is_crud(): void
    {
        $catchAll = 'App\\Http\\Controllers\\Api\\MobileResourceController@listRecords';

        // الحرفيّاتُ لا تحلّ إلى الـcatch-all، بل كلٌّ لمعالجه
        $ownHandler = [
            '/api/mobile/v1/context'   => 'App\\Http\\Controllers\\Api\\MobileContextController@context',
            '/api/mobile/v1/bootstrap' => 'App\\Http\\Controllers\\Api\\MobileContextController@bootstrap',
            '/api/mobile/v1/schema'    => 'App\\Http\\Controllers\\Api\\MobileContextController@schema',
            '/api/mobile/v1/home'      => 'App\\Http\\Controllers\\Api\\MobileWorkController@home',
            '/api/mobile/v1/search'    => 'App\\Http\\Controllers\\Api\\MobileWorkController@search',
        ];
        foreach ($ownHandler as $uri => $action) {
            $resolved = $this->resolve($uri);
            $this->assertNotSame($catchAll, $resolved->getActionName(), "«GET {$uri}» ابتلعه {module} (F9)");
            $this->assertSame($action, $resolved->getActionName(), "«GET {$uri}» يجب أن يحلَّ لمعالجه الخاصّ");
        }

        // مقطعٌ مفردٌ مجهولٌ (وحدة) ⇒ الـcatch-all CRUD
        $this->assertSame($catchAll, $this->resolve('/api/mobile/v1/some_unknown_module')->getActionName(),
            'المقطعُ المفردُ المجهولُ يجب أن يحلَّ إلى CRUD `{module}`');
    }
}
