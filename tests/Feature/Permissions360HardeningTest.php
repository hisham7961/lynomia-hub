<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **Permissions 360 — تصليباتُ المرحلة 0 (عيوبٌ مُثبَتةٌ بالتدقيق).**
 *
 * إثباتٌ لا ادّعاء: كلُّ اختبارٍ يفشلُ على الشيفرةِ قبلَ الإصلاح.
 *  · P1 [15.1] حدُّ العميل: `portal.employee` كان يعتمدُ على `hr:v` وحدَه، فمصفوفةُ عميلٍ
 *    ملوّثةٌ بـ`hr:v` تبلغُ ملفَّ الموظّف 360 الداخليّ — الآن يُردُّ ٤٠٤ لأيِّ حسابِ عميل.
 *  · P1 [10.1] IDOR عبرَ الشركات: `finalize` كان يستعملُ `hub_company_scope` (فلترُ الجلسةِ
 *    للتركيز، يخلو عند غياب شركةٍ نشطة) بدل `hub_scope` (عزلُ الدورِ الدائم) — فمحرّرُ HR
 *    لشركةِ ألف كان يختمُ الأثرَ الفعّالَ على صفِّ حضورٍ لشركةِ باء.
 */
class Permissions360HardeningTest extends TestCase
{
    /* ═══════════ P1 [15.1] — حدُّ العميل يعلو مصفوفةً ملوّثة ═══════════ */

    public function test_client_with_contaminated_hr_matrix_cannot_reach_employee_360(): void
    {
        $this->seedCore();

        // دورُ عميلٍ «مُساءُ الضبط»: مُنِح hr:v في مصفوفته (تلوّثٌ) — يجب ألّا يسرّب حرفاً
        $role = Role::create(['name' => 'عميلٌ ملوّثُ المصفوفة', 'scope' => 'all', 'flags' => [],
            'matrix' => ['hr' => ['v' => 1, 'a' => 1, 'e' => 1]]]);
        $client = User::create(['name' => 'حسابُ عميل', 'email' => 'ctam@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'account_type' => 'client', 'password_changed_at' => now()]);

        $emp = Employee::create(['name' => 'موظّفٌ داخليّ', 'status' => 'نشط']);

        // ٤٠٤ لا ٢٠٠ ولا ٤٠٣: لا نُثبت وجودَ ما لا يخصّه (حدُّ العميلِ فوق المصفوفة §79)
        $this->actingAs($client)->get(route('portal.employee', ['id' => $emp->id]))->assertNotFound();
    }

    public function test_internal_hr_reader_still_reaches_employee_360(): void
    {
        $this->seedCore();
        $emp = Employee::create(['name' => 'موظّفٌ داخليّ', 'status' => 'نشط']);
        // المالكُ (وصولٌ كامل) يبقى يرى الملفَّ — لا انحدارَ على الداخليّ
        $this->actingAs($this->owner)->get(route('portal.employee', ['id' => $emp->id]))->assertOk();
    }

    /* ═══════════ P1 [10.1] — لا ختمَ عبرَ الشركات (finalize) ═══════════ */

    public function test_company_scoped_hr_editor_cannot_finalize_another_companys_attendance(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);

        $empB = Employee::create(['name' => 'موظفُ باء', 'status' => 'نشط', 'company_id' => $coB->id]);
        $rowB = Attendance::create(['emp_id' => $empB->id, 'date' => now()->toDateString(),
            'time_in' => '08:00', 'time_out' => '16:00', 'status' => 'حاضر', 'hours' => 8, 'company_id' => $coB->id]);

        // محرّرُ HR منطَّقٌ على «ألف» فقط
        $role = Role::create(['name' => 'محرّرُ HR ألف', 'scope' => 'company', 'flags' => [],
            'matrix' => ['hr' => ['v' => 1, 'e' => 1]], 'companies' => [$coA->id]]);
        $hr = User::create(['name' => 'محرّرُ ألف', 'email' => 'hrA@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'companies' => [$coA->id], 'password_changed_at' => now()]);

        // ٤٠٤: صفُّ حضورِ شركةِ باء خارجَ نطاقِ الدور — لا يُختَم أثرُه الفعّال (عزلُ الدور لا الجلسة §80)
        $this->actingAs($hr)->post(route('reports.finalize', $rowB->id), ['outcome' => 'clear'])
            ->assertNotFound();
    }

    public function test_in_scope_hr_editor_can_finalize_own_company_attendance(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $empA = Employee::create(['name' => 'موظفُ ألف', 'status' => 'نشط', 'company_id' => $coA->id]);
        $rowA = Attendance::create(['emp_id' => $empA->id, 'date' => now()->toDateString(),
            'time_in' => '08:00', 'time_out' => '16:00', 'status' => 'حاضر', 'hours' => 8, 'company_id' => $coA->id]);

        $role = Role::create(['name' => 'محرّرُ HR ألف٢', 'scope' => 'company', 'flags' => [],
            'matrix' => ['hr' => ['v' => 1, 'e' => 1]], 'companies' => [$coA->id]]);
        $hr = User::create(['name' => 'محرّرُ ألف٢', 'email' => 'hrA2@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'companies' => [$coA->id], 'password_changed_at' => now()]);

        // داخلَ النطاق: يُقبَل (إعادةُ توجيهٍ بعد الختم، لا ٤٠٤/٤٠٣)
        $this->actingAs($hr)->post(route('reports.finalize', $rowA->id), ['outcome' => 'clear'])
            ->assertRedirect();
    }
}
