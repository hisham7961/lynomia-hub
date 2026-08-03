<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use Tests\TestCase;

/**
 * سلامة رصيد الإجازات — الموجة الرابعة:
 * (١) حذف إجازةٍ معتمدة كان لا يعيد الرصيد أبداً (المزامنة على created/updated فقط،
 *     والحذف الناعم لا يطلق updated) — فيبقى الخصم يتيماً، وتُقبل إجازةٌ جديدة فوق
 *     التواريخ نفسها (السلة خارج حارس التداخل) فيُخصم الغياب الواحد مرتين.
 * (٢) الخصم كان أعمى عن النوع: «عمل عن بعد» و«شهادة راتب» و«سلفة» تُخصم من رصيد
 *     الإجازات رغم أنها ليست غياباً — تآكلٌ صامت. الآن يُخصم نوعُ الإجازة وحده.
 * (٣) طلبٌ بلا «إلى» كان يفلت من الاشتقاق وحارس التداخل كليّاً.
 */
class LeaveBalanceIntegrityTest extends TestCase
{
    protected function emp(float $bal = 20): Employee
    {
        return Employee::create(['name' => 'موظف ' . uniqid(), 'status' => 'نشط', 'leave_bal' => $bal]);
    }

    protected function approvedAnnual(Employee $emp, string $from = '2026-06-01', string $to = '2026-06-14'): LeaveRequest
    {
        $lv = LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية', 'status' => 'مقدّم',
            'date_from' => $from, 'date_to' => $to]);
        $lv->update(['status' => 'معتمد']);

        return $lv->fresh();
    }

    /** (١) حذف المعتمدة يعيد رصيدها — لا يبقى الخصم يتيماً */
    public function test_deleting_an_approved_leave_restores_balance(): void
    {
        $this->seedCore();
        $emp = $this->emp(20);
        $lv = $this->approvedAnnual($emp);                       // ١٠ أيام عمل
        $this->assertEquals(10.0, (float) $emp->fresh()->leave_bal, 'الاعتماد يخصم');

        $lv->delete();                                            // حذف ناعم
        $this->assertEquals(20.0, (float) $emp->fresh()->leave_bal,
            'حذف إجازةٍ معتمدة لم يُعِد رصيدها — خصمٌ يتيمٌ بلا سجل');
    }

    /** (١) استعادة المحذوفة من السلة تعيد الخصم — تناظرٌ سليم */
    public function test_restoring_a_trashed_approved_leave_rededucts(): void
    {
        $this->seedCore();
        $emp = $this->emp(20);
        $lv = $this->approvedAnnual($emp);
        $lv->delete();
        $this->assertEquals(20.0, (float) $emp->fresh()->leave_bal);

        $lv->restore();
        $this->assertEquals(10.0, (float) $emp->fresh()->leave_bal,
            'استعادةُ إجازةٍ معتمدة من السلة لم تُعِد خصمها');
    }

    /** (١) بعد الحذف، إجازةٌ جديدة بنفس التواريخ لا تُخصم مرتين */
    public function test_deleting_then_new_leave_same_dates_does_not_double_deduct(): void
    {
        $this->seedCore();
        $emp = $this->emp(20);
        $lv = $this->approvedAnnual($emp);
        $lv->delete();                                            // الرصيد عاد ٢٠

        // تصحيح الإدخال: إجازةٌ جديدة بنفس التواريخ تُعتمد
        $this->approvedAnnual($emp);
        $this->assertEquals(10.0, (float) $emp->fresh()->leave_bal,
            'خُصم الغياب الواحد مرتين — الرصيد ٠ بدل ١٠');
    }

    /** (٢) الأنواع غير-الإجازية لا تُنقص رصيد الإجازات */
    public function test_non_leave_types_do_not_deduct_balance(): void
    {
        $this->seedCore();
        foreach (['عمل عن بعد', 'شهادة راتب', 'سلفة', 'إذن خروج'] as $type) {
            $emp = $this->emp(20);
            $lv = LeaveRequest::create(['emp_id' => $emp->id, 'type' => $type, 'status' => 'مقدّم',
                'date_from' => '2026-06-01', 'date_to' => '2026-06-04']);
            $lv->update(['status' => 'معتمد']);
            $this->assertEquals(20.0, (float) $emp->fresh()->leave_bal,
                "«{$type}» خُصم من رصيد الإجازات وهو ليس غياباً");
        }
    }

    /** (٢/٣) طلبٌ غير غيابيّ لا يحجز التواريخ فيمنع إجازةً حقيقية */
    public function test_a_document_request_does_not_block_a_real_leave(): void
    {
        $this->seedCore();
        $emp = $this->emp(20);
        LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'شهادة راتب', 'status' => 'معتمد',
            'date_from' => '2026-09-01', 'date_to' => '2026-09-01']);

        // إجازةٌ حقيقية بنفس اليوم تُقبل — «شهادة راتب» ليست حجزاً
        $lv = LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية', 'status' => 'مقدّم',
            'date_from' => '2026-09-01', 'date_to' => '2026-09-01']);
        $this->assertNotNull($lv->fresh(), 'طلبُ وثيقةٍ حجب إجازةً حقيقية بلا سبب');
    }

    /** (٣) طلبٌ بيومٍ واحد (بلا «إلى») يشتق أيامه ويخضع للتداخل */
    public function test_single_date_request_derives_days_and_is_guarded(): void
    {
        $this->seedCore();
        $emp = $this->emp(20);

        // 2026-06-01 اثنين (يوم عمل) — بلا «إلى»
        $lv = LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية', 'status' => 'مقدّم',
            'date_from' => '2026-06-01']);
        $this->assertEquals(1.0, (float) $lv->fresh()->days, 'يومٌ واحد لم تُشتق أيامه');

        // طلبٌ آخر بنفس اليوم الواحد يتداخل فيُرفض
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة طارئة', 'status' => 'مقدّم',
            'date_from' => '2026-06-01']);
    }
}
