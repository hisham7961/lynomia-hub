<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\TrackSession;
use App\Models\User;
use Tests\TestCase;

/**
 * عزلُ الشركات على مسارات GPS الميدانية (تدقيقٌ أمنيّ): مشرفٌ محصورٌ بشركةٍ
 * لا يرى جلساتِ مسارِ شركةٍ أخرى — لا في القائمة ولا بالرابط المباشر.
 */
class FieldTrackIsolationTest extends TestCase
{
    /** مشرفٌ ميدانيّ محصورٌ بشركة A (hr.v + monitor) */
    private function supervisor(Company $a): User
    {
        $role = Role::create(['name' => 'مشرف ميدانيّ', 'scope' => 'company',
            'flags' => ['monitor' => 1], 'matrix' => ['hr' => ['v' => 1]]]);

        return User::create(['name' => 'مشرف', 'email' => 'sup@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$a->id]]);
    }

    public function test_supervisor_cannot_open_another_companys_track_route(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ']);
        $b = Company::create(['name_ar' => 'شركة ب']);
        $sup = $this->supervisor($a);

        $emp = Employee::create(['name' => 'مندوب ب', 'company_id' => $b->id]);
        $sB = TrackSession::create(['emp_id' => $emp->id, 'field_day' => now()->toDateString(),
            'status' => 'منتهية', 'company_id' => $b->id, 'started_at' => now()->subHour()]);

        // الرابط المباشر لجلسة شركةٍ أخرى = 404 (عزلٌ صارم)
        $this->actingAs($sup)->get('/field/route/' . $sB->id)->assertNotFound();
    }

    public function test_owner_can_open_any_track_route(): void
    {
        $this->seedCore();
        $b = Company::create(['name_ar' => 'شركة ب']);
        $emp = Employee::create(['name' => 'مندوب', 'company_id' => $b->id]);
        $s = TrackSession::create(['emp_id' => $emp->id, 'field_day' => now()->toDateString(),
            'status' => 'منتهية', 'company_id' => $b->id, 'started_at' => now()->subHour()]);

        $this->actingAs($this->owner)->get('/field/route/' . $s->id)->assertOk();
    }

    public function test_session_list_excludes_other_company_sessions(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ']);
        $b = Company::create(['name_ar' => 'شركة ب']);
        $sup = $this->supervisor($a);

        $empA = Employee::create(['name' => 'مندوب أ', 'company_id' => $a->id]);
        $empB = Employee::create(['name' => 'مندوب ب', 'company_id' => $b->id]);
        TrackSession::create(['emp_id' => $empA->id, 'field_day' => now()->toDateString(), 'status' => 'منتهية', 'company_id' => $a->id, 'started_at' => now()->subHour()]);
        $sB = TrackSession::create(['emp_id' => $empB->id, 'field_day' => now()->toDateString(), 'status' => 'منتهية', 'company_id' => $b->id, 'started_at' => now()->subHour()]);

        $res = $this->actingAs($sup)->get('/field/sessions?fresh=1')->assertOk();
        // جلسةُ شركة أ تظهر، وجلسةُ شركة ب لا (المعرّف لا يظهر في القائمة)
        $res->assertDontSee($sB->id);
    }
}
