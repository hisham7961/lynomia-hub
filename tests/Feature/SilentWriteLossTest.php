<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ٤: **ما يُمحى أو يسقط بلا كلمة.**
 *
 * أربعةُ مساراتٍ تُبلّغ نجاحاً بينما البيانات ناقصةٌ أو ممحوّة — وهذا أخطرُ من
 * الخطأ الصاخب: لا أحد يذهب ليتحقّق.
 *
 *  ١) `_field.blade.php:68` — حقلُ `tags` يُرسَم من `$raw` بشرط `is_string`،
 *     والكاست `'tags' => 'array'` يجعلها **مصفوفة** — فالمربّع يُرسَم فارغاً
 *     ويمحو الوسومَ عند أيّ حفظ، في ٢٨ وحدة، بنسبة ١٠٠٪.
 *
 *  ٢) `ModuleController:249` — لا نظيرَ لـ`inheritCompany` يورّث المشروع:
 *     مستخدمُ `scope='proj'` يُنشئ سجلاً في وحدةٍ بلا حقلِ مشروعٍ في نموذجها،
 *     فيُكتب `project_id = NULL` ثمّ يُقصيه `hub_scope` فوراً — يتيمٌ لا يراه
 *     منشئُه (٤٠٤) ولا يظهر في قائمته.
 *
 *  ٣) `LeaveRequest:116` — `$syncBalance` تخصم مرّةً واحدة عبر
 *     `meta['balance_deducted']` ولا تصالح `days` بعدها أبداً: تمديدُ إجازةٍ
 *     معتمدة يلتفّ على سقف الرصيد، وتقليصُها يُبقي الخصمَ الزائد.
 *
 *  ٤) `PayrollController:88` — `whereNotIn('status', [...])` يُقيَّم
 *     `NULL NOT IN (…)` = **مجهول** فيُسقط الموظفَ صامتاً على المحرّكين معاً:
 *     راتبٌ لا يُصرَف ولا رسالةَ تقول لماذا.
 */
class SilentWriteLossTest extends TestCase
{
    /* ── ١) الوسوم تنجو من التعديل ── */

    public function test_tags_survive_an_edit_that_does_not_touch_them(): void
    {
        $this->seedCore();

        $c = \App\Models\Client::create(['name' => 'عميلٌ موسوم', 'tags' => ['ذهبي', 'الكويت']]);
        $this->assertSame(['ذهبي', 'الكويت'], (array) $c->fresh()->tags, 'خطُّ الأساس: الوسوم مكتوبة');

        // شاشةُ التعديل يجب أن تعرض الوسوم القائمة — وإلا مُحيت بمجرّد الحفظ
        $html = $this->actingAs($this->owner)->get('/m/clients/' . $c->id . '/edit')->assertOk()->getContent();
        $this->assertStringContainsString('ذهبي', $html,
            'مربّعُ الوسوم يُرسَم فارغاً — فأيُّ حفظٍ يمحو وسومَ السجل في ٢٨ وحدة');

        // ثمّ حفظٌ يغيّر الهاتف وحده كما يفعل المستخدم من الشاشة نفسها
        $this->actingAs($this->owner)->put('/m/clients/' . $c->id, [
            'name' => 'عميلٌ موسوم', 'phone' => '99887766',
            'tags' => 'ذهبي، الكويت',
        ]);

        $this->assertSame(['ذهبي', 'الكويت'], (array) $c->fresh()->tags,
            'مُحيت الوسوم بحفظٍ لم يقصدها');
    }

    /** ومن لا يرسل الحقل إطلاقاً (API/نموذجٌ جزئي) لا تُمحى وسومُه */
    public function test_tags_are_not_wiped_when_the_key_is_absent(): void
    {
        $this->seedCore();
        $c = \App\Models\Client::create(['name' => 'عميلٌ آخر', 'tags' => ['فضي']]);

        $this->actingAs($this->owner)->put('/m/clients/' . $c->id, [
            'name' => 'عميلٌ آخر', 'phone' => '11223344',
        ]);

        $this->assertSame(['فضي'], (array) $c->fresh()->tags,
            'حفظٌ بلا مفتاح tags محا الوسوم — الغيابُ ليس تفريغاً');
    }

    /* ── ٢) لا سجلَّ يتيماً على منشئه ── */

    public function test_a_project_scoped_user_can_see_what_they_just_created(): void
    {
        $this->seedCore();

        $proj = \App\Models\Project::create(['name' => 'مشروعُ المقاول', 'status' => 'نشط']);
        $role = \App\Models\Role::create(['name' => 'مديرُ مشاريع', 'scope' => 'proj', 'flags' => [],
            'matrix' => collect(array_keys(config('hub.modules')))
                ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all()]);
        $u = \App\Models\User::create(['name' => 'مقاول', 'email' => 'pm@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        $proj->forceFill(['manager_id' => $u->id])->save();

        $this->actingAs($u)->post('/m/kb', ['title' => 'مقالةُ المعرفة', 'body' => 'محتوى']);

        $row = \App\Models\KbArticle::where('title', 'مقالةُ المعرفة')->first();
        $this->assertNotNull($row, 'لم يُكتب السجل أصلاً');
        $this->actingAs($u)->get('/m/kb/' . $row->id)->assertOk();
        $this->assertStringContainsString('مقالةُ المعرفة',
            $this->actingAs($u)->get('/m/kb')->assertOk()->getContent(),
            'السجلُّ الذي أنشأه المستخدم لتوّه اختفى من قائمته — يتيمٌ بلا مشروع');
    }

    /* ── ٣) رصيدُ الإجازة يُصالَح مع الأيام ── */

    private function employeeWithBalance(float $bal = 20): Employee
    {
        return Employee::create(['name' => 'موظفُ الرصيد', 'status' => 'نشط', 'leave_bal' => $bal]);
    }

    public function test_shrinking_an_approved_leave_returns_the_extra_days(): void
    {
        $this->seedCore();
        $emp = $this->employeeWithBalance(20);

        $lr = LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية', 'status' => 'معتمد',
            'date_from' => now()->toDateString(), 'date_to' => now()->addDays(9)->toDateString(), 'days' => 10]);
        $this->assertSame(10.0, (float) $emp->fresh()->leave_bal, 'خطُّ الأساس: خُصمت عشرة');

        $lr->forceFill(['date_to' => now()->toDateString(), 'days' => 1])->save();

        $this->assertSame(19.0, (float) $emp->fresh()->leave_bal,
            'تقليصُ إجازةٍ معتمدة أبقى الخصمَ الزائد — تسعةُ أيامٍ ضاعت من رصيد الموظف');
    }

    public function test_extending_an_approved_leave_deducts_the_difference(): void
    {
        $this->seedCore();
        $emp = $this->employeeWithBalance(20);

        $lr = LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية', 'status' => 'معتمد',
            'date_from' => now()->toDateString(), 'date_to' => now()->toDateString(), 'days' => 1]);
        $this->assertSame(19.0, (float) $emp->fresh()->leave_bal);

        // تمديدٌ إلى ما دون السقف: الفارقُ يُخصم
        $lr->forceFill(['date_to' => now()->addDays(4)->toDateString(), 'days' => 5])->save();

        $this->assertSame(15.0, (float) $emp->fresh()->leave_bal,
            'تمديدُ إجازةٍ معتمدة لم يُخصم فارقَه — التفافٌ على سقف الرصيد بالتمديد');
    }

    public function test_extending_beyond_the_balance_is_refused(): void
    {
        $this->seedCore();
        $emp = $this->employeeWithBalance(5);

        $lr = LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية', 'status' => 'معتمد',
            'date_from' => now()->toDateString(), 'date_to' => now()->toDateString(), 'days' => 1]);

        try {
            $lr->forceFill(['date_to' => now()->addDays(99)->toDateString(), 'days' => 100])->save();
        } catch (\Illuminate\Validation\ValidationException) {
            // مرفوضٌ بالسقف — وهو المطلوب
        }

        $this->assertGreaterThanOrEqual(0.0, (float) $emp->fresh()->leave_bal,
            'التمديدُ هبط بالرصيد تحت الصفر — السقفُ لا يُفحص على الفارق');
    }

    /* ── ٤) موظفٌ بحالةٍ فارغة لا يسقط من المسيّر ── */

    public function test_an_employee_with_null_status_is_included_in_payroll(): void
    {
        $this->seedCore();

        Employee::create(['name' => 'موظفٌ بحالة', 'status' => 'نشط', 'salary' => 500]);
        $ghost = Employee::create(['name' => 'موظفٌ بلا حالة', 'salary' => 1000]);
        $ghost->forceFill(['status' => null])->saveQuietly();

        $run = PayrollRun::create(['name' => 'مسيّرُ الاختبار', 'month' => now()->format('Y-m'), 'status' => 'مسودة']);
        $this->actingAs($this->owner)->post('/payroll/' . $run->id . '/act', ['do' => 'generate']);

        $this->assertSame(1, \App\Models\PayrollLine::where('run_id', $run->id)
            ->where('emp_id', $ghost->id)->count(),
            'موظفٌ بحالةٍ فارغة سقط من المسيّر صامتاً — NULL NOT IN يُقيَّم «مجهولاً» لا «صحيحاً»');
        $this->assertSame(1500.0, (float) $run->fresh()->total,
            'إجماليُّ المسيّر لا يشمل من سقط — راتبٌ لا يُصرَف ولا رسالةَ تقول لماذا');
    }
}
