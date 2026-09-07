<?php

namespace Tests\Feature\Mobile;

use Tests\TestCase;

/**
 * **C.3 · GET app-config + health** (عامّ · ما قبل الدخول) — Mobile Readiness · الطور C.
 *
 * نقطةٌ عامّةٌ صادقةٌ يقرؤها التطبيقُ قبل الدخول: الوقتُ، إصدارُ عقد الجوال، حالةُ
 * الصيانة/القفل وتوفّرُ الدخول، وبوّابةُ الإصدار. تُبرهن: تُبلَغ بلا رمز، صادقةٌ في
 * الصيانة/القفل، **بوّابةٌ فارغةٌ لا تحجب نسخَ التطوير**، لا أسرار، والقيمةُ الخارجيّةُ
 * الغائبةُ `null` (NOT_CONFIGURED) لا مُختلَقة (spec §C.3 · §Version gate).
 */
class MobileAppConfigTest extends TestCase
{
    use InteractsWithMobileAuth;
    use AssertsMobilePayload;

    public function test_app_config_is_reachable_without_any_token(): void
    {
        $this->seedCore();

        $res = $this->getJson('/api/mobile/v1/app-config');
        $res->assertOk();
        $this->assertSame('1', $res->json('data.mobile_api_version'));
        $this->assertNotEmpty($res->json('data.server_time'));
        $this->assertNotEmpty($res->json('data.timezone'));
    }

    public function test_app_config_reports_login_available_by_default(): void
    {
        $this->seedCore();

        $res = $this->getJson('/api/mobile/v1/app-config');
        $res->assertOk()
            ->assertJsonPath('data.maintenance', false)
            ->assertJsonPath('data.lockdown', false)
            ->assertJsonPath('data.login_available', true);
        $this->assertNull($res->json('data.maintenance_message'), 'لا رسالةَ صيانةٍ حين لا صيانة');
    }

    public function test_maintenance_makes_login_unavailable_and_surfaces_its_message(): void
    {
        $this->seedCore();
        $this->hubSetting('maintenance.on', '1');
        $this->hubSetting('maintenance.msg', 'نُحدّث النظام — عُد قريباً');

        $res = $this->getJson('/api/mobile/v1/app-config');
        $res->assertOk()
            ->assertJsonPath('data.maintenance', true)
            ->assertJsonPath('data.login_available', false)
            ->assertJsonPath('data.maintenance_message', 'نُحدّث النظام — عُد قريباً');
    }

    public function test_lockdown_makes_login_unavailable(): void
    {
        $this->seedCore();
        $this->hubSetting('security.lockdown', '1');

        $this->getJson('/api/mobile/v1/app-config')->assertOk()
            ->assertJsonPath('data.lockdown', true)
            ->assertJsonPath('data.login_available', false);
    }

    public function test_blank_version_gate_never_blocks_dev_builds_and_sets_no_force_update(): void
    {
        $this->seedCore();

        // بوّابةٌ فارغةٌ افتراضاً: min/latest = null، force_update = false
        $res = $this->getJson('/api/mobile/v1/app-config');
        $res->assertOk()
            ->assertJsonPath('data.version_gate.ios.min', null)
            ->assertJsonPath('data.version_gate.android.min', null)
            ->assertJsonPath('data.version_gate.force_update', false);

        // حتى مع عميلٍ قديمٍ صريحٍ (منصّة + إصدار) — بوّابةٌ فارغةٌ ⇒ لا حجب
        $withOldClient = $this->withHeaders([
            'X-Lynomia-App-Platform' => 'ios',
            'X-Lynomia-App-Version'  => '0.0.1',
        ])->getJson('/api/mobile/v1/app-config');
        $withOldClient->assertOk()->assertJsonPath('data.update_required', false);
    }

    public function test_configured_min_version_blocks_older_client_but_not_newer(): void
    {
        $this->seedCore();
        $this->hubSetting('mobile.min_version_ios', '2.0.0');

        // البوّابةُ صارت غيرَ فارغة — تظهر في الحمولة
        $this->getJson('/api/mobile/v1/app-config')->assertOk()
            ->assertJsonPath('data.version_gate.ios.min', '2.0.0');

        // عميلٌ أقدمُ من الحدّ ⇒ update_required = true
        $this->withHeaders(['X-Lynomia-App-Platform' => 'ios', 'X-Lynomia-App-Version' => '1.5.0'])
            ->getJson('/api/mobile/v1/app-config')->assertOk()
            ->assertJsonPath('data.update_required', true);

        // عميلٌ عند الحدّ أو أحدثُ ⇒ لا حجب
        $this->withHeaders(['X-Lynomia-App-Platform' => 'ios', 'X-Lynomia-App-Version' => '2.4.0'])
            ->getJson('/api/mobile/v1/app-config')->assertOk()
            ->assertJsonPath('data.update_required', false);

        // ومنصّةٌ أخرى (أندرويد) بلا حدٍّ مضبوطٍ لا تُحجَب
        $this->withHeaders(['X-Lynomia-App-Platform' => 'android', 'X-Lynomia-App-Version' => '0.1.0'])
            ->getJson('/api/mobile/v1/app-config')->assertOk()
            ->assertJsonPath('data.update_required', false);
    }

    public function test_app_config_contains_no_secret_shaped_keys(): void
    {
        $this->seedCore();
        // حتى مع إعداداتٍ حسّاسةٍ في القاعدة — لا تظهر في الحمولة العامّة
        $this->hubSetting('mobile.support_url', 'https://help.lynomia.test');

        $res = $this->getJson('/api/mobile/v1/app-config');
        $res->assertOk();
        $this->assertNoKeysDeep($this->forbiddenSecretKeys(), $res->json('data'),
            'إعداداتُ ما قبل الدخول بلا أسرار');
    }

    public function test_absent_external_values_are_null_not_fabricated(): void
    {
        $this->seedCore();

        // بلا ضبطٍ: رابطُ الدعم وروابطُ المتجر null صادقةٌ (NOT_CONFIGURED) لا نصٌّ مُختلَق
        $res = $this->getJson('/api/mobile/v1/app-config');
        $res->assertOk()
            ->assertJsonPath('data.support_url', null)
            ->assertJsonPath('data.store_urls.ios', null)
            ->assertJsonPath('data.store_urls.android', null);

        // وبعد الضبط تظهر القيمةُ الحقيقيّة
        $this->hubSetting('mobile.support_url', 'https://help.lynomia.test');
        $this->hubSetting('mobile.store_url_ios', 'https://apps.apple.com/app/id0');
        $this->getJson('/api/mobile/v1/app-config')->assertOk()
            ->assertJsonPath('data.support_url', 'https://help.lynomia.test')
            ->assertJsonPath('data.store_urls.ios', 'https://apps.apple.com/app/id0')
            ->assertJsonPath('data.store_urls.android', null);
    }

    // ════════════════════════════ health (C.3) ════════════════════════════

    public function test_health_status_priority_lockdown_over_maintenance_over_update_over_ok(): void
    {
        $this->seedCore();

        // سليمٌ افتراضاً
        $this->getJson('/api/mobile/v1/health')->assertOk()->assertJsonPath('data.status', 'ok');

        // صيانةٌ ⇒ maintenance
        $this->hubSetting('maintenance.on', '1');
        $this->getJson('/api/mobile/v1/health')->assertOk()->assertJsonPath('data.status', 'maintenance');

        // قفلٌ يغلب الصيانة ⇒ lockdown
        $this->hubSetting('security.lockdown', '1');
        $this->getJson('/api/mobile/v1/health')->assertOk()->assertJsonPath('data.status', 'lockdown');
    }

    public function test_health_is_reachable_without_token(): void
    {
        $this->seedCore();
        $this->getJson('/api/mobile/v1/health')->assertOk()
            ->assertJsonPath('data.maintenance', false)
            ->assertJsonPath('data.lockdown', false);
    }
}
