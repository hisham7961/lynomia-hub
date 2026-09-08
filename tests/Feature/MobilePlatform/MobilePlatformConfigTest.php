<?php

namespace Tests\Feature\MobilePlatform;

use App\Models\HubNotification;
use App\Models\Role;
use App\Models\User;
use App\Support\MobilePlatform;
use App\Support\NotificationLink;
use Tests\TestCase;

/**
 * **مركزُ منصّة الجوال — التطبيق والإطلاق (MPC-4)**: إعداداتُ الإصدار، معاينةُ
 * app-config **الحيّة** (نفسُ النقطة لا نسخةٌ ثانية) وفعّاليّةُ بوّابةِ التحديث،
 * الروابطُ العميقة ووثائقُها العالميّة (NOT_CONFIGURED صدقاً)، ومُختبِرٌ دلاليٌّ
 * يتحقّق من سجلِّ الوحدات الحقيقيّ ويطابق `NotificationLink` (لا خريطةَ ثانية).
 */
class MobilePlatformConfigTest extends TestCase
{
    private function mobileAdmin(): User
    {
        $role = Role::create(['name' => 'مسؤولُ جوال', 'scope' => 'all', 'flags' => ['mobile' => 1], 'matrix' => []]);

        return User::create(['name' => 'مسؤول', 'email' => 'mob4@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    public function test_config_tab_shows_versions_deeplinks_and_checklist(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=config')->assertOk()
            ->assertSee('إصداراتُ التطبيق')
            ->assertSee('معاينةُ app-config')
            ->assertSee('قائمةُ فحصِ الإطلاق')
            ->assertSee('غير مُهيّأ');   // الروابط/الإصدارات غيرُ مضبوطةٍ افتراضاً — صدق
    }

    public function test_employee_cannot_access_config_tab(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/admin/mobile-platform?tab=config')->assertForbidden();
    }

    public function test_mobile_admin_can_view_config_tab(): void
    {
        $this->seedCore();
        $this->actingAs($this->mobileAdmin())->get('/admin/mobile-platform?tab=config')->assertOk()
            ->assertSee('التطبيق والإطلاق');
    }

    /** المعاينةُ من النقطةِ الحيّة: حدٌّ أدنى مضبوطٌ + إصدارُ عميلٍ دونه ⇒ تحديثٌ مطلوب */
    public function test_version_gate_effectiveness_via_live_endpoint(): void
    {
        $this->seedCore();
        $this->hubSetting('mobile.min_version_ios', '2.0.0');

        // إصدارٌ دون الحدّ ⇒ يُحجَب
        $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=config&cv_ios=1.0.0')->assertOk()
            ->assertSee('تحجبه البوّابة');

        // إصدارٌ فوق الحدّ ⇒ يمرّ (لا يُحجَب)
        $blocked = $this->actingAs($this->owner)
            ->get('/admin/mobile-platform?tab=config&cv_ios=3.0.0')->assertOk()->getContent();
        $this->assertStringNotContainsString('تحجبه البوّابة', $blocked, 'إصدارٌ فوق الحدِّ حُجب خطأً');
    }

    /** المعاينةُ تعكس النقطةَ الحيّة نفسَها (نسخةُ العقد حاضرة) */
    public function test_app_config_preview_uses_the_live_endpoint(): void
    {
        $this->seedCore();
        $ios = MobilePlatform::appConfigPreview('ios', '1.0.0');
        $this->assertArrayHasKey('mobile_api_version', $ios);
        $this->assertArrayHasKey('version_gate', $ios);
        $this->assertArrayHasKey('update_required', $ios);
    }

    /** مُختبِرُ الرابط: وحدةٌ غير مسجّلةٍ ⇒ صدقُ «غير مسجّلة» (لا اختلاق) */
    public function test_deep_link_tester_rejects_unregistered_module(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)
            ->get('/admin/mobile-platform?tab=config&dl_module=no_such_module_xyz&dl_id=1')->assertOk()
            ->assertSee('غير مسجّلة');
    }

    /** مُختبِرُ الرابط: وحدةٌ مسجّلةٌ ⇒ وجهةٌ قانونيّةٌ + رابطٌ عالميّ */
    public function test_deep_link_tester_resolves_registered_module(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)
            ->get('/admin/mobile-platform?tab=config&dl_module=companies&dl_id=abc123')->assertOk()
            ->assertSee('الوجهةُ القانونيّة')
            ->assertSee('/m/companies/abc123');
    }

    /** الرابطُ العالميُّ **يطابق** ما يُنتجه NotificationLink (لا خريطةَ ثانية تتباعد) */
    public function test_deep_link_url_matches_notification_link(): void
    {
        $this->seedCore();
        $n = new HubNotification(['module' => 'companies', 'record_id' => 'abc123']);

        $this->assertSame(
            NotificationLink::webUrl($n),
            MobilePlatform::deepLinkResolve('companies', 'abc123')['url'],
            'الرابطُ العميقُ في المركز انحرف عن NotificationLink'
        );
    }

    /** وثائقُ الربط العالميّة: NOT_CONFIGURED صدقاً افتراضاً */
    public function test_wellknown_is_not_configured_by_default(): void
    {
        $this->seedCore();
        $wk = MobilePlatform::wellKnown();
        $this->assertFalse($wk['aasa']['configured']);
        $this->assertFalse($wk['assetlinks']['configured']);
        $this->assertSame('NOT_CONFIGURED', $wk['aasa']['status']);
    }

    /** تُضبَط معرّفاتُ Apple ⇒ الوثيقةُ الحيّة CONFIGURED (من نفس المتحكّم) */
    public function test_wellknown_apple_configured_when_ids_present(): void
    {
        $this->seedCore();
        $this->hubSetting('mobile.dl_apple_team_id', 'ABCDE12345');
        $this->hubSetting('mobile.dl_apple_bundle_id', 'com.lynomia.app');

        $wk = MobilePlatform::wellKnown();
        $this->assertTrue($wk['aasa']['configured'], 'AASA لم تُعلَن CONFIGURED رغم ضبط المعرّفات');
        $this->assertSame('CONFIGURED', $wk['aasa']['status']);
    }

    /** قائمةُ الفحص تنقلب READY حين تُضبط المعرّفات */
    public function test_launch_checklist_flips_ready_when_configured(): void
    {
        $this->seedCore();
        $this->hubSetting('mobile.dl_apple_team_id', 'ABCDE12345');
        $this->hubSetting('mobile.dl_apple_bundle_id', 'com.lynomia.app');

        $items = collect(MobilePlatform::launchChecklist());
        $apple = $items->firstWhere('anchor', 'mobile.dl_apple_team_id');
        $this->assertSame(MobilePlatform::READY, $apple['state']);
        // بندٌ آخرُ غيرُ مضبوطٍ يبقى صادقاً NOT_CONFIGURED
        $android = $items->firstWhere('anchor', 'mobile.dl_android_fingerprints');
        $this->assertSame(MobilePlatform::NOT_CONFIGURED, $android['state']);
    }

    /** لا سرَّ يُعرَض في تبويب التهيئة (دفاعيّاً) */
    public function test_no_secret_rendered_on_config_tab(): void
    {
        $this->seedCore();
        $this->hubSetting('mobile.push_fcm_access_token', 'SECRET-FCM-CFG-777');

        $html = $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=config')->assertOk()->getContent();
        $this->assertStringNotContainsString('SECRET-FCM-CFG-777', $html);
    }
}
