<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ٩: **أسطحُ عرضٍ تتجاوز النطاق أو قفلَ الحقل.**
 *
 *  ١) `modules/custom/payroll.blade.php:4` — بنودُ المسيّر تقرأ أسماءَ
 *     الموظفين خاماً: قارئٌ معزولٌ بشركةٍ يرى أسماءَ موظفي شركةٍ أخرى.
 *  ٢) `Staff.php:249` — شاشةُ `/staff` تسرد **كلَّ حسابات النظام** (اسمٌ وبريدٌ
 *     ودورٌ وحالة) لمن يملك `hr:v` وحده — بلا رايةِ `users` وبلا تنطيقِ شركات.
 *  ٣) `AuditController:73` — «نبضُ» سجلّ التدقيق يقرأ الجدولَ كلَّه بلا نطاق،
 *     فيُطبع عددُ الحذف والتصدير وعناوينُ IP عابرةً حدَّ الشركات.
 *  ٤) `MorningController:168` — بطاقةُ «مشاريع متعثرة» بلا فحصِ صلاحيةٍ على
 *     وحدة المشاريع: يكفي فتحُ شاشة الصباح لقراءة أسماءِ المشاريع وصحّتها.
 *  ٥) `ReportController:25` — تقريرُ المالية يتجاوز `hub_field_mode`: مبالغُ
 *     محجوبةٌ في القائمة تُطبع في التقرير.
 */
class ReadSurfaceLeaksRound6Test extends TestCase
{
    private function roleWith(array $matrix, array $flags = []): Role
    {
        $none = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0]])->all();

        return Role::create(['name' => 'دور ' . \Illuminate\Support\Str::random(5),
            'scope' => 'all', 'flags' => $flags, 'matrix' => array_replace($none, $matrix)]);
    }

    private function userWith(Role $r, ?array $companies = null): User
    {
        return User::create(['name' => 'قارئ', 'email' => 'x' . \Illuminate\Support\Str::random(6) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $r->id, 'status' => 'نشط',
            'companies' => $companies, 'password_changed_at' => now()]);
    }

    /* ── ١) أسماءُ بنود المسيّر منطَّقة ── */

    public function test_payroll_lines_do_not_name_employees_out_of_scope(): void
    {
        $this->seedCore();

        $a = Company::create(['name_ar' => 'شركة ألف']);
        $b = Company::create(['name_ar' => 'شركة باء']);
        $mine = Employee::create(['name' => 'موظفُ ألف', 'status' => 'نشط', 'salary' => 100, 'company_id' => $a->id]);
        $theirs = Employee::create(['name' => 'موظفُ باء السرّيّ', 'status' => 'نشط', 'salary' => 900, 'company_id' => $b->id]);

        $run = \App\Models\PayrollRun::create(['name' => 'مسيّر', 'month' => now()->format('Y-m'),
            'status' => 'مسودة', 'company_id' => $a->id]);
        foreach ([$mine, $theirs] as $e) {
            \App\Models\PayrollLine::create(['run_id' => $run->id, 'emp_id' => $e->id,
                'base' => (float) $e->salary, 'net' => (float) $e->salary]);
        }

        $u = $this->userWith($this->roleWith([
            'payroll' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0],
            'hr' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0],
        ]), [$a->id]);

        $html = $this->actingAs($u)->get('/m/payroll/' . $run->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('موظفُ باء السرّيّ', $html,
            'بنودُ المسيّر تطبع أسماءَ موظفي شركةٍ أجنبية — قراءةٌ خام بلا تنطيق');
    }

    /* ── ٢) شاشةُ الفجوات لا تسرد حسابات النظام لمن لا يديرها ── */

    public function test_staff_gaps_do_not_list_accounts_without_the_users_flag(): void
    {
        $this->seedCore();

        $u = $this->userWith($this->roleWith(['hr' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]]));
        $html = $this->actingAs($u)->get('/staff')->assertOk()->getContent();

        $this->assertStringNotContainsString($this->employee->email, $html,
            'شاشةُ الفريق تسرد بريدَ كلِّ حسابات النظام لمن يملك hr:v وحده — بلا رايةِ users');
    }

    public function test_staff_gaps_still_list_accounts_for_a_user_manager(): void
    {
        $this->seedCore();

        $html = $this->actingAs($this->owner)->get('/staff')->assertOk()->getContent();
        $this->assertStringContainsString($this->employee->email, $html,
            'حُجبت القائمةُ عمّن يديرها — الحارسُ قتل الشاشة بدل أن يحدّها');
    }

    /* ── ٣) نبضُ التدقيق منطَّق ── */

    public function test_audit_pulse_is_scoped_to_the_readers_companies(): void
    {
        $this->seedCore();

        $a = Company::create(['name_ar' => 'شركة ألف']);
        $b = Company::create(['name_ar' => 'شركة باء']);
        DB::table('audits')->insert([
            ['user_id' => $this->owner->id, 'action' => 'حذف', 'module' => 'clients',
             'company_id' => $b->id, 'ip' => '203.0.113.77', 'created_at' => now()],
            ['user_id' => $this->owner->id, 'action' => 'حذف', 'module' => 'clients',
             'company_id' => $b->id, 'ip' => '203.0.113.77', 'created_at' => now()],
        ]);

        $role = $this->roleWith(['clients' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]], ['audit' => 1]);
        $u = $this->userWith($role, [$a->id]);

        $html = $this->actingAs($u)->get('/admin/audit')->assertOk()->getContent();
        $this->assertStringNotContainsString('203.0.113.77', $html,
            'نبضُ التدقيق يطبع عناوينَ IP من شركةٍ خارج نطاق القارئ');
    }

    /* ── ٤) شاشةُ الصباح تحترم صلاحية المشاريع ── */

    public function test_morning_hides_projects_without_the_module_permission(): void
    {
        $this->seedCore();

        \App\Models\Project::create(['name' => 'مشروعٌ متعثّرٌ سرّيّ', 'status' => 'نشط']);

        $u = $this->userWith($this->roleWith(['hr' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]]));
        $html = $this->actingAs($u)->get('/morning')->assertOk()->getContent();

        $this->assertStringNotContainsString('مشروعٌ متعثّرٌ سرّيّ', $html,
            'شاشةُ الصباح تطبع أسماءَ المشاريع لمن لا يملك رؤيتَها');
    }

    /* ── ٥) تقريرُ المالية يحترم قفلَ الحقل ── */

    public function test_finance_report_respects_the_field_lock_on_amounts(): void
    {
        $this->seedCore();

        \App\Models\FinDocument::create(['doc_no' => 'INV-777', 'kind' => 'فاتورة مبيعات',
            'date' => now()->toDateString(), 'total' => 123456.75, 'paid' => 0, 'state' => 'مرسلة']);

        $role = $this->roleWith(['fin' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]]);
        $role->forceFill(['field_rules' => ['fin' => ['total' => 'hide']]])->save();
        $u = $this->userWith($role);

        $html = $this->actingAs($u)->get('/reports/finance')->assertOk()->getContent();
        foreach (['123456.75', '123,456.75', '123456.750'] as $needle) {
            $this->assertStringNotContainsString($needle, $html,
                'تقريرُ المالية يطبع مبلغاً محجوباً بقفل الحقل — التقريرُ نافذةٌ خلفية على القائمة');
        }
    }
}
