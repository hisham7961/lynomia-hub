<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **تفكيكُ رايةِ التصدير** (Permissions 360 · م2 · 01.1/11.2/02.3/17.2/20.4/09.4).
 *
 * إضافةٌ لا كسر: رايةُ `exp` تبقى مفتاحاً رئيساً يصدّرُ كلَّ وحدةٍ يراها الحامل؛ وأُضيف
 * مفتاحٌ دقيقٌ `<module>.export` يُقرأ بـ`hub_can`، فيُمنَح تصديرُ وحدةٍ بعينها دون فتحِ
 * تصديرِ كلِّ شيء. حزامُ التصدير (تجميدُ الطوارئ + تصعيدُ الحجم + بصمةُ التدقيق) فوقَ القرار.
 */
class ExportDecompositionTest extends TestCase
{
    private function userWith(string $email, array $matrix, array $flags = []): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => $flags, 'matrix' => $matrix]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /** مفتاحُ `hr.export` الدقيقُ (بلا رايةِ exp) يُتيحُ تصديرَ الموارد */
    public function test_module_export_key_allows_export_without_exp_flag(): void
    {
        $this->seedCore();
        $u = $this->userWith('hrexp@test.local', ['hr' => ['v' => 1, 'export' => 1]]);
        $this->actingAs($u)->get(route('m.export', ['module' => 'hr']))->assertOk();
    }

    /** بلا مفتاحٍ ولا راية ⇒ التصديرُ ممنوعٌ (٤٠٣) رغمَ رؤيةِ الوحدة */
    public function test_export_forbidden_without_key_or_flag(): void
    {
        $this->seedCore();
        $u = $this->userWith('noexp@test.local', ['hr' => ['v' => 1]]);
        $this->actingAs($u)->get(route('m.export', ['module' => 'hr']))->assertForbidden();
    }

    /** الرايةُ الجامعةُ تبقى تعمل (توافقٌ خلفيّ) */
    public function test_legacy_exp_flag_still_exports(): void
    {
        $this->seedCore();
        $u = $this->userWith('expflag@test.local', ['hr' => ['v' => 1]], ['exp' => 1]);
        $this->actingAs($u)->get(route('m.export', ['module' => 'hr']))->assertOk();
    }

    /** المفتاحُ لمجالِه وحدَه: `hr.export` لا يُصدّرُ المشتريات */
    public function test_export_key_is_scoped_to_its_module(): void
    {
        $this->seedCore();
        $u = $this->userWith('hronly@test.local', ['hr' => ['v' => 1, 'export' => 1], 'purchases' => ['v' => 1]]);
        $this->actingAs($u)->get(route('m.export', ['module' => 'hr']))->assertOk();
        $this->actingAs($u)->get(route('m.export', ['module' => 'purchases']))->assertForbidden();
    }
}
