<?php

namespace Tests\Feature\Mobile;

use App\Support\MobileOpenApi;
use App\Support\OpenApi;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * **مواصفةُ الجوال الحيّة + بيانُ القدرات + سقالةُ الروابط العالميّة** — Mobile
 * Readiness · الطور H · H.2/H.3.
 *
 * يُثبِت (إثبات لا ادّعاء · CLAUDE.md):
 *  1. `GET /api/mobile/v1/openapi.json` عامّةٌ وصحيحةٌ (OpenAPI 3.1) بمساراتِ الجوال.
 *  2. المواصفةُ **وثيقةٌ منفصلة** عن `/api/v1` (لا تسرّبُ مساراتِ أحدهما للآخر).
 *  3. **`/api/v1` غيرُ مَمسوس:** مُخرَجُ مولّد v1 مطابقٌ لـ`docs/openapi.json` (عدا
 *     `servers` التي يُطبّعها `hub:openapi`)، والنقطةُ الحيّةُ بلا أيِّ مسارِ جوال.
 *  4. البيانُ (mobile-capabilities.json) **لا ينحرف إلى خيال:** كلُّ نقطةٍ فيه تُحلّ
 *     لمسارٍ مُسجَّلٍ حقيقيّ، وكلُّ مسارِ جوالٍ مُسجَّلٍ مذكورٌ فيه (تطابقٌ ثنائيّ)،
 *     والملفُّ المحفوظُ يطابق مُخرَجَ المولّد.
 *  5. سقالةُ الروابط العالميّة تُخدَم **صادقةً NOT_CONFIGURED** حتى تُضبط المعرّفات.
 */
class MobileOpenApiTest extends TestCase
{
    // ══════════════════════════ H.2 · المواصفة ══════════════════════════

    public function test_mobile_openapi_is_public_and_valid_3_1(): void
    {
        $this->seedCore();

        $spec = $this->getJson('/api/mobile/v1/openapi.json')->assertOk()->json();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertStringContainsString('Mobile', $spec['info']['title']);
        foreach (['/api/mobile/v1/auth/login', '/api/mobile/v1/bootstrap',
                  '/api/mobile/v1/sync/{module}', '/api/mobile/v1/openapi.json'] as $p) {
            $this->assertArrayHasKey($p, $spec['paths'], "المسارُ {$p} موثَّقٌ في مواصفة الجوال");
        }
        // الأكوادُ الأربعةُ الجوّالة حاضرةٌ صراحةً (فوق العقد الموحّد)
        $this->assertSame(
            ['MFA_REQUIRED', 'REFRESH_TOKEN_INVALID', 'SESSION_REVOKED', 'APP_UPDATE_REQUIRED'],
            array_keys($spec['x-mobile-error-codes']));
        // العقدُ الموحّد كاملاً (لا يُطالَب العميلُ بقراءة العربية)
        $this->assertArrayHasKey('UNAUTHENTICATED', $spec['x-error-codes']);
        $this->assertArrayHasKey('VERSION_CONFLICT', $spec['x-error-codes']);
        // عقودٌ عرضيّةٌ موثَّقة
        $this->assertSame('show', $spec['x-deep-link']['canonical']['action']);
        $this->assertArrayHasKey('universal_links', $spec['x-not-configured']);
    }

    public function test_public_ops_have_empty_security_and_authed_carry_bearer(): void
    {
        $this->seedCore();
        $spec = $this->getJson('/api/mobile/v1/openapi.json')->assertOk()->json();

        // عامّة: أمنٌ فارغٌ صراحةً (يُلغي الافتراضَ)
        foreach ([['/api/mobile/v1/auth/login', 'post'], ['/api/mobile/v1/app-config', 'get'],
                  ['/api/mobile/v1/health', 'get'], ['/api/mobile/v1/openapi.json', 'get']] as [$p, $m]) {
            $this->assertSame([], $spec['paths'][$p][$m]['security'], "{$m} {$p} عامّة");
        }
        // مُصادَقة: رمزُ الوصول
        foreach ([['/api/mobile/v1/bootstrap', 'get'], ['/api/mobile/v1/context', 'get'],
                  ['/api/mobile/v1/sync/{module}', 'get']] as [$p, $m]) {
            $this->assertSame([['mobileBearerAuth' => []]], $spec['paths'][$p][$m]['security'], "{$m} {$p} مُصادَقة");
        }
    }

    public function test_mobile_spec_is_a_distinct_document_from_v1(): void
    {
        $mobile = MobileOpenApi::spec();
        $v1 = OpenApi::spec();

        // لا تسرّبَ مساراتٍ من v1 إلى مواصفة الجوال
        foreach (array_keys($mobile['paths']) as $p) {
            $this->assertStringStartsNotWith('/api/v1', $p, "مسارُ v1 تسرّب لمواصفة الجوال: {$p}");
        }
        // ولا العكس
        foreach (array_keys($v1['paths']) as $p) {
            $this->assertStringStartsNotWith('/api/mobile', $p, "مسارُ جوالٍ تسرّب لمواصفة v1: {$p}");
        }
        // عنوانان مختلفان (وثيقتان مستقلّتان)
        $this->assertNotSame($mobile['info']['title'], $v1['info']['title']);
    }

    // ══════════════════════════ /api/v1 غيرُ مَمسوس ══════════════════════════

    public function test_v1_generator_output_is_unchanged_except_normalized_servers(): void
    {
        $live = OpenApi::spec();
        $committed = json_decode((string) file_get_contents(base_path('docs/openapi.json')), true);

        // `hub:openapi` يُطبّع servers إلى «/» عند التصدير — الفرقُ الوحيدُ المتوقَّع،
        // ولا شأنَ له بهذا الطور. نُسقطه من الطرفين ونطالب بتطابقٍ تامّ لكلِّ ما عداه.
        unset($live['servers'], $committed['servers']);
        $this->assertEquals($committed, $live,
            'مُخرَجُ مولّد /api/v1 يجب أن يبقى مطابقاً لـdocs/openapi.json (عدا servers)');
    }

    public function test_v1_live_endpoint_has_no_mobile_paths(): void
    {
        $this->seedCore();
        $h = ['Authorization' => 'Bearer ' . $this->apiToken($this->owner)];

        $spec = $this->withHeaders($h)->getJson('/api/v1/openapi.json')->assertOk()->json();
        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertArrayHasKey('/api/v1/me', $spec['paths']);
        foreach (array_keys($spec['paths']) as $p) {
            $this->assertStringStartsNotWith('/api/mobile', $p, "مسارُ جوالٍ تسرّب لنقطة v1 الحيّة: {$p}");
        }
    }

    // ══════════════════════════ بيانُ القدرات لا ينحرف ══════════════════════════

    public function test_capabilities_manifest_matches_generator(): void
    {
        $this->seedCore();
        $file = json_decode((string) file_get_contents(base_path('docs/mobile-readiness/mobile-capabilities.json')), true);
        $this->assertIsArray($file);
        $this->assertEquals(MobileOpenApi::capabilities(), $file,
            'الملفُّ المحفوظُ يجب أن يطابق مُخرَجَ المولّد (أعد التوليدَ عند تغيّر المسارات)');
    }

    public function test_capabilities_endpoints_all_map_to_registered_routes(): void
    {
        // كلُّ مسارِ جوالٍ مُسجَّلٍ حقيقيّ (method + path)
        $registered = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), MobileOpenApi::PREFIX)) continue;
            foreach ($route->methods() as $verb) {
                if (in_array($verb, ['HEAD', 'OPTIONS'], true)) continue;
                $registered[$verb . ' /' . $route->uri()] = true;
            }
        }

        $caps = MobileOpenApi::capabilities();
        $listed = [];
        foreach ($caps['areas'] as $area) {
            foreach ($area['endpoints'] as $e) {
                $key = $e['method'] . ' ' . $e['path'];
                $listed[$key] = true;
                $this->assertArrayHasKey($key, $registered, "نقطةٌ في البيان بلا مسارٍ مُسجَّل (خيال): {$key}");
            }
        }
        // وكلُّ مسارٍ مُسجَّلٍ مذكورٌ في البيان (لا نقطةَ مخفيّة)
        foreach (array_keys($registered) as $key) {
            $this->assertArrayHasKey($key, $listed, "مسارٌ مُسجَّلٌ غائبٌ عن البيان: {$key}");
        }
    }

    public function test_capabilities_sync_coverage_matches_config(): void
    {
        $caps = MobileOpenApi::capabilities();
        $sum = array_sum($caps['sync']['coverage']);
        $this->assertSame(count(config('hub.modules')), $sum, 'تغطيةُ المزامنة تشمل كلَّ وحدةٍ مرّةً واحدة');
        foreach ($caps['sync']['modules'] as $module => $cls) {
            $this->assertSame(hub_sync_class($module), $cls, "تصنيفُ {$module} يطابق hub_sync_class");
        }
    }

    // ══════════════════════════ H.3 · الروابط العالميّة (صادقةٌ NOT_CONFIGURED) ══════════════════════════

    public function test_aasa_served_honestly_not_configured_by_default(): void
    {
        $this->seedCore();

        $res = $this->getJson('/.well-known/apple-app-site-association')->assertOk();
        $res->assertHeader('X-Deep-Links-Status', 'NOT_CONFIGURED');
        // details فارغةٌ ⇒ لا تطبيقَ مربوط (لا رابطَ عالميٌّ يعمل — لا اختلاق)
        $this->assertSame([], $res->json('applinks.details'));
        $this->assertSame('NOT_CONFIGURED', $res->json('x-lynomia.status'));
    }

    public function test_assetlinks_served_honestly_not_configured_by_default(): void
    {
        $this->seedCore();

        $res = $this->getJson('/.well-known/assetlinks.json')->assertOk();
        $res->assertHeader('X-Deep-Links-Status', 'NOT_CONFIGURED');
        $this->assertSame([], $res->json());   // مصفوفةٌ فارغة = لا تطبيقَ مربوط
    }

    public function test_aasa_serves_real_association_when_configured(): void
    {
        $this->seedCore();
        config(['hub.mobile.deep_links.apple.team_id' => 'ABCDE12345',
                'hub.mobile.deep_links.apple.bundle_id' => 'com.lynomia.hub']);

        $res = $this->getJson('/.well-known/apple-app-site-association')->assertOk();
        $res->assertHeader('X-Deep-Links-Status', 'CONFIGURED');
        $this->assertSame(['ABCDE12345.com.lynomia.hub'], $res->json('applinks.details.0.appIDs'));
        // نمطُ المسار `/m/*` = ترميزُ الرابطِ العميق القانونيّ
        $paths = array_column($res->json('applinks.details.0.components'), '/');
        $this->assertContains('/m/*', $paths);
    }

    public function test_assetlinks_serves_real_statement_when_configured(): void
    {
        $this->seedCore();
        config(['hub.mobile.deep_links.android.package_name' => 'com.lynomia.hub',
                'hub.mobile.deep_links.android.sha256_cert_fingerprints' => ['AA:BB:CC']]);

        $res = $this->getJson('/.well-known/assetlinks.json')->assertOk();
        $res->assertHeader('X-Deep-Links-Status', 'CONFIGURED');
        $this->assertSame('com.lynomia.hub', $res->json('0.target.package_name'));
        $this->assertSame(['AA:BB:CC'], $res->json('0.target.sha256_cert_fingerprints'));
        $this->assertContains('delegate_permission/common.handle_all_urls', $res->json('0.relation'));
    }
}
