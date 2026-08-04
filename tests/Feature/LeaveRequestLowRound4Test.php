<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * الموجة الرابعة — عيوب LeaveRequest المنخفضة:
 * (٢) صفٌّ قائمٌ بـ date_to فارغ (أُدرج قبل حارس «اليوم الواحد») لا يُحجَز عليه:
 *     استعلامُ التداخل يقارن `date_to >= from` وNULL يسقط، فإجازةٌ جديدة تتداخل
 *     معه لا تُمنَع. العلاج: COALESCE(date_to, date_from) في الاستعلام.
 * (٣) الاعتماد يهبط بالرصيد تحت الصفر بصمت — لا سقف: العلاج حارسُ اعتمادٍ يرفض
 *     ما يتجاوز الرصيد المتاح برسالةٍ واضحة.
 * (٤) خصمُ الرصيد اقرأ-ثم-اكتب بلا قفل: اعتمادان متزامنان لنفس الموظف يفقدان
 *     أحد الخصمين. العلاج: applyBalance تعيد القراءة من صفٍّ مقفول داخل معاملة.
 */
class LeaveRequestLowRound4Test extends TestCase
{
    protected function emp(float $bal = 20): Employee
    {
        return Employee::create(['name' => 'موظف ' . uniqid(), 'status' => 'نشط', 'leave_bal' => $bal]);
    }

    protected function applyBalance(Employee $emp, float $delta): void
    {
        $ref = new \ReflectionMethod(LeaveRequest::class, 'applyBalance');
        $ref->setAccessible(true);
        $ref->invoke(null, $emp, $delta);
    }

    /** (٢) صفٌّ قائمٌ بلا date_to يحجز التواريخ رغم غياب العمود */
    public function test_legacy_null_date_to_absence_still_blocks_overlap(): void
    {
        $this->seedCore();
        $emp = $this->emp(20);

        // صفٌّ «قديم» أُدرج مباشرةً بـ date_to فارغ (يتخطّى حارس saving)
        DB::table('leave_requests')->insert([
            'id' => (string) Str::uuid(), 'emp_id' => $emp->id, 'type' => 'إجازة سنوية',
            'status' => 'معتمد', 'date_from' => '2026-07-10', 'date_to' => null, 'days' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // إجازةٌ جديدة على اليوم نفسه — يجب أن تُمنَع بالتداخل مع الصف القديم
        try {
            LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية', 'status' => 'مقدّم',
                'date_from' => '2026-07-10', 'date_to' => '2026-07-10']);
            $this->fail('طلبٌ جديد تداخل مع غيابٍ قائمٍ بلا date_to ولم يُمنَع');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('from', $e->errors());
        }
    }

    /** (٣) الاعتماد لا يتجاوز الرصيد المتاح */
    public function test_approval_beyond_balance_is_capped(): void
    {
        $this->seedCore();
        $emp = $this->emp(3);   // رصيدٌ لا يكفي لعشرة أيام

        $lv = LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية', 'status' => 'مقدّم',
            'date_from' => '2026-06-01', 'date_to' => '2026-06-14']);   // ~١٠ أيام عمل

        try {
            $lv->update(['status' => 'معتمد']);
            $this->fail('اعتُمدت إجازةٌ تتجاوز الرصيد فهبط تحت الصفر بصمت');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('days', $e->errors());
        }

        $this->assertEquals(3.0, (float) $emp->fresh()->leave_bal, 'خُصم الرصيد رغم رفض الاعتماد');
        $this->assertSame('مقدّم', $lv->fresh()->status, 'بقيت الحالة على غير المعتمد');
    }

    /** (٤) خصمان على نسختين قديمتين لا يضيّعان أحدهما */
    public function test_two_stale_reads_do_not_lose_a_deduction(): void
    {
        $this->seedCore();
        $emp = $this->emp(20);

        // معالجان قرآ رصيد الموظف نفسه (٢٠) قبل أن يخصم أيٌّ منهما
        $a = Employee::find($emp->id);
        $b = Employee::find($emp->id);

        $this->applyBalance($a, -5);
        $this->applyBalance($b, -3);

        $this->assertEquals(12.0, (float) $emp->fresh()->leave_bal,
            'ضاع أحد الخصمين — اقرأ-ثم-اكتب بلا قفل صفّيّ');
    }
}
