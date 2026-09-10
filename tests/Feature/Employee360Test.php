<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use App\Support\Custody;
use App\Support\Employee360;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الموظف 360** (الكيان 360 · §6–18/§93) — ترقيةُ الملفّ الشامل القائم: شريطُ نظرةٍ منطَّق،
 * وتاريخُ محطةٍ/عهدةٍ، ونشاطٌ، وروابطُ راسِل/العلاقات — وحجبُ المالية بلا صلاحيّتها (§14/§70).
 */
class Employee360Test extends TestCase
{
    private function employee(?string $companyId = null): array
    {
        $user = User::create(['name' => 'ريم ' . Str::random(3), 'email' => Str::random(6) . '@t.local',
            'password' => 'Secret!2026x', 'role_id' => $this->owner->role_id, 'status' => 'نشط',
            'password_changed_at' => now(), 'companies' => $companyId ? [$companyId] : []]);
        $emp = Employee::create(['name' => $user->name, 'user_id' => $user->id,
            'company_id' => $companyId, 'status' => 'نشط', 'title' => 'مهندسة']);

        return [$emp, $user];
    }

    private function client(): User
    {
        $role = Role::create(['name' => 'عميل', 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'عميل', 'email' => 'cl@t.local', 'account_type' => 'client',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    public function test_profile_shows_overview_and_actions(): void
    {
        $this->seedCore();
        [$emp, $user] = $this->employee();
        $s = Station::create(['status' => 'متاحة', 'current_employee_id' => $user->id]);

        $this->actingAs($this->owner)->get(route('portal.employee', $emp->id))->assertOk()
            ->assertSee('المحطةُ الحاليّة')       // شريطُ النظرة (§6)
            ->assertSee('💬 راسِل')               // زرُّ DM (§16)
            ->assertSee('🕸️ العلاقات');           // زرُّ الرسم (§56)
    }

    public function test_station_and_custody_history_appear(): void
    {
        $this->seedCore();
        [$emp, $user] = $this->employee();
        $this->actingAs($this->owner);
        $s = Station::create(['status' => 'متاحة']);
        \App\Models\StationAssignment::create(['station_id' => $s->id, 'user_id' => $user->id,
            'action' => 'assign', 'at' => now(), 'by_id' => $this->owner->id]);
        $a = Asset::create(['name' => 'حاسوب', 'type' => 'laptop', 'status' => 'نشط']);
        Custody::move($a, 'تسليم', $user->id, now()->toDateString());

        $e360 = new Employee360;
        $this->assertTrue($e360->stationHistory($emp, $this->owner)->isNotEmpty(), 'تاريخُ المحطات §9 فارغ');
        $this->assertTrue($e360->custodyHistory($emp, $this->owner)->isNotEmpty(), 'تاريخُ العهدة §11 فارغ');
        $this->assertTrue($e360->activity($emp, $this->owner)->isNotEmpty(), 'النشاط §17 فارغ');
    }

    /** §14/§70: الرصيدُ الماليُّ لا يظهر لمن لا يملك صلاحيّةَ العهدة ولو رأى الملفّ */
    public function test_financial_balance_hidden_without_custody_permission(): void
    {
        $this->seedCore();
        [$emp] = $this->employee();

        // قارئٌ يملك hr:v لكن لا custody:v
        $role = Role::create(['name' => 'HR بلا مالية', 'scope' => 'all', 'flags' => [],
            'matrix' => ['hr' => ['v' => 1], 'stations' => ['v' => 1], 'assets' => ['v' => 1]]]);
        $reader = User::create(['name' => 'HR', 'email' => 'hr@t.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $ov = (new Employee360)->overview($emp, $reader);
        $this->assertNull($ov['balance'], 'رصيدُ العهدة ظهر لمن لا يملك صلاحيّةَ العهدة');

        // والمالكُ (custody:v) يراه (رقمٌ لا null)
        $ovOwner = (new Employee360)->overview($emp, $this->owner);
        $this->assertNotNull($ovOwner['balance']);
    }

    public function test_overview_counts_are_permission_scoped(): void
    {
        $this->seedCore();
        [$emp, $user] = $this->employee();
        // قارئٌ بلا صلاحيّة مشاريع/أصول: عدّاداتُها null (§74 غائبٌ لا صفر)
        $role = Role::create(['name' => 'ضيّق', 'scope' => 'all', 'flags' => [], 'matrix' => ['hr' => ['v' => 1]]]);
        $reader = User::create(['name' => 'ضيّق', 'email' => 'n@t.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $ov = (new Employee360)->overview($emp, $reader);
        $this->assertNull($ov['assets']);
        $this->assertNull($ov['projects']);
        $this->assertNull($ov['endpoints']);
    }

    public function test_client_cannot_reach_employee_360(): void
    {
        $this->seedCore();
        [$emp] = $this->employee();
        // العميلُ يبلغ portal.* لكن يُردّ بلا hr:v (٤٠٣ — حجبٌ صلب)
        $resp = $this->actingAs($this->client())->get(route('portal.employee', $emp->id));
        $this->assertContains($resp->status(), [403, 404], 'العميلُ بلغ الملفَّ الداخليَّ للموظف');
    }

    public function test_cross_company_employee_is_404(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'أ']);
        $coB = Company::create(['name_ar' => 'ب']);
        [$empB] = $this->employee($coB->id);
        $role = Role::create(['name' => 'مقيَّد', 'scope' => 'all', 'flags' => [],
            'matrix' => ['hr' => ['v' => 1]]]);
        $u = User::create(['name' => 'مقيَّد', 'email' => 'r@t.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(), 'companies' => [$coA->id]]);

        $this->actingAs($u)->get(route('portal.employee', $empB->id))->assertNotFound();
    }
}
