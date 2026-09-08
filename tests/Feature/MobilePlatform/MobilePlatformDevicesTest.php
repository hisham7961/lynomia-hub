<?php

namespace Tests\Feature\MobilePlatform;

use App\Models\MobileInstallation;
use App\Models\MobileSession;
use App\Models\Role;
use App\Models\User;
use App\Support\MobileSessionService;
use Tests\TestCase;

/**
 * **مركزُ منصّة الجوال — الجلسات والأجهزة (MPC-2)**: قوائمُ آمنةٌ مُصفَّحة، تفتيشٌ،
 * إبطالٌ عبر السكّة القائمة (تدقيقٌ، لا حذفَ صفّ)، وجهاز 360. لا تجزئةَ رمزٍ تُعرَض.
 */
class MobilePlatformDevicesTest extends TestCase
{
    private function mobileAdmin(): User
    {
        $role = Role::create(['name' => 'مسؤولُ جوال', 'scope' => 'all', 'flags' => ['mobile' => 1], 'matrix' => []]);

        return User::create(['name' => 'مسؤول', 'email' => 'mob2@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /** يزرع تنصيباً وجلسةً حقيقيّة (عبر السكّة) — يعيد [install, session, accessPlain] */
    private function seedDevice(User $user): array
    {
        $inst = MobileInstallation::create([
            'user_id' => $user->id, 'installation_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'platform' => 'ios', 'device_model' => 'iPhone 15', 'os_version' => '17.4',
            'app_version' => '1.0.0', 'app_build' => '100', 'locale' => 'ar', 'tz' => 'Asia/Kuwait',
            'push_capable' => true, 'registered_at' => now(), 'last_seen_at' => now(),
        ]);
        [$session, $access] = MobileSessionService::mint($user, $inst, '10.0.0.9', '1.0.0', 'ios');

        return [$inst, $session, $access];
    }

    public function test_devices_tab_lists_sessions_for_authorized_only(): void
    {
        $this->seedCore();
        [, $session] = $this->seedDevice($this->employee);

        $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=devices')->assertOk()
            ->assertSee('الجلسات')
            ->assertSee($this->employee->name);   // صاحبُ الجلسة

        // الموظّفُ العاديُّ يُصَدّ عن المركز كلِّه
        $this->actingAs($this->employee)->get('/admin/mobile-platform?tab=devices')->assertForbidden();
    }

    public function test_session_hashes_are_never_rendered(): void
    {
        $this->seedCore();
        [, $session, $access] = $this->seedDevice($this->employee);

        $html = $this->actingAs($this->owner)
            ->get('/admin/mobile-platform?tab=devices&session=' . $session->id)->assertOk()->getContent();

        $this->assertStringNotContainsString($access, $html, 'رمزُ الوصولِ الخام ظهر');
        $this->assertStringNotContainsString(hash('sha256', $access), $html, 'تجزئةُ رمزِ الوصول ظهرت');
        $this->assertStringNotContainsString($session->refresh_hash, $html, 'تجزئةُ رمزِ التحديث ظهرت');
    }

    public function test_installations_list_and_device_360(): void
    {
        $this->seedCore();
        [$inst] = $this->seedDevice($this->employee);

        $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=devices&view=installs')->assertOk()
            ->assertSee('iPhone 15')->assertSee('جهاز 360');

        $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=devices&install=' . $inst->id)->assertOk()
            ->assertSee('جلساتُ هذا الجهاز')
            ->assertSee('رموزُ الدفع');
    }

    public function test_revoke_uses_service_audits_and_does_not_delete(): void
    {
        $this->seedCore();
        [, $session] = $this->seedDevice($this->employee);

        $this->actingAs($this->owner)
            ->post(route('mobileplatform.session.revoke', $session->id))->assertRedirect();

        $fresh = MobileSession::find($session->id);
        $this->assertNotNull($fresh, 'الصفُّ حُذف — يجب أن يبقى شاهداً');
        $this->assertNotNull($fresh->revoked_at, 'الجلسةُ لم تُبطَل');
        $this->assertDatabaseHas('audits', ['action' => 'إبطالُ جلسةِ جوال']);
    }

    public function test_employee_cannot_revoke(): void
    {
        $this->seedCore();
        [, $session] = $this->seedDevice($this->employee);

        $this->actingAs($this->employee)
            ->post(route('mobileplatform.session.revoke', $session->id))->assertForbidden();
        $this->assertNull(MobileSession::find($session->id)->revoked_at, 'الموظّفُ أبطلَ جلسةً — تجاوزٌ للحرس');
    }

    public function test_mobile_admin_can_view_and_revoke(): void
    {
        $this->seedCore();
        [, $session] = $this->seedDevice($this->employee);
        $admin = $this->mobileAdmin();

        $this->actingAs($admin)->get('/admin/mobile-platform?tab=devices')->assertOk();
        $this->actingAs($admin)->post(route('mobileplatform.session.revoke', $session->id))->assertRedirect();
        $this->assertNotNull(MobileSession::find($session->id)->revoked_at);
    }
}
