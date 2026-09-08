<?php

namespace Tests\Feature\MobilePlatform;

use App\Models\Role;
use App\Models\User;
use App\Support\InformationArchitecture;
use Tests\TestCase;

/**
 * **مركزُ منصّة الجوال — الأساس (MPC-1)**: الوصولُ محروسٌ في المتحكّم (مالك/رايةُ
 * mobile)، النظرةُ العامّة وبطاقةُ الجاهزية والصحّة حقيقيّة، والمركزُ مُدمَجٌ في IA
 * (كتالوج الإدارة + خريطة النظام). لا أسرارَ تُعرَض.
 */
class MobilePlatformCenterTest extends TestCase
{
    private function mobileAdmin(): User
    {
        $role = Role::create(['name' => 'مسؤولُ جوال', 'scope' => 'all', 'flags' => ['mobile' => 1], 'matrix' => []]);

        return User::create(['name' => 'مسؤول', 'email' => 'mob@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    public function test_ordinary_employee_is_denied_at_the_controller(): void
    {
        $this->seedCore();
        // الموظّفُ العاديُّ لا رايةَ mobile ولا مالك — ٤٠٣ بالمتحكّم لا بإخفاء التنقّل
        $this->actingAs($this->employee)->get('/admin/mobile-platform')->assertForbidden();
    }

    public function test_owner_sees_the_center_with_tabs_and_scorecard(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/admin/mobile-platform')->assertOk()
            ->assertSee('منصّة تطبيق الهاتف')
            ->assertSee('نظرة عامّة')
            ->assertSee('بطاقةُ جاهزيّةِ المنصّة')
            ->assertSee('حالةُ التهيئة');
    }

    public function test_mobile_flagged_user_can_access(): void
    {
        $this->seedCore();
        $this->actingAs($this->mobileAdmin())->get('/admin/mobile-platform')->assertOk()
            ->assertSee('منصّة تطبيق الهاتف');
    }

    public function test_operations_tab_shows_real_health(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=operations')->assertOk()
            ->assertSee('مكوّناتٌ جوهريّة')
            ->assertSee('مسارات الجوال');   // مكوّنٌ حقيقيّ
    }

    /** الدفعُ يقول NOT_CONFIGURED صدقاً افتراضاً (لا نجاحٌ مزيّف) */
    public function test_push_shows_not_configured_by_default(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/admin/mobile-platform')->assertOk()
            ->assertSee('غير مُهيّأ');
    }

    /** لا يُعرَض سرُّ FCM ولو ضُبط — حضورٌ لا قيمة */
    public function test_fcm_secret_value_is_never_rendered(): void
    {
        $this->seedCore();
        $this->hubSetting('mobile.push_fcm_access_token', 'SUPER-SECRET-FCM-XYZ');

        $html = $this->actingAs($this->owner)->get('/admin/mobile-platform')->assertOk()->getContent();
        $this->assertStringNotContainsString('SUPER-SECRET-FCM-XYZ', $html, 'سرُّ FCM ظهر في الصفحة');
    }

    /* ═══════════ اندماجُ IA ═══════════ */

    public function test_is_homed_in_ia_administration_and_resolves(): void
    {
        $this->seedCore();
        $ia = new InformationArchitecture();
        $loc = $ia->routeLocation('mobileplatform.index');
        $this->assertSame('domain', $loc['scope'] ?? null);
        $this->assertSame('administration', $loc['domain'] ?? null);
    }

    public function test_appears_in_admin_catalog_for_authorized_only(): void
    {
        $this->seedCore();

        $ownerKeys = array_column(hub_admin_links($this->owner), 'key');
        $this->assertContains('mobileplatform', $ownerKeys);
        $ownerOk = collect(hub_admin_links($this->owner))->firstWhere('key', 'mobileplatform')['ok'];
        $this->assertTrue($ownerOk, 'المالكُ لا يرى مدخلَ منصّة الجوال');

        $empOk = collect(hub_admin_links($this->employee))->firstWhere('key', 'mobileplatform')['ok'];
        $this->assertFalse($empOk, 'الموظّفُ العاديُّ يرى مدخلَ منصّة الجوال');
    }

    public function test_system_map_shows_center_to_owner_not_to_employee(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->get('/system-map')->assertOk()->assertSee('منصّة تطبيق الهاتف');
        $empHtml = $this->actingAs($this->employee)->get('/system-map')->assertOk()->getContent();
        $this->assertStringNotContainsString('منصّة تطبيق الهاتف', $empHtml, 'المركزُ ظهر لموظّفٍ في خريطة النظام');
    }
}
