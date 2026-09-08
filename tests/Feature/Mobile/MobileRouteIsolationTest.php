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

    /* ══════════════════ الطور E — الاتصال (Critic F9) ══════════════════ */

    /**
     * كلُّ مسارٍ حرفيٍّ في الطور E (إشعارات/تعليقات/DM/دفع) يحلّ إلى معالجه الخاصّ —
     * **لا يبتلعه** الـcatch-all `{module}` المُسجَّلُ بعده. لو سبَقَ الـcatch-all
     * لحلَّ `GET notifications` إلى `apiIndex('notifications')` و`GET
     * notifications/unread-count` إلى `{module}/{id}` و`POST push/register` إلى
     * `{module}/{id}` — فيسقط هذا فوراً بدل أن يمرّ التسريبُ صامتاً.
     */
    public function test_phase_e_literal_routes_are_not_swallowed_by_the_module_catch_all(): void
    {
        $C = 'App\\Http\\Controllers\\Api\\MobileCommController@';
        $P = 'App\\Http\\Controllers\\Api\\MobilePushController@';

        $expected = [
            // [uri, method] => [name, action]
            ['/api/mobile/v1/notifications',              'GET',  'mobile.notifications.index',    $C . 'notifications'],
            ['/api/mobile/v1/notifications/unread-count', 'GET',  'mobile.notifications.unread',    $C . 'unreadCount'],
            ['/api/mobile/v1/notifications/read-all',     'POST', 'mobile.notifications.read_all',  $C . 'markAllRead'],
            ['/api/mobile/v1/notifications/n-1/target',   'GET',  'mobile.notifications.target',    $C . 'notificationTarget'],
            ['/api/mobile/v1/notifications/n-1/read',     'POST', 'mobile.notifications.read',      $C . 'markRead'],
            ['/api/mobile/v1/comments',                   'GET',  'mobile.comments.index',          $C . 'comments'],
            ['/api/mobile/v1/comments',                   'POST', 'mobile.comments.store',          $C . 'postComment'],
            ['/api/mobile/v1/dm/threads',                 'GET',  'mobile.dm.threads',              $C . 'dmThreads'],
            ['/api/mobile/v1/dm/threads/u-7/messages',    'GET',  'mobile.dm.messages',             $C . 'dmMessages'],
            ['/api/mobile/v1/dm/threads/u-7/send',        'POST', 'mobile.dm.send',                 $C . 'dmSend'],
            ['/api/mobile/v1/dm/threads/u-7/read',        'POST', 'mobile.dm.read',                 $C . 'dmMarkRead'],
            ['/api/mobile/v1/push/register',              'POST', 'mobile.push.register',           $P . 'register'],
            ['/api/mobile/v1/push/unregister',            'POST', 'mobile.push.unregister',         $P . 'unregister'],
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
     * حرّاسٌ أخصّ لعقد E: أحاديّاتُ المقطع (`notifications`/`comments`) لا يبتلعها
     * `GET {module}`، وثنائيّاتُ المقطع (`dm/threads`, `push/register`) لا يبتلعها
     * `{module}/{id}` — برهانٌ صريحٌ على أنّ الحرفيَّ يسبق العامّ (F9).
     */
    public function test_phase_e_literals_never_shadowed_by_generic_crud(): void
    {
        $crudIndex = 'App\\Http\\Controllers\\Api\\MobileResourceController@listRecords';
        $crudShow  = 'App\\Http\\Controllers\\Api\\MobileResourceController@showRecord';
        $crudStore = 'App\\Http\\Controllers\\Api\\MobileResourceController@createRecord';

        // أحاديّةُ المقطع لا تحلّ إلى `{module}` CRUD
        $this->assertNotSame($crudIndex, $this->resolve('/api/mobile/v1/notifications')->getActionName(),
            '«GET notifications» ابتلعه {module} (F9)');
        $this->assertNotSame($crudIndex, $this->resolve('/api/mobile/v1/comments')->getActionName(),
            '«GET comments» ابتلعه {module} (F9)');

        // ثنائيّةُ المقطع لا تحلّ إلى `{module}/{id}` CRUD
        $this->assertNotSame($crudShow, $this->resolve('/api/mobile/v1/dm/threads')->getActionName(),
            '«GET dm/threads» ابتلعه {module}/{id} (F9)');
        $this->assertNotSame($crudShow, $this->resolve('/api/mobile/v1/notifications/unread-count')->getActionName(),
            '«GET notifications/unread-count» ابتلعه {module}/{id} (F9)');
        $this->assertNotSame($crudStore, $this->resolveMethod('/api/mobile/v1/push/register', 'POST')->getActionName(),
            '«POST push/register» ابتلعه {module}/{id} (F9)');
    }

    /* ══════════════════ الطور F — ملفّات/ماسح/موقع (Critic F9) ══════════════════ */

    /**
     * كلُّ مسارٍ حرفيٍّ في الطور F (files/identity/tracking) يحلّ إلى معالجه الخاصّ في
     * `MobileFileController` — **لا يبتلعه** الـcatch-all `{module}` المُسجَّلُ بعده. لو
     * سبَقَ الـcatch-all لحلَّ `POST tracking/start` إلى `{module}/{id}` و`GET
     * identity/resolve/x` إلى `{module}/{id}/actions` — فيسقط هذا فوراً بدل أن يمرّ
     * التسريبُ صامتاً. المقطعُ الثالثُ الحرفيّ (download/stream/complete/chunk) يميّزها
     * عن `actions`، لكنّ الانضباطَ يُبقيها أوّلاً على كل حال (نصُّ المهمّة · F9).
     */
    public function test_phase_f_literal_routes_are_not_swallowed_by_the_module_catch_all(): void
    {
        $F = 'App\\Http\\Controllers\\Api\\MobileFileController@';

        $expected = [
            // [uri, method] => [name, action]
            ['/api/mobile/v1/files/upload-session',                 'POST', 'mobile.files.upload_session',  $F . 'uploadSession'],
            ['/api/mobile/v1/files/upload-session/sess-1/chunk',    'PUT',  'mobile.files.upload_chunk',     $F . 'uploadChunk'],
            ['/api/mobile/v1/files/upload-session/sess-1/complete', 'POST', 'mobile.files.upload_complete',  $F . 'uploadComplete'],
            ['/api/mobile/v1/files/attach',                         'POST', 'mobile.files.attach',           $F . 'attach'],
            ['/api/mobile/v1/files/att-9/download',                 'GET',  'mobile.files.download',         $F . 'download'],
            ['/api/mobile/v1/files/att-9/stream',                   'GET',  'mobile.files.stream',           $F . 'stream'],
            ['/api/mobile/v1/identity/resolve/ABC-123',             'GET',  'mobile.identity.resolve',       $F . 'identityResolve'],
            ['/api/mobile/v1/tracking/start',                       'POST', 'mobile.tracking.start',         $F . 'trackingStart'],
            ['/api/mobile/v1/tracking/trk-1/points',                'POST', 'mobile.tracking.points',        $F . 'trackingPoints'],
            ['/api/mobile/v1/tracking/trk-1/end',                   'POST', 'mobile.tracking.end',           $F . 'trackingEnd'],
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
     * حرّاسٌ أخصّ لعقد F: أحاديّاتُ/ثنائيّاتُ المقطع لا يبتلعها الجوهرُ العامّ. `POST
     * tracking/start` و`POST files/attach` (مقطعان) لا يحلّان إلى `{module}` (POST
     * أحاديُّ المقطع فقط)، و`GET identity/resolve/x` (ثلاثةُ مقاطعَ، الأوّلُ حرفيّ)
     * لا يحلّ إلى `{module}/{id}/actions` (المقطعُ الثالثُ حرفيٌّ `actions`). برهانٌ
     * صريحٌ على أنّ الحرفيَّ يسبق العامّ.
     */
    public function test_phase_f_literals_never_shadowed_by_generic_crud(): void
    {
        $crudStore   = 'App\\Http\\Controllers\\Api\\MobileResourceController@createRecord';
        $crudActions = 'App\\Http\\Controllers\\Api\\MobileResourceController@listActions';

        $this->assertNotSame($crudStore, $this->resolveMethod('/api/mobile/v1/tracking/start', 'POST')->getActionName(),
            '«POST tracking/start» ابتلعه {module} (F9)');
        $this->assertNotSame($crudStore, $this->resolveMethod('/api/mobile/v1/files/attach', 'POST')->getActionName(),
            '«POST files/attach» ابتلعه {module} (F9)');
        $this->assertNotSame($crudActions, $this->resolve('/api/mobile/v1/identity/resolve/ABC-123')->getActionName(),
            '«GET identity/resolve/x» ابتلعه {module}/{id}/actions (F9)');
        // `files/{id}/download` (المقطعُ الثالثُ `download`) لا يُخلط بـ`{module}/{id}/actions`
        $this->assertNotSame($crudActions, $this->resolve('/api/mobile/v1/files/att-9/download')->getActionName(),
            '«GET files/{id}/download» ابتلعه {module}/{id}/actions (F9)');
    }

    /**
     * العقدُ الأمنيّ: كلُّ حرفيّاتِ الطور F خلف `mobile.session` (الهويّة) +
     * `mobile.context` (التضييق) — فلا رفعٌ/تنزيلٌ/تتبّعٌ بلا جلسةٍ مُصادَقة.
     */
    public function test_phase_f_routes_carry_session_and_context_middleware(): void
    {
        $uris = [
            ['/api/mobile/v1/files/upload-session',                 'POST'],
            ['/api/mobile/v1/files/upload-session/sess-1/chunk',    'PUT'],
            ['/api/mobile/v1/files/upload-session/sess-1/complete', 'POST'],
            ['/api/mobile/v1/files/attach',                         'POST'],
            ['/api/mobile/v1/files/att-9/download',                 'GET'],
            ['/api/mobile/v1/files/att-9/stream',                   'GET'],
            ['/api/mobile/v1/identity/resolve/ABC-123',             'GET'],
            ['/api/mobile/v1/tracking/start',                       'POST'],
            ['/api/mobile/v1/tracking/trk-1/points',                'POST'],
            ['/api/mobile/v1/tracking/trk-1/end',                   'POST'],
        ];

        foreach ($uris as [$uri, $method]) {
            $mw = $this->resolveMethod($uri, $method)->gatherMiddleware();
            $this->assertContains('mobile.session', $mw, "«{$method} {$uri}» يجب أن تكون خلف mobile.session");
            $this->assertContains('mobile.context', $mw, "«{$method} {$uri}» يجب أن تحمل mobile.context (SF-4)");
        }
    }

    /* ══════════════════ الطور G — المزامنة/الصمود (Critic F9) ══════════════════ */

    /**
     * `GET sync/{module}` (مقطعان، الأوّلُ حرفيٌّ `sync`) يحلّ إلى `MobileSyncController@sync`
     * — **لا يبتلعه** الـcatch-all `GET {module}/{id}` (مقطعان كلاهما وسيط) المُسجَّلُ
     * بعده. لو سبَقَ الـcatch-all لحلَّ `GET sync/tickets` إلى `showRecord('sync','tickets')`
     * فيسقط هذا فوراً بدل أن يمرّ التسريبُ صامتاً (نظيرُ انضباط F9 في الأطوار D/E/F).
     */
    public function test_phase_g_sync_route_is_not_swallowed_by_the_module_catch_all(): void
    {
        $route = $this->resolveMethod('/api/mobile/v1/sync/tickets', 'GET');
        $this->assertSame('mobile.sync', $route->getName(),
            "«GET sync/tickets» حُلّ إلى «{$route->getName()}» لا «mobile.sync» — هل ابتلعه catch-all؟ (F9)");
        $this->assertSame('App\\Http\\Controllers\\Api\\MobileSyncController@sync', $route->getActionName(),
            '«GET sync/tickets» يجب أن يحلَّ إلى معالجه الحرفيّ الخاصّ (F9)');

        // برهانٌ صريحٌ: لا يحلّ إلى الـcatch-all `{module}/{id}` (showRecord)
        $this->assertNotSame('App\\Http\\Controllers\\Api\\MobileResourceController@showRecord',
            $route->getActionName(), '«GET sync/tickets» ابتلعه {module}/{id} — الحرفيُّ `sync` يجب أن يسبق (F9)');
    }

    /**
     * العقدُ الأمنيّ: مسارُ المزامنة خلف `mobile.session` (الهويّة) + `mobile.context`
     * (التضييق) — فلا مزامنةَ بلا جلسةٍ مُصادَقة، والتضييقُ فعّالٌ فوق `hub_scope`.
     */
    public function test_phase_g_sync_route_carries_session_and_context_middleware(): void
    {
        $mw = $this->resolveMethod('/api/mobile/v1/sync/tickets', 'GET')->gatherMiddleware();
        $this->assertContains('mobile.session', $mw, '«GET sync/{module}» يجب أن تكون خلف mobile.session');
        $this->assertContains('mobile.context', $mw, '«GET sync/{module}» يجب أن تحمل mobile.context (SF-4)');
    }
}
