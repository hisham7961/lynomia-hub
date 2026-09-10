<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\MonthlyAttendance;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * **الحضورُ الشهريّ — سجلٌّ لكلِّ موظّفٍ، وتصديرٌ للمحاسب بصلاحيّة.**
 *
 * يبني فوق `DailyWorkCompliance` (لا محرّكَ ثانٍ). ما يحرسه:
 *  - العدُّ الصحيح: العطلُ غيرُ المسجَّلة ليست غياباً؛ الغيابُ لصفٍّ مختوم؛ الإجازةُ منفصلة.
 *  - المحاسبُ بـ`attend:v` وحدَه يرى الشهريَّ ويصدّره، **ولا** يرى مركزَ التقارير (محتوى §34).
 *  - عزلُ الشركة (§80)، وردُّ العميلِ ٤٠٤ (§79)، والاكتشافيّةُ في الشريط (§121).
 */
class MonthlyAttendanceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    protected function accountant(array $matrix = ['attend' => ['v' => 1]]): User
    {
        $role = Role::create(['name' => 'محاسب' . \Illuminate\Support\Str::random(4), 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);
        return User::create(['name' => 'محاسب', 'email' => \Illuminate\Support\Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /* ═══════════ العدُّ الصحيح ═══════════ */

    public function test_monthly_totals_count_present_leave_absent_and_off_days(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        $e = Employee::create(['name' => 'موظف الشهر', 'status' => 'نشط']);

        Attendance::create(['emp_id' => $e->id, 'date' => '2026-09-01', 'time_in' => '08:00', 'time_out' => '16:00', 'status' => 'حاضر', 'hours' => 8]);
        Attendance::create(['emp_id' => $e->id, 'date' => '2026-09-02', 'time_in' => '08:00', 'time_out' => '16:00', 'status' => 'حاضر', 'hours' => 8]);
        // غيابٌ مختوم (صفٌّ بلا حضور)
        Attendance::create(['emp_id' => $e->id, 'date' => '2026-09-03', 'status' => 'غائب']);
        // إجازةٌ معتمدة (برصيدٍ كافٍ)
        Employee::whereKey($e->id)->update(['leave_bal' => 30]);
        LeaveRequest::create(['emp_id' => $e->id, 'type' => 'إجازة سنوية',
            'date_from' => '2026-09-04', 'date_to' => '2026-09-04', 'days' => 1, 'status' => 'معتمد']);

        $t = MonthlyAttendance::sheet($e, '2026-09')['totals'];
        $this->assertSame(2, $t['present'], 'يومَا حضور');
        $this->assertSame(1, $t['absent'], 'غيابٌ واحدٌ مختوم');
        $this->assertSame(1, $t['leave'], 'إجازةٌ واحدة');
        $this->assertSame(16.0, $t['attendance_hours']);
        // بقيّةُ أيام سبتمبر (٣٠ − ٤ مسجَّلة) عطلٌ/غيرُ مسجَّلة — لا تُحتسب غياباً
        $this->assertSame(26, $t['off'], 'العطلُ غيرُ المسجَّلة ليست غياباً');
    }

    /* ═══════════ صلاحيّةُ المحاسب ═══════════ */

    public function test_accountant_sees_monthly_and_exports_but_not_reports_center(): void
    {
        $this->seedCore();
        $acc = $this->accountant();
        $e = Employee::create(['name' => 'موظف', 'status' => 'نشط']);
        Attendance::create(['emp_id' => $e->id, 'date' => now()->toDateString(), 'time_in' => '08:00', 'time_out' => '16:00', 'status' => 'حاضر', 'hours' => 8]);
        $month = now()->format('Y-m');

        $this->actingAs($acc)->get(route('reports.monthly', ['month' => $month]))->assertOk()->assertSee('الحضور الشهري');
        $this->actingAs($acc)->get(route('reports.monthly.employee', ['emp' => $e->id, 'month' => $month]))->assertOk();
        $exp = $this->actingAs($acc)->get(route('reports.monthly.export', ['month' => $month]))->assertOk();
        $this->assertSame('text/csv; charset=UTF-8', $exp->headers->get('content-type'));

        // فصلُ النطاق (§34): المحاسبُ لا يرى مركزَ التقارير (محتوى) ولا المراجعة
        $this->actingAs($acc)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($acc)->get(route('reports.review'))->assertForbidden();
    }

    public function test_monthly_export_csv_has_headers_and_a_row(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        $acc = $this->accountant();
        $e = Employee::create(['name' => 'أحمد الحاضر', 'status' => 'نشط']);
        Attendance::create(['emp_id' => $e->id, 'date' => '2026-09-02', 'time_in' => '08:00', 'time_out' => '16:00', 'status' => 'حاضر', 'hours' => 8]);

        $csv = $this->actingAs($acc)->get(route('reports.monthly.export', ['month' => '2026-09']))->streamedContent();
        $this->assertStringContainsString('الموظف', $csv);
        $this->assertStringContainsString('الحالة المحتسَبة', $csv);
        $this->assertStringContainsString('أحمد الحاضر', $csv);
        $this->assertStringContainsString('2026-09-02', $csv);
    }

    /* ═══════════ العزل ═══════════ */

    public function test_company_isolation_in_monthly(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $empA = Employee::create(['name' => 'موظفُ ألف', 'status' => 'نشط', 'company_id' => $coA->id]);
        $empB = Employee::create(['name' => 'موظفُ باء', 'status' => 'نشط', 'company_id' => $coB->id]);
        Attendance::create(['emp_id' => $empA->id, 'date' => now()->toDateString(), 'time_in' => '08:00', 'time_out' => '16:00', 'status' => 'حاضر', 'hours' => 8]);
        Attendance::create(['emp_id' => $empB->id, 'date' => now()->toDateString(), 'time_in' => '08:00', 'time_out' => '16:00', 'status' => 'حاضر', 'hours' => 8]);

        $role = Role::create(['name' => 'محاسبُ ألف', 'scope' => 'company', 'flags' => [],
            'matrix' => ['attend' => ['v' => 1]], 'companies' => [$coA->id]]);
        $acc = User::create(['name' => 'محاسبُ ألف', 'email' => 'accA@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'companies' => [$coA->id], 'password_changed_at' => now()]);

        $html = $this->actingAs($acc)->get(route('reports.monthly', ['month' => now()->format('Y-m')]))->assertOk()->getContent();
        $this->assertStringContainsString('موظفُ ألف', $html);
        $this->assertStringNotContainsString('موظفُ باء', $html, 'لا تسرّبَ عبرَ الشركات (§80)');
    }

    public function test_client_cannot_reach_monthly(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'عميل', 'scope' => 'all', 'flags' => [], 'matrix' => ['attend' => ['v' => 1]]]);
        $client = User::create(['name' => 'عميل', 'email' => 'climon@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'account_type' => 'client', 'password_changed_at' => now()]);

        $this->actingAs($client)->get(route('reports.monthly'))->assertNotFound();
        $this->actingAs($client)->get(route('reports.monthly.export'))->assertNotFound();
    }

    /* ═══════════ الاكتشافيّة ═══════════ */

    public function test_monthly_is_discoverable_in_the_accountant_sidebar(): void
    {
        $this->seedCore();
        $acc = $this->accountant();
        $html = $this->actingAs($acc)->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('الحضور الشهري', $html);
        $this->assertStringContainsString('href="' . route('reports.monthly') . '"', $html);
        // ولا يرى مركزَ التقارير (hr:v) في الشريط
        $this->assertStringNotContainsString('مركز التقارير اليومية', $html);
    }
}
