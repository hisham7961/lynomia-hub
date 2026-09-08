<?php

namespace Tests\Feature\MobilePlatform;

use App\Models\AuditEntry;
use App\Models\MobileInstallation;
use App\Models\Role;
use App\Models\User;
use App\Support\MobilePlatform;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مركزُ منصّة الجوال — الأمن والتليمتري (MPC-6)**: موقفُ أمنِ المصادقة، تدقيقُ
 * الجوال (`source=mobile` — السكّةُ القائمة، لا مخزنَ ثانٍ) مُرشَّحاً بأعمدةٍ آمنة،
 * وتبنّي الإصدارات الحقيقيّ (صادقٌ فارغٌ قبل الإطلاق). الحرسُ في المتحكّم.
 */
class MobilePlatformSecurityTest extends TestCase
{
    private function mobileAdmin(): User
    {
        $role = Role::create(['name' => 'مسؤولُ جوال', 'scope' => 'all', 'flags' => ['mobile' => 1], 'matrix' => []]);

        return User::create(['name' => 'مسؤول', 'email' => 'mob6@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function audit(string $source, string $action, string $category, string $outcome = 'success'): void
    {
        AuditEntry::create([
            'user_id' => $this->employee->id, 'action' => $action, 'source' => $source,
            'category' => $category, 'severity' => 'info', 'outcome' => $outcome,
            'ip' => '10.0.0.7', 'created_at' => now(),
        ]);
    }

    private function seedInstall(string $platform, string $version): void
    {
        MobileInstallation::create([
            'user_id' => $this->employee->id, 'installation_uuid' => (string) Str::uuid(),
            'platform' => $platform, 'app_version' => $version, 'push_capable' => true,
            'registered_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    public function test_security_tab_shows_posture_and_audit(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=security')->assertOk()
            ->assertSee('موقفُ أمنِ مصادقةِ الجوال')
            ->assertSee('تدقيقُ الجوال')
            ->assertSee('تبنّي الإصدارات');
    }

    public function test_employee_cannot_access_security_tab(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/admin/mobile-platform?tab=security')->assertForbidden();
    }

    public function test_mobile_admin_can_view_security_tab(): void
    {
        $this->seedCore();
        $this->actingAs($this->mobileAdmin())->get('/admin/mobile-platform?tab=security')->assertOk()
            ->assertSee('الأمن والتليمتري');
    }

    /** التدقيقُ يقتصر على source=mobile — لا يُظهر قيودَ الويب (لا خلط) */
    public function test_audit_shortcut_is_mobile_source_only(): void
    {
        $this->seedCore();
        $this->audit('mobile', 'دخول ناجح', 'AUTH_SUCCESS');
        $this->audit('mobile', 'خروج', 'LOGOUT');
        $this->audit('web', 'دخول ناجح', 'AUTH_SUCCESS');   // ويبٌ — يجب ألّا يُحسَب

        $this->assertSame(2, MobilePlatform::mobileAudit([])->count());   // simplePaginate ⇒ عدُّ الصفحة
        $stats = MobilePlatform::mobileAuditStats();
        $this->assertSame(2, $stats['total']);
        $this->assertSame(2, $stats['security']);   // كلاهما مُصنّفٌ أمنيّاً
    }

    /** ترشيحُ الفئة: security = المجموعةُ الأمنيّة، وفئةٌ بعينها تُصفّي فعلاً */
    public function test_audit_category_filter(): void
    {
        $this->seedCore();
        $this->audit('mobile', 'دخول ناجح', 'AUTH_SUCCESS');
        $this->audit('mobile', 'خروج', 'LOGOUT');

        $this->assertSame(1, MobilePlatform::mobileAudit(['category' => 'AUTH_SUCCESS'])->count());
        $this->assertSame(2, MobilePlatform::mobileAudit(['category' => 'security'])->count());
        // قيمةٌ خارجَ allowlist ⇒ تُتجاهَل (لا ترشيح، لا حقن)
        $this->assertSame(2, MobilePlatform::mobileAudit(['category' => "x'; DROP"])->count());
    }

    /** التبنّي حقيقيٌّ من التنصيبات — توزيعُ الإصدار والمنصّة */
    public function test_adoption_is_real_from_installations(): void
    {
        $this->seedCore();
        $this->seedInstall('ios', '1.0.0');
        $this->seedInstall('ios', '1.0.0');
        $this->seedInstall('android', '1.1.0');

        $adoption = MobilePlatform::adoption();
        $this->assertSame(3, $adoption['total']);
        $this->assertSame(2, $adoption['platforms']['ios'] ?? 0);
        $this->assertSame(1, $adoption['platforms']['android'] ?? 0);
        $this->assertSame(2, $adoption['versions']['1.0.0'] ?? 0);
    }

    /** صادقٌ فارغٌ قبل الإطلاق — لا تبنٍّ مُختلَق */
    public function test_adoption_is_honestly_empty_before_launch(): void
    {
        $this->seedCore();
        $adoption = MobilePlatform::adoption();
        $this->assertSame(0, $adoption['total']);
        $this->assertSame(0, $adoption['active_7d']);
        $this->assertSame([], $adoption['versions']);
    }

    /** لا before/after في سجلِّ التدقيق المعروض — كي لا يبلغ ما زُرع فيهما الشاشة */
    public function test_audit_rows_exclude_before_after_columns(): void
    {
        $this->seedCore();
        AuditEntry::create([
            'user_id' => $this->employee->id, 'action' => 'إبطالُ جلسةِ جوال', 'source' => 'mobile',
            'category' => 'SESSION_REVOKED', 'severity' => 'notice', 'outcome' => 'success',
            'after' => ['secret_probe' => 'LEAK-CANARY-42'], 'created_at' => now(),
        ]);

        $html = $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=security')->assertOk()->getContent();
        $this->assertStringNotContainsString('LEAK-CANARY-42', $html, 'حمولةُ after بلغت الشاشة');
    }
}
