<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use Tests\TestCase;

/**
 * **تنطيقُ المراجعِ عند الكتابة** (Permissions 360 · م2 · 10.4 · 12.5).
 *
 * 10.4: حضورُ الموظّفِ لا يُربَطُ بمشروعٍ خارجَ نطاقِه (يُتجاهَل المرجعُ لا يُقبَل خاماً).
 * 12.5: إسنادُ مقعدِ محطةٍ لا يقبلُ مستخدماً من شركةٍ خارجَ نطاقِ القارئِ المعزول.
 */
class Permissions360ScopeIdorTest extends TestCase
{
    private function user(string $email, array $matrix, string $scope = 'all', array $companies = []): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => $scope, 'flags' => [],
            'matrix' => $matrix, 'companies' => $companies ?: null]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => $companies ?: null]);
    }

    /* ═══════════ 10.4 — حضورٌ لا يُربَطُ بمشروعٍ خارجَ النطاق ═══════════ */

    public function test_checkin_drops_out_of_scope_project_reference(): void
    {
        $this->seedCore();
        $out = Project::create(['name' => 'مشروعٌ غيرُ مُسنَد', 'status' => 'نشط']);

        // موظّفٌ محدودُ النطاقِ بمشاريعِ دورِه (بلا إسنادٍ ⇒ كلُّ المشاريعِ خارجَ نطاقِه)
        $u = $this->user('p4a@test.local', ['updates' => ['v' => 1]], scope: 'proj');
        $emp = Employee::create(['name' => 'موظف', 'status' => 'نشط', 'user_id' => $u->id]);

        $res = \App\Support\Workday::checkIn($u, ['project_id' => $out->id, 'mode' => 'مكتب']);
        $this->assertTrue($res['ok'] ?? false, 'الحضورُ سُجِّل');
        $this->assertNull(Attendance::where('emp_id', $emp->id)->first()->project_id,
            'مشروعٌ خارجَ النطاقِ لم يُربَط بالحضور');
    }

    public function test_checkin_keeps_in_scope_project_for_unscoped_user(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع', 'status' => 'نشط']);
        $u = $this->user('p4b@test.local', ['updates' => ['v' => 1]]);   // scope=all
        $emp = Employee::create(['name' => 'موظف٢', 'status' => 'نشط', 'user_id' => $u->id]);

        \App\Support\Workday::checkIn($u, ['project_id' => $p->id, 'mode' => 'مكتب']);
        $this->assertSame($p->id, Attendance::where('emp_id', $emp->id)->first()->project_id,
            'المشروعُ ضمنَ نطاقِ الشامل يبقى مربوطاً');
    }

    /* ═══════════ 12.5 — إسنادُ المحطةِ ضمنَ نطاقِ الشركة ═══════════ */

    public function test_station_assign_rejects_out_of_company_user(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'أ', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'ب', 'status' => 'نشطة']);
        $sA = Station::create(['facility' => 'مبنى أ', 'type' => 'مكتب', 'company_id' => $coA->id]);

        $mgr = $this->user('mgr@test.local', ['stations' => ['v' => 1, 'e' => 1]],
            scope: 'company', companies: [$coA->id]);
        $targetB = $this->user('tb@test.local', ['updates' => ['v' => 1]],
            scope: 'company', companies: [$coB->id]);

        // مُسنَدٌ من شركةِ باء ⇒ يُرفَض (خارجَ نطاقِ القارئ المعزول على ألف)
        $this->actingAs($mgr)->post(route('stations.assign', $sA->id), ['user_id' => $targetB->id])
            ->assertSessionHasErrors('user_id');

        // ومُسنَدٌ من شركةِ ألف ⇒ يُقبَل
        $targetA = $this->user('ta@test.local', ['updates' => ['v' => 1]],
            scope: 'company', companies: [$coA->id]);
        $this->actingAs($mgr)->post(route('stations.assign', $sA->id), ['user_id' => $targetA->id])
            ->assertSessionHasNoErrors();
    }
}
