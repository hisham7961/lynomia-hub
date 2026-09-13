<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionInspector;
use Tests\TestCase;

/**
 * **ترقيةُ مُفسِّرِ الصلاحيّات** (Permissions 360 · م3 — مركزُ إدارةِ الصلاحيّات).
 *
 * «ماذا يفتح هذا المفتاحُ ولمن مُنح؟» — الفاحصُ يفسِّر الآن المفاتيحَ الدقيقةَ
 * (كتالوجُ hub_permissions) والراياتِ (بما فيها مجموعاتُ المراقبةِ الثلاث)،
 * وتظهرُ في /admin/access و/admin/access/role/{role}.
 */
class Permissions360InspectorTest extends TestCase
{
    private function user(string $email, array $matrix, array $flags = []): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => $flags, 'matrix' => $matrix]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /* ═══════════ finePerms: الحكمُ الفعّال لكلِّ مفتاحٍ على وحدتِه ═══════════ */

    public function test_fine_perms_reports_granted_and_denied_keys(): void
    {
        $this->seedCore();
        $u = $this->user('insp@test.local', ['hr' => ['v' => 1, 'fieldsec' => 1], 'payroll' => ['v' => 1]]);

        $fine = collect(PermissionInspector::finePerms($u));
        $this->assertNotEmpty($fine, 'الكتالوجُ غيرُ فارغ');

        $hrFieldsec = $fine->first(fn ($f) => $f['key'] === 'fieldsec' && $f['module'] === 'hr');
        $this->assertNotNull($hrFieldsec, 'مفتاحُ الحقولِ الحسّاسةِ على الموارد مُدرَج');
        $this->assertTrue($hrFieldsec['granted'], 'حاملُه يظهرُ ممنوحاً');

        $payrollApprove = $fine->first(fn ($f) => $f['key'] === 'approve' && $f['module'] === 'payroll');
        $this->assertNotNull($payrollApprove, 'اعتمادُ الرواتبِ مُدرَج');
        $this->assertFalse($payrollApprove['granted'], 'غيرُ الحاملِ يظهرُ غيرَ ممنوح');

        // المالكُ يتجاوز — كلُّ المفاتيح ممنوحة
        $ownerFine = collect(PermissionInspector::finePerms($this->owner));
        $this->assertTrue($ownerFine->every(fn ($f) => $f['granted']), 'المالكُ ممنوحٌ كلَّ مفتاح');
    }

    /* ═══════════ flags: الراياتُ ومجموعاتُ المراقبة، ورقابةُ الاتصالاتِ بحكمِها الحقيقيّ ═══════════ */

    public function test_flags_report_monitor_groups_and_oversight_truthfully(): void
    {
        $this->seedCore();
        $fin = $this->user('inspf@test.local', [], ['finAnalytics' => 1]);

        $flags = collect(PermissionInspector::flags($fin))->keyBy('key');
        $this->assertTrue($flags['finAnalytics']['granted'], 'مجموعةُ الماليّةِ ممنوحة');
        $this->assertFalse($flags['opsAnalytics']['granted'], 'مجموعةُ التشغيلِ غيرُ ممنوحة');
        $this->assertFalse($flags['monitor']['granted'], 'الرايةُ الجامعةُ غيرُ ممنوحة');

        // رقابةُ الاتصالات ليست موروثةً للمالك آليّاً (حكمُ isOversightOfficer لا hub_flag)
        $ownerFlags = collect(PermissionInspector::flags($this->owner))->keyBy('key');
        $this->assertFalse($ownerFlags['oversight']['granted'], 'المالكُ لا يرثُ الرقابةَ آليّاً');
        $this->assertTrue($ownerFlags['monitor']['granted'], 'وسائرُ الراياتِ يتجاوزها');
    }

    /* ═══════════ السطح: صفحتا التشخيصِ تعرضان المفاتيحَ الدقيقة ═══════════ */

    public function test_access_pages_render_fine_perms_sections(): void
    {
        $this->seedCore();
        $u = $this->user('inspp@test.local', ['hr' => ['v' => 1]]);

        // تشخيصُ مستخدمٍ محدَّد
        $this->actingAs($this->owner)
            ->get(route('access.index', ['user' => $u->id]))
            ->assertOk()
            ->assertSee('الصلاحيّاتُ الدقيقة')
            ->assertSee('الراياتُ الفعّالة')
            ->assertSee('fieldsec');

        // معاينةُ دور
        $this->actingAs($this->owner)
            ->get(route('access.role', $u->role_id))
            ->assertOk()
            ->assertSee('الصلاحيّاتُ الدقيقة')
            ->assertSee('docsec');
    }
}
