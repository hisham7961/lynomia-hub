<?php

namespace Tests\Feature\MobilePlatform;

use App\Models\Role;
use App\Models\User;
use App\Support\MobileOpenApi;
use App\Support\MobilePlatform;
use Tests\TestCase;

/**
 * **مركزُ منصّة الجوال — الـAPI والقدرات (MPC-5)**: عقدُ الـAPI، مُستكشِفُ المسارات
 * (للقراءة، من المسارات الحيّة)، سجلُّ القدرات، والمزامنة/التغطية — كلُّه تفويضٌ
 * لـ`MobileOpenApi` (لا سجلَّ ثانٍ، لا سرّ). الحرسُ في المتحكّم.
 */
class MobilePlatformApiTest extends TestCase
{
    private function mobileAdmin(): User
    {
        $role = Role::create(['name' => 'مسؤولُ جوال', 'scope' => 'all', 'flags' => ['mobile' => 1], 'matrix' => []]);

        return User::create(['name' => 'مسؤول', 'email' => 'mob5@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    public function test_api_tab_shows_contract_explorer_and_registry(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=api')->assertOk()
            ->assertSee('عقدُ الـMobile API')
            ->assertSee('مُستكشِفُ المسارات')
            ->assertSee('سجلُّ القدرات')
            ->assertSee('/api/mobile/v1/openapi.json');   // رابطُ OpenAPI الحيّ
    }

    public function test_route_explorer_lists_real_routes(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=api')->assertOk()
            ->assertSee('mobile.auth.login')          // مسارٌ حقيقيٌّ من السجلّ الحيّ
            ->assertSee('/api/mobile/v1/auth/login');
    }

    public function test_area_filter_narrows_and_bogus_falls_back(): void
    {
        $this->seedCore();
        // مجالٌ حقيقيّ
        $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=api&area=auth')->assertOk()
            ->assertSee('mobile.auth.login');
        // مجالٌ وهميّ ⇒ يسقط إلى الكلّ (لا خطأ)
        $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=api&area=no_such_area')->assertOk()
            ->assertSee('مُستكشِفُ المسارات');
    }

    public function test_auth_filter_shows_public_endpoints(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=api&auth=public')->assertOk()
            ->assertSee('/api/mobile/v1/openapi.json');   // نقطةٌ عامّة
    }

    public function test_employee_cannot_access_api_tab(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/admin/mobile-platform?tab=api')->assertForbidden();
    }

    public function test_mobile_admin_can_view_api_tab(): void
    {
        $this->seedCore();
        $this->actingAs($this->mobileAdmin())->get('/admin/mobile-platform?tab=api')->assertOk()
            ->assertSee('الـAPI والقدرات');
    }

    /** القدراتُ تفويضٌ صرفٌ لـMobileOpenApi — لا سجلَّ ثانٍ يتباعد */
    public function test_capabilities_delegate_to_openapi(): void
    {
        $this->seedCore();
        $caps = MobilePlatform::capabilities();
        $this->assertSame(MobileOpenApi::capabilities(), $caps, 'سجلُّ القدرات في المركز انحرف عن MobileOpenApi');
        foreach (['areas', 'sync', 'auth', 'error_codes', 'concurrency', 'idempotency'] as $k) {
            $this->assertArrayHasKey($k, $caps);
        }
    }

    /** تغطيةُ المزامنة تشمل كلَّ الوحدات (لا وحدةَ بلا تصنيف) */
    public function test_sync_coverage_counts_all_modules(): void
    {
        $this->seedCore();
        $sync = MobilePlatform::sync();
        $this->assertNotEmpty($sync['coverage'] ?? []);
        $this->assertSame(count(hub_modules()), array_sum($sync['coverage']), 'مجموعُ تغطيةِ المزامنة لا يطابق عددَ الوحدات');
    }

    public function test_no_secret_rendered_on_api_tab(): void
    {
        $this->seedCore();
        $this->hubSetting('mobile.push_fcm_access_token', 'SECRET-FCM-API-555');

        $html = $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=api')->assertOk()->getContent();
        $this->assertStringNotContainsString('SECRET-FCM-API-555', $html);
    }
}
