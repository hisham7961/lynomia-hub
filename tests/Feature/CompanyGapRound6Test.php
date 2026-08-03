<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use Tests\TestCase;

/**
 * جولة تدقيق ٦ — سدّ فجوتَي عزل الشركات:
 *  1) المهارات والسياسات وإقراراتها كانت بلا عمود شركة أصلاً — المعزول يرى كل الشركات.
 *  2) عشر وحدات (إجازات، حضور، توظيف، سجلات، حركات مخزون، صيانة، قيود…) لها عمود
 *     شركة بلا حقلٍ معلن — والمعزول بلا شركة نشطة كان ينشئ سجلاً بـ NULL «يختفي» فوراً.
 */
class CompanyGapRound6Test extends TestCase
{
    protected Company $coA;
    protected Company $coB;

    protected function seedTwoCompanies(): void
    {
        $this->seedCore();
        $this->coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $this->coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $this->employee->update(['companies' => [$this->coA->id]]);
    }

    public function test_skills_are_isolated_by_company(): void
    {
        $this->seedTwoCompanies();
        \App\Models\Skill::create(['name' => 'مهارة ألف السرية', 'company_id' => $this->coA->id]);
        \App\Models\Skill::create(['name' => 'مهارة باء السرية', 'company_id' => $this->coB->id]);

        $this->actingAs($this->employee)->get('/m/skills')
            ->assertOk()->assertSee('مهارة ألف السرية')->assertDontSee('مهارة باء السرية');
        // غير المقيد يرى الكل
        $this->actingAs($this->owner)->get('/m/skills')
            ->assertOk()->assertSee('مهارة ألف السرية')->assertSee('مهارة باء السرية');
    }

    public function test_policies_are_isolated_by_company(): void
    {
        $this->seedTwoCompanies();
        \App\Models\Policy::create(['title' => 'سياسة ألف الداخلية', 'ver' => '1', 'body' => 'نص', 'company_id' => $this->coA->id]);
        \App\Models\Policy::create(['title' => 'سياسة باء الداخلية', 'ver' => '1', 'body' => 'نص', 'company_id' => $this->coB->id]);

        $this->actingAs($this->employee)->get('/m/policies')
            ->assertOk()->assertSee('سياسة ألف الداخلية')->assertDontSee('سياسة باء الداخلية');
    }

    public function test_isolated_user_record_is_never_orphaned(): void
    {
        $this->seedTwoCompanies();
        $project = \App\Models\Project::create(['name' => 'مشروع اليتم', 'company_id' => $this->coA->id]);

        // المهام: عمود شركة بلا حقل معلن — معزولٌ بلا شركة نشطة كان ينشئ مهمة
        // بـ NULL «تختفي» من قائمته فوراً (whereIn يُقصي NULL)
        $this->actingAs($this->employee)->post('/m/tasks', [
            'title' => 'مهمة يجب ألا تضيع', 'projectId' => $project->id,
        ]);

        $task = \App\Models\Task::where('title', 'مهمة يجب ألا تضيع')->first();
        $this->assertNotNull($task, 'المهمة لم تُنشأ');
        $this->assertSame((string) $this->coA->id, (string) $task->company_id,
            'مهمة المعزول يجب أن تُنسب لشركته تلقائياً لا أن تبقى بلا شركة');

        // وتظهر في قائمته فعلاً
        $this->actingAs($this->employee)->get('/m/tasks')->assertOk()->assertSee('مهمة يجب ألا تضيع');
    }

    public function test_isolated_user_must_pick_own_company_in_declared_field_modules(): void
    {
        $this->seedTwoCompanies();
        $emp = Employee::create(['name' => 'موظف ألف', 'company_id' => $this->coA->id]);

        // الحقل معلن الآن في الإجازات: المعزول بلا اختيار شركة يُوقَف برسالة واضحة
        $this->actingAs($this->employee)->from('/m/leaves/create')->post('/m/leaves', [
            'empId' => $emp->id, 'type' => 'إجازة سنوية', 'from' => now()->toDateString(),
        ])->assertRedirect('/m/leaves/create')->assertSessionHasErrors('companyId');

        // وباختيار شركته يمرّ ويُنسب صحيحاً
        $this->actingAs($this->employee)->post('/m/leaves', [
            'empId' => $emp->id, 'type' => 'إجازة سنوية', 'from' => now()->toDateString(),
            'companyId' => $this->coA->id,
        ]);
        $lv = \App\Models\LeaveRequest::where('emp_id', $emp->id)->first();
        $this->assertNotNull($lv);
        $this->assertSame((string) $this->coA->id, (string) $lv->company_id);
    }

    public function test_declared_company_field_rejects_foreign_company(): void
    {
        $this->seedTwoCompanies();
        $emp = Employee::create(['name' => 'موظف باء', 'company_id' => $this->coB->id]);

        // الحقل المعلن حديثاً يخضع لحارس الكتابة القائم — شركة أجنبية تُرفض
        $resp = $this->actingAs($this->employee)->from('/m/leaves/create')->post('/m/leaves', [
            'empId' => $emp->id, 'type' => 'إجازة سنوية', 'from' => now()->toDateString(),
            'companyId' => $this->coB->id,
        ]);
        $resp->assertRedirect('/m/leaves/create')->assertSessionHasErrors('companyId');
        $this->assertSame(0, \App\Models\LeaveRequest::count());
    }
}
