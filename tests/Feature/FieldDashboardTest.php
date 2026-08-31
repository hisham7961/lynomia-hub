<?php

namespace Tests\Feature;

use App\Models\Cycle;
use App\Models\Employee;
use App\Models\Hcp;
use App\Models\Role;
use App\Models\User;
use App\Models\Visit;
use Tests\TestCase;

/** لوحة المشرف الميدانيّ — مخطط مقابل فعلي وتغطية الدورات ونشاط المندوبين. */
class FieldDashboardTest extends TestCase
{
    public function test_supervisor_dashboard_computes_plan_vs_actual(): void
    {
        $this->seedCore();
        $cycle = Cycle::create(['name' => 'دورة', 'status' => 'نشط', 'target_visits' => 4]);
        $hcp = Hcp::create(['name' => 'د. أ', 'status' => 'نشط']);
        $emp = Employee::create(['name' => 'مندوب', 'status' => 'نشط', 'field_role' => 'مندوب طبي']);
        $today = now()->toDateString();
        Visit::create(['hcp_id' => $hcp->id, 'cycle_id' => $cycle->id, 'emp_id' => $emp->id, 'status' => 'تمت', 'planned_date' => $today]);
        Visit::create(['hcp_id' => $hcp->id, 'cycle_id' => $cycle->id, 'emp_id' => $emp->id, 'status' => 'مخطط', 'planned_date' => $today]);
        Visit::create(['hcp_id' => $hcp->id, 'cycle_id' => $cycle->id, 'emp_id' => $emp->id, 'status' => 'فائتة', 'planned_date' => $today]);

        $this->actingAs($this->owner)->get('/field')->assertOk()
            ->assertSee('لوحة المشرف الميدانيّ')
            ->assertSee('نسبة الالتزام');
    }

    public function test_dashboard_is_gated_to_field_supervisors(): void
    {
        $this->seedCore();
        // موظفٌ عاديّ بلا مراقبة → 403
        $role = Role::create(['name' => 'بلا إشراف', 'scope' => 'all', 'flags' => [],
            'matrix' => collect(array_keys(config('hub.modules')))->mapWithKeys(fn ($m) => [$m => ['v' => 1]])->all()]);
        $u = User::create(['name' => 'عاديّ', 'email' => 'plain@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $this->actingAs($u)->get('/field')->assertForbidden();
    }
}
