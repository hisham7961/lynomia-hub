<?php

namespace Tests\Feature\MobilePlatform;

use App\Models\MobileInstallation;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Mobile\MobilePlatform;
use App\Support\Mobile\MobileSessionService;
use App\Support\Mobile\MobileSettings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **محرّرُ إعدادات الجوال في مركز المنصّة** (خطّةُ التطبيق · 2.1) + **تعريفٌ واحدٌ لـ«نشطة»** (2.4).
 *
 * يُثبت: الحرسُ حرسُ المركز (مالكٌ أو رايةُ mobile، والموظّفُ ٤٠٣)؛ التحقّقُ قبل الكتابة
 * (semver، الأدنى ≤ الأحدث، https للمتجر، صيغُ الروابط العميقة) بلا حفظٍ جزئيّ؛ قيدُ تدقيقٍ
 * لكلِّ تغيير؛ السرُّ للكتابة فقط (مشفَّرٌ، لا يُعرَض ولا يُعاد، والفارغُ يُبقيه)؛ روابطُ «اضبط ←»
 * تفتح المحرّر؛ وجدولُ الإعدادات يقول أين تُحرَّر؛ وعدّادُ «نشطة» يطابق فلتره.
 */
class MobilePlatformSettingsTest extends TestCase
{
    private function mobileAdmin(): User
    {
        $role = Role::create(['name' => 'مسؤولُ جوال', 'scope' => 'all', 'flags' => ['mobile' => 1], 'matrix' => []]);

        return User::create(['name' => 'مسؤول', 'email' => 'mobset@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function raw(string $key): ?string
    {
        return Setting::where('key', $key)->value('value');
    }

    public function test_release_settings_are_validated_saved_and_audited_per_change(): void
    {
        $this->seedCore();
        $admin = $this->mobileAdmin();

        $this->actingAs($admin)->post('/admin/mobile-platform/settings/release', [
            'min_version_ios' => '1.2.0', 'latest_version_ios' => '1.4.0',
            'store_url_ios' => 'https://apps.apple.com/app/id123', 'support_url' => 'mailto:help@example.com',
            'force_update' => '1',
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('1.2.0', setting('mobile.min_version_ios'));
        $this->assertSame('1.4.0', setting('mobile.latest_version_ios'));
        $this->assertSame('1', (string) setting('mobile.force_update'));
        $this->assertSame('mailto:help@example.com', setting('mobile.support_url'));
        $this->assertSame(5, DB::table('audits')->where('action', MobileSettings::AUDIT_ACTION)->count(), 'قيدٌ لكلِّ تغيير');
        $this->assertSame(5, DB::table('setting_changes')->where('source', 'mobile')->count());

        // app-config يقرأ القيمَ الجديدة (الحلقةُ مغلقةٌ مع التطبيق)
        $cfg = $this->getJson('/api/mobile/v1/app-config')->assertOk();
        $this->assertSame('https://apps.apple.com/app/id123', $cfg->json('data.store_urls.ios') ?? $cfg->json('store_urls.ios'));

        // حفظُ النموذجِ نفسِه بلا تغيير لا يكتب ولا يُدقّق
        $this->actingAs($admin)->post('/admin/mobile-platform/settings/release', [
            'min_version_ios' => '1.2.0', 'latest_version_ios' => '1.4.0',
            'store_url_ios' => 'https://apps.apple.com/app/id123', 'support_url' => 'mailto:help@example.com',
            'force_update' => '1',
        ])->assertSessionHas('ok', 'لا تغييرَ لحفظه');
        $this->assertSame(5, DB::table('audits')->where('action', MobileSettings::AUDIT_ACTION)->count());
    }

    public function test_invalid_input_saves_nothing(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        foreach ([
            ['min_version_ios' => '2.0.0', 'latest_version_ios' => '1.9.9'],   // الأدنى > الأحدث
            ['min_version_android' => 'v1'],                                   // ليست semver
            ['store_url_ios' => 'http://apps.apple.com/x'],                    // ليست https
            ['store_url_android' => 'javascript:alert(1)'],
            ['support_url' => 'ftp://x.example'],
            ['latest_version_ios' => '1.0.0', 'store_url_ios' => 'nope'],      // خطأٌ واحدٌ يمنع القسمَ كلَّه
        ] as $bad) {
            $this->post('/admin/mobile-platform/settings/release', $bad)
                ->assertRedirect()->assertSessionHasErrors([], null, 'mobileSettings');
        }
        foreach ([
            ['dl_apple_team_id' => 'abc'],
            ['dl_apple_bundle_id' => 'no spaces allowed'],
            ['dl_android_package' => '1com.bad'],
            ['dl_android_fingerprints' => 'AA:BB'],
        ] as $bad) {
            $this->post('/admin/mobile-platform/settings/deeplinks', $bad)->assertSessionHasErrors([], null, 'mobileSettings');
        }
        $this->post('/admin/mobile-platform/settings/push', ['push_driver' => 'apns'])->assertSessionHasErrors([], null, 'mobileSettings');
        $this->post('/admin/mobile-platform/settings/push', ['push_fcm_project_id' => 'X!'])->assertSessionHasErrors([], null, 'mobileSettings');

        $this->assertSame(0, Setting::where('key', 'like', 'mobile.%')->count(), 'لا كتابةَ من أيِّ مدخلٍ باطل');
        $this->assertSame(0, DB::table('audits')->where('action', MobileSettings::AUDIT_ACTION)->count());
    }

    public function test_deep_link_fingerprints_are_normalized(): void
    {
        $this->seedCore();
        $fp = implode(':', array_fill(0, 32, 'ab'));
        $fp2 = implode(':', array_fill(0, 32, 'CD'));

        $this->actingAs($this->owner)->post('/admin/mobile-platform/settings/deeplinks', [
            'dl_apple_team_id' => 'ABCDE12345', 'dl_apple_bundle_id' => 'com.lynomia.hub',
            'dl_android_package' => 'com.lynomia.hub', 'dl_android_fingerprints' => $fp . "\n" . $fp2 . ', ' . $fp,
        ])->assertSessionHas('ok');

        $this->assertSame(strtoupper($fp) . ',' . $fp2, setting('mobile.dl_android_fingerprints'));
        $this->assertSame('ABCDE12345', setting('mobile.dl_apple_team_id'));
        // الوثيقةُ العالميّةُ تقرأ القيمة
        $this->get('/.well-known/assetlinks.json')->assertOk()->assertSee('com.lynomia.hub');
    }

    public function test_fcm_token_is_write_only_encrypted_and_never_echoed(): void
    {
        $this->seedCore();
        $secret = 'ya29.SECRET-TOKEN-7f3c9e1d2b8a4c6e';
        $this->actingAs($this->owner)->post('/admin/mobile-platform/settings/push', [
            'push_driver' => 'fcm', 'push_fcm_project_id' => 'lynomia-hub', 'push_fcm_access_token' => $secret,
        ])->assertSessionHas('ok');

        $this->assertStringStartsWith('enc:', (string) $this->raw('mobile.push_fcm_access_token'), 'السرُّ مشفَّرٌ في القاعدة');
        $this->assertSame($secret, (string) setting('mobile.push_fcm_access_token'));
        $audit = (string) DB::table('audits')->where('action', MobileSettings::AUDIT_ACTION)
            ->where('name', 'mobile.push_fcm_access_token')->value('after');
        $this->assertStringNotContainsString($secret, $audit, 'القيدُ لا يحمل السرّ');

        $html = $this->get('/admin/mobile-platform?tab=push')->assertOk()->getContent();
        $this->assertStringNotContainsString($secret, $html, 'الصفحةُ لا تعيد السرّ');
        $this->assertStringContainsString('🔐 مضبوط', $html);

        // الفارغُ يُبقي المخزَّن، وخطأُ تحقّقٍ لا يعيد السرَّ في old()
        $this->post('/admin/mobile-platform/settings/push', ['push_driver' => 'fcm', 'push_fcm_project_id' => 'lynomia-hub',
            'push_fcm_access_token' => ''])->assertSessionHas('ok');
        $this->assertSame($secret, (string) setting('mobile.push_fcm_access_token'));
        $this->post('/admin/mobile-platform/settings/push', ['push_driver' => 'bad', 'push_fcm_access_token' => 'other-secret-abcdef'])
            ->assertSessionHasErrors([], null, 'mobileSettings');
        $this->assertNull(session()->getOldInput('push_fcm_access_token'));
        $this->assertSame($secret, (string) setting('mobile.push_fcm_access_token'));

        // المسحُ صريحٌ بخانته
        $this->post('/admin/mobile-platform/settings/push', ['push_driver' => 'fcm', 'push_fcm_project_id' => 'lynomia-hub',
            'clear_fcm_access_token' => '1'])->assertSessionHas('ok');
        $this->assertNull($this->raw('mobile.push_fcm_access_token'));
    }

    public function test_only_the_center_guard_may_write(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->post('/admin/mobile-platform/settings/release', ['min_version_ios' => '1.0.0'])
            ->assertForbidden();
        $this->actingAs($this->viewer)->post('/admin/mobile-platform/settings/push', ['push_driver' => 'fcm'])
            ->assertForbidden();
        $this->actingAs($this->owner)->post('/admin/mobile-platform/settings/unknown', [])->assertNotFound();
        $this->assertSame(0, Setting::where('key', 'like', 'mobile.%')->count());
    }

    public function test_configure_links_open_the_editor_and_settings_page_names_the_owner(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $cfg = $this->get('/admin/mobile-platform?tab=config')->assertOk()->getContent();
        $this->assertStringContainsString('id="mps-min_version_ios"', $cfg, 'الحقلُ موجودٌ في المحرّر');
        $this->assertStringContainsString(e(MobileSettings::editUrl('mobile.min_version_ios')), $cfg, '«اضبط ←» يفتح المحرّر');
        $this->assertStringNotContainsString(e(route('settings.edit')) . '#mobile.', $cfg, 'لا رابطَ إلى صفٍّ بلا حقل');
        $ov = $this->get('/admin/mobile-platform')->assertOk()->getContent();
        $this->assertStringNotContainsString(e(route('settings.edit')) . '#mobile.', $ov);

        $settings = $this->get(route('settings.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('يُحرَّر في مركز منصّة الجوال', $settings);
        $this->assertStringContainsString(e(route('mobileplatform.index', ['tab' => 'push'])), $settings);
    }

    // ═══════════ 2.4 · «نشطة» تعريفٌ واحد للعدّاد والفلتر والوسم ═══════════

    public function test_active_sessions_kpi_matches_the_active_filter(): void
    {
        $this->seedCore();
        $inst = MobileInstallation::create(['user_id' => $this->employee->id, 'installation_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'platform' => 'ios', 'registered_at' => now(), 'last_seen_at' => now()]);
        [$s] = MobileSessionService::mint($this->employee, $inst, '10.0.0.9', '1.0.0', 'ios');
        // رمزُ الوصول (١٥ دقيقة) انتهى والتحديثُ حيّ — الجلسةُ قائمةٌ يجدّدها التطبيق
        $s->forceFill(['access_expires_at' => now()->subHour(), 'refresh_expires_at' => now()->addDays(10)])->save();

        $kpi = MobilePlatform::sessionStats()['active'];
        $listed = collect(MobilePlatform::sessions(['status' => 'active'])->items())->count();
        $this->assertSame(1, $listed);
        $this->assertSame($listed, $kpi, 'عدّادُ «نشطة» يخالف فلترَ «نشطة» — تعريفان لسؤالٍ واحد');
        $this->assertSame('active', MobilePlatform::sessionStatus($s->fresh())['key']);
    }
}
