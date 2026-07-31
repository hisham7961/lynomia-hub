<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use Tests\TestCase;

/**
 * المرحلة ٤ — محرك الإجازات: كان الرصيد رقماً ساكناً لا يُخصم ولا يُستعاد،
 * والأيام تُكتب يدوياً، والتواريخ العكسية والتداخل يمرّان بصمت.
 */
class LeaveEngineRound6Test extends TestCase
{
    protected function emp(float $bal = 20): Employee
    {
        return Employee::create(['name' => 'موظف رصيد ' . uniqid(), 'status' => 'نشط', 'leave_bal' => $bal]);
    }

    public function test_days_derive_from_workdays_and_reversed_dates_refuse(): void
    {
        $this->seedCore();
        $emp = $this->emp();

        // أسبوعان كاملان = ١٠ أيام عمل (الجمعة والسبت عطلة) — تُشتق آلياً
        $lv = LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية', 'status' => 'مقدّم',
            'date_from' => '2026-06-01', 'date_to' => '2026-06-14']);
        $this->assertEquals(10.0, (float) $lv->days, 'الأيام تُشتق من أيام العمل لا تُكتب باليد');

        // النهاية قبل البداية تُرفض
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية', 'status' => 'مقدّم',
            'date_from' => '2026-07-10', 'date_to' => '2026-07-05']);
    }

    public function test_overlapping_leave_for_same_employee_refuses(): void
    {
        $this->seedCore();
        $emp = $this->emp();
        LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية', 'status' => 'معتمد',
            'date_from' => '2026-08-01', 'date_to' => '2026-08-10']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة طارئة', 'status' => 'مقدّم',
            'date_from' => '2026-08-05', 'date_to' => '2026-08-06']);
    }

    public function test_approval_deducts_balance_and_cancellation_restores(): void
    {
        $this->seedCore();
        $emp = $this->emp(20);
        $lv = LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية', 'status' => 'مقدّم',
            'date_from' => '2026-06-01', 'date_to' => '2026-06-14']);   // ١٠ أيام عمل

        $this->assertEquals(20.0, (float) $emp->fresh()->leave_bal, 'التقديم لا يخصم');

        $lv->update(['status' => 'معتمد']);
        $this->assertEquals(10.0, (float) $emp->fresh()->leave_bal, 'الاعتماد يخصم أيام العمل من الرصيد');

        // اعتماد متكرر (حفظ آخر) لا يخصم مرتين
        $lv->fresh()->update(['note' => 'تحديث عابر']);
        $this->assertEquals(10.0, (float) $emp->fresh()->leave_bal);

        // الإلغاء يعيد الرصيد
        $lv->fresh()->update(['status' => 'ملغى']);
        $this->assertEquals(20.0, (float) $emp->fresh()->leave_bal, 'إلغاء المعتمدة يعيد رصيدها');
    }

    public function test_portal_shows_self_leave_request_button(): void
    {
        $this->seedCore();
        Employee::create(['name' => 'صاحبة البوابة', 'status' => 'نشط', 'user_id' => $this->employee->id]);

        $this->actingAs($this->employee)->get('/me')
            ->assertOk()->assertSee('طلب إجازة');
    }
}
