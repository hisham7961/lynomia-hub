<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * الموجة الرابعة — استيلاء حساب من مستخدم hr.e:
 * align() كان يدهس name/email الحساب المربوط بلا حارس Staff::mayTouch (بينما link()
 * يحرسه)، والمُمكِّن أن حقل userId (user_id) يُكتب خاماً من نموذج التعديل العام بلا
 * accountTaken/mayTouch — تحقّقه الوحيد exists:users. فمستخدم hr (تعديل ملفات، لا
 * ملكية ولا إدارة مستخدمين) يعيد توجيه user_id لملفٍ نحو حساب المالك ثم يدهس بريده
 * ببريدٍ يسيطر عليه → استعادةٌ عبر «نسيت كلمة المرور» → استيلاء كامل.
 *
 * الحارس الآن في مكانين: align() يفرض mayTouch، والنموذج Employee يفرض
 * mayTouch+accountTaken على أي تغيير لـuser_id من أي مسار — آخرُ خطِّ دفاع.
 */
class StaffAccountTakeoverRound4Test extends TestCase
{
    /** مستخدم موارد بشرية: تعديل ملفات فقط — لا ملكية ولا علَم users */
    protected function hrEditor(): User
    {
        $hr = Role::create(['name' => 'موارد بشرية', 'scope' => 'all', 'flags' => [],
            'matrix' => ['hr' => ['v' => 1, 'a' => 1, 'e' => 1]]]);

        return User::create(['name' => 'مسؤول ملفات', 'email' => 'hr-attacker@test.local',
            'password' => 'Secret!2026x', 'role_id' => $hr->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /** إعادة توجيه user_id لحساب المالك من النموذج العام تُرفض */
    public function test_hr_editor_cannot_redirect_user_id_to_owner_via_general_form(): void
    {
        $this->seedCore();
        $hr = $this->hrEditor();
        // ملفٌ بلا حساب أُنشئ خارج أي جلسة (لا حارس على البذر)
        $emp = Employee::create(['name' => 'ضحية', 'email' => 'victim@test.local', 'status' => 'نشط']);

        // hr.e يحاول ربط الملف بحساب المالك عبر PUT /m/hr/{id}
        $this->actingAs($hr)->put("/m/hr/{$emp->id}", [
            'name' => 'ضحية', 'email' => 'victim@test.local', 'status' => 'نشط',
            'userId' => $this->owner->id,
        ]);

        $this->assertNotSame($this->owner->id, $emp->fresh()->user_id,
            'مستخدم hr.e أعاد توجيه user_id نحو حساب المالك من النموذج العام');
    }

    /** التوحيد على حساب المالك من hr.e يُرفض ولا يدهس بريده */
    public function test_hr_editor_cannot_hijack_owner_account_via_align(): void
    {
        $this->seedCore();
        $hr = $this->hrEditor();
        // ملفٌ مربوطٌ بحساب المالك (رُبط خارج أي جلسة تحاكي حالةً متراكمة)،
        // وبريدُه بريدٌ يسيطر عليه المهاجم
        $emp = Employee::create(['name' => 'واجهة', 'email' => 'attacker@evil.tld',
            'status' => 'نشط', 'user_id' => $this->owner->id]);

        $this->actingAs($hr)->post("/staff/{$emp->id}/align")->assertForbidden();

        $this->assertSame('owner@test.local', $this->owner->fresh()->email,
            'دُهس بريدُ المالك عبر align — استُولي على حسابه');
    }

    /** لا إفراط في المنع: hr.e يوحّد حساباً عاديّاً كالمعتاد */
    public function test_hr_editor_can_still_align_a_normal_account(): void
    {
        $this->seedCore();
        $hr = $this->hrEditor();
        $u = User::create(['name' => 'الاسم القديم', 'email' => 'plain@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $emp = Employee::create(['name' => 'الاسم الصحيح', 'email' => 'plain@test.local',
            'status' => 'نشط', 'user_id' => $u->id]);

        $this->actingAs($hr)->post("/staff/{$emp->id}/align")->assertRedirect();
        $this->assertSame('الاسم الصحيح', $u->fresh()->name, 'حُرِم التوحيد المشروع لحسابٍ عاديّ');
    }
}
