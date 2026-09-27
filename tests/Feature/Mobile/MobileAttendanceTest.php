<?php

namespace Tests\Feature\Mobile;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الحضورُ والانصراف على الجوال** (خطّةُ التطبيق · 3.1) — السكّةُ الويبية نفسُها (`Workday`).
 *
 * يُثبت: (١) الصفُّ صفُّ صاحبِ الجلسة وحدَه ولا يُكرَّر، (٢) عدمُ الأثر بـIdempotency-Key،
 * (٣) الموقعُ لا يُقبل بلا موافقةٍ صريحة ولا يُحفظ إلا حين يُفعِّله الخادم، (٤) المشروعُ
 * خارجَ نطاقِ الموظّف لا يُربَط، (٥) حسابُ العميل ومن بلا ملفِّ موظّفٍ يُردّان بكودٍ آليّ.
 */
class MobileAttendanceTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function staff(string $scope = 'all', string $name = 'سارة'): array
    {
        $role = Role::create(['name' => 'موظّف' . Str::random(4), 'scope' => $scope,
            'flags' => [], 'matrix' => ['tasks' => ['v' => 1], 'projects' => ['v' => 1]]]);
        $u = User::create(['name' => $name, 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => $name, 'status' => 'نشط', 'user_id' => $u->id]);

        return [$u, $e];
    }

    private function h(User $u): array
    {
        return $this->bearer($this->mobileLogin($u)['access_token']);
    }

    public function test_check_in_then_out_writes_my_row_once_through_workday(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_start', '23:59');
        [$u, $e] = $this->staff();
        [$other, $oe] = $this->staff('all', 'غيرُها');
        $h = $this->h($u);

        $this->withHeaders($h)->getJson('/api/mobile/v1/attendance/today')->assertOk()
            ->assertJsonPath('data.state', 'not_checked_in')->assertJsonPath('data.can.check_in', true);

        $res = $this->withHeaders($h)->postJson('/api/mobile/v1/attendance/check-in', ['mode' => 'عن بعد'])->assertOk();
        $this->assertSame('عن بعد', $res->json('data.attendance.mode'));
        $this->assertSame('عمل عن بعد', $res->json('data.attendance.status'));
        $this->assertArrayNotHasKey('meta', $res->json('data.attendance'), 'لا عنوانَ ولا جهازَ في البطاقة');

        // الثاني يُردّ بكودٍ آليّ ولا يُكرّر صفّاً
        $this->withHeaders($h)->postJson('/api/mobile/v1/attendance/check-in', [])
            ->assertStatus(422)->assertJsonPath('code', 'BUSINESS_RULE_VIOLATION')
            ->assertJsonPath('details.reason', 'already_checked_in');
        $this->assertSame(1, Attendance::where('emp_id', $e->id)->count());
        $this->assertSame(0, Attendance::where('emp_id', $oe->id)->count(), 'صفُّ غيري لم يُمسّ');

        $this->withHeaders($h)->getJson('/api/mobile/v1/attendance/today')->assertOk()
            ->assertJsonPath('data.state', 'checked_in')->assertJsonPath('data.can.check_out', true);

        $out = $this->withHeaders($h)->postJson('/api/mobile/v1/attendance/check-out')->assertOk();
        $this->assertNotNull($out->json('data.attendance.time_out'));
        $this->assertIsNumeric($out->json('data.attendance.hours'));

        $this->withHeaders($h)->postJson('/api/mobile/v1/attendance/check-out')
            ->assertStatus(422)->assertJsonPath('details.reason', 'already_checked_out');
    }

    public function test_check_out_before_check_in_is_a_coded_refusal(): void
    {
        $this->seedCore();
        [$u] = $this->staff();

        $this->withHeaders($this->h($u))->postJson('/api/mobile/v1/attendance/check-out')
            ->assertStatus(422)->assertJsonPath('details.reason', 'not_checked_in');
    }

    public function test_idempotency_key_replays_instead_of_refusing(): void
    {
        $this->seedCore();
        [$u, $e] = $this->staff();
        $h = $this->h($u) + ['Idempotency-Key' => 'ci-' . Str::random(8)];

        $a = $this->withHeaders($h)->postJson('/api/mobile/v1/attendance/check-in', ['mode' => 'مكتب'])->assertOk();
        $b = $this->withHeaders($h)->postJson('/api/mobile/v1/attendance/check-in', ['mode' => 'مكتب'])->assertOk();

        $b->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($a->json('data.attendance.id'), $b->json('data.attendance.id'));
        $this->assertSame(1, Attendance::where('emp_id', $e->id)->count());
    }

    public function test_location_requires_explicit_consent_and_the_server_switch(): void
    {
        $this->seedCore();
        [$u, $e] = $this->staff();
        $h = $this->h($u);

        // بلا موافقة: رفضٌ صريحٌ لا حفظٌ صامت ولا تجاهل
        $this->withHeaders($h)->postJson('/api/mobile/v1/attendance/check-in', ['lat' => 29.37, 'lng' => 47.98])
            ->assertStatus(422)->assertJsonPath('details.reason', 'location_consent_required');
        $this->assertSame(0, Attendance::where('emp_id', $e->id)->count());

        // بموافقةٍ والخادمُ لا يسجّل المواقع (work.geo=0): يمضي الحضورُ بلا موقع
        $this->hubSetting('work.geo', '0');
        $res = $this->withHeaders($h)->postJson('/api/mobile/v1/attendance/check-in',
            ['lat' => 29.37, 'lng' => 47.98, 'accuracy' => 12, 'location_consent' => true])->assertOk();
        $this->assertFalse($res->json('data.location_recorded'));
        $this->assertNull(data_get(Attendance::where('emp_id', $e->id)->orderBy('id')->first()->meta, 'checkin.geo'));
    }

    public function test_location_is_stamped_when_consented_and_enabled(): void
    {
        $this->seedCore();
        $this->hubSetting('work.geo', '1');
        [$u, $e] = $this->staff();

        $res = $this->withHeaders($this->h($u))->postJson('/api/mobile/v1/attendance/check-in',
            ['lat' => 29.3759, 'lng' => 47.9774, 'accuracy' => 8.4, 'location_consent' => true])->assertOk();

        $this->assertTrue($res->json('data.location_recorded'));
        $this->assertSame('29.375900,47.977400±8m',
            data_get(Attendance::where('emp_id', $e->id)->orderBy('id')->first()->meta, 'checkin.geo'));
    }

    public function test_a_project_outside_my_scope_is_not_linked(): void
    {
        $this->seedCore();
        [$u, $e] = $this->staff('proj');
        $foreign = Project::create(['name' => 'مشروعٌ لا أراه']);

        $this->withHeaders($this->h($u))->postJson('/api/mobile/v1/attendance/check-in',
            ['project_id' => $foreign->id])->assertOk()->assertJsonPath('data.attendance.project_id', null);
        $this->assertNull(Attendance::where('emp_id', $e->id)->orderBy('id')->first()->project_id);
    }

    public function test_no_employee_profile_and_client_accounts_are_refused(): void
    {
        $this->seedCore();
        $noEmp = User::create(['name' => 'بلا ملف', 'email' => 'noemp@test.local', 'password' => 'Secret!2026x',
            'role_id' => $this->employee->role_id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $this->withHeaders($this->h($noEmp))->postJson('/api/mobile/v1/attendance/check-in')
            ->assertStatus(422)->assertJsonPath('details.reason', 'no_employee_profile');

        $client = Client::create(['name' => 'عميل']);
        $cu = User::create(['name' => 'عميل', 'email' => 'c@ext.local', 'password' => 'Secret!2026x',
            'role_id' => $this->employee->role_id, 'status' => 'نشط', 'password_changed_at' => now(),
            'account_type' => 'client']);
        \App\Models\ClientMembership::create(['client_id' => $client->id, 'user_id' => $cu->id,
            'role' => 'lead', 'status' => 'active', 'activated_at' => now()]);
        Employee::create(['name' => 'عميل', 'status' => 'نشط', 'user_id' => $cu->id]);
        $ch = $this->h($cu);
        $this->withHeaders($ch)->getJson('/api/mobile/v1/attendance/today')->assertStatus(404);
        $this->withHeaders($ch)->postJson('/api/mobile/v1/attendance/check-in')->assertStatus(404);
        $this->assertSame(0, Attendance::count());
    }
}
