<?php

namespace Tests\Feature\Mobile;

use App\Models\HubNotification;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkUpdate;
use Tests\TestCase;

/**
 * **مراجعةُ التقارير اليوميّة للفريق على الجوال** (خطّة التطبيق 4.4) — الطابورُ
 * (`ReportReview::reviewableQuery`) والحكمُ (`canReview`) والفعلُ (`act`) التي يستدعيها
 * `ReportsController` الويبيّ حرفاً: مديرُ المشاريع غيرُ الواسع يرى «مشاريعي» افتراضاً و
 * `scope=team` يوسّع؛ لا مراجعةَ لتقرير النفس؛ التنقيحُ بلا ملاحظةٍ ٤٢٢؛ الحقلُ المحجوبُ null؛
 * ومن لا يراجع ٤٠٣، والعميلُ ٤٠٤.
 */
class MobileTeamReportsTest extends TestCase
{
    use InteractsWithMobileAuth;

    private User $mgr;
    private User $worker;
    private Project $p1;
    private Project $p2;

    private function user(string $email, array $matrix, array $fieldRules = []): User
    {
        $role = Role::create(['name' => 'دور ' . $email, 'scope' => 'all', 'flags' => [], 'matrix' => $matrix,
            'field_rules' => $fieldRules]);

        return User::create(['name' => 'مستخدم ' . $email, 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function world(array $mgrFieldRules = []): void
    {
        $this->seedCore();
        $this->mgr = $this->user('mgr@test.local', ['projects' => ['v' => 1], 'updates' => ['v' => 1, 'e' => 1],
            'tasks' => ['v' => 1]], $mgrFieldRules);
        $this->worker = $this->user('worker@test.local', ['projects' => ['v' => 1], 'updates' => ['v' => 1, 'a' => 1, 'e' => 1]]);
        $this->p1 = Project::create(['name' => 'مشروعُ المدير', 'manager_id' => $this->mgr->id]);
        $this->p2 = Project::create(['name' => 'مشروعٌ آخر', 'manager_id' => $this->owner->id]);
    }

    private function report(Project $p, string $done): WorkUpdate
    {
        $this->actingAs($this->worker);
        $w = WorkUpdate::create(['project_id' => $p->id, 'done' => $done, 'hours' => 3,
            'problems' => 'معوّقٌ-حسّاس', 'work_date' => now()->toDateString()]);
        auth()->logout();

        return $w;
    }

    private function h(User $u): array
    {
        return $this->bearer($this->mobileLogin($u)['access_token']);
    }

    public function test_manager_sees_his_projects_by_default_and_the_team_on_request(): void
    {
        $this->world();
        $mine = $this->report($this->p1, 'بندٌ في مشروعي');
        $other = $this->report($this->p2, 'بندٌ في مشروعٍ آخر');
        $H = $this->h($this->mgr);

        $d = $this->withHeaders($H)->getJson('/api/mobile/v1/reports/daily')->assertOk()->json('data');
        $this->assertSame('mine', $d['scope']);
        $this->assertSame([(string) $mine->id], array_column($d['entries'], 'id'));
        $this->assertSame(1, $d['summary']['pending']);
        $this->assertNull($d['compliance'], 'امتثالُ الحضور لحامل hr:v وحدَه');
        $this->assertTrue($d['entries'][0]['can_review']);
        $this->assertSame('مشروعُ المدير', $d['entries'][0]['project']['name']);

        $team = $this->withHeaders($H)->getJson('/api/mobile/v1/reports/daily?scope=team')->assertOk()->json('data');
        $ids = array_column($team['entries'], 'id');
        sort($ids);
        $expected = [(string) $mine->id, (string) $other->id];
        sort($expected);
        $this->assertSame($expected, $ids);

        // يومٌ آخر لا يعرضها
        $this->withHeaders($H)->getJson('/api/mobile/v1/reports/daily?date=' . now()->subDays(3)->toDateString())
            ->assertOk()->assertJsonPath('data.entries', []);
    }

    public function test_review_actions_follow_the_web_rules(): void
    {
        $this->world();
        $w = $this->report($this->p1, 'بندٌ للمراجعة');
        $url = '/api/mobile/v1/reports/daily/' . $w->id . '/review';

        // صاحبُ التقرير لا يراجع نفسَه، ومن لا يراجع أصلاً ٤٠٣
        $this->withHeaders($this->h($this->worker))->postJson($url, ['action' => 'accept'])->assertForbidden();
        $peer = $this->user('peer@test.local', ['tasks' => ['v' => 1]]);
        $this->withHeaders($this->h($peer))->postJson($url, ['action' => 'accept'])->assertForbidden();
        $this->withHeaders($this->h($peer))->getJson('/api/mobile/v1/reports/daily')->assertForbidden();
        $this->assertNull($w->fresh()->review_status);

        $H = $this->h($this->mgr);
        $this->withHeaders($H)->postJson($url, ['action' => 'needs_revision'])->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->withHeaders($H)->postJson($url, ['action' => 'erase'])->assertStatus(422);
        $this->assertNull($w->fresh()->review_status);

        $this->withHeaders($H)->postJson($url, ['action' => 'needs_revision', 'feedback' => 'فصّل ما أنجزت'])->assertOk()
            ->assertJsonPath('data.outcome', 'needs_revision')->assertJsonPath('data.entry.review_status', 'needs_revision');
        $this->assertTrue(HubNotification::where('user_id', $this->worker->id)->where('kind', 'report_needs_revision')->exists());

        $this->withHeaders($H)->postJson($url, ['action' => 'accept'])->assertOk()->assertJsonPath('data.outcome', 'accepted');
        $this->assertSame('accepted', $w->fresh()->review_status);
        $this->assertSame((string) $this->mgr->id, (string) $w->fresh()->reviewed_by);

        $this->withHeaders($H)->postJson('/api/mobile/v1/reports/daily/00000000-0000-0000-0000-000000000000/review',
            ['action' => 'accept'])->assertNotFound();
    }

    public function test_hidden_fields_are_null_for_the_reviewer(): void
    {
        $this->world(['updates' => ['problems' => 'hide']]);
        $this->report($this->p1, 'بندٌ ظاهر');

        $res = $this->withHeaders($this->h($this->mgr))->getJson('/api/mobile/v1/reports/daily')->assertOk();
        $res->assertJsonPath('data.entries.0.done', 'بندٌ ظاهر')->assertJsonPath('data.entries.0.problems', null);
        $this->assertMaskedValueAbsent((string) $res->getContent(), 'معوّقٌ-حسّاس');
    }

    public function test_hr_reviewer_gets_team_compliance_and_client_is_404(): void
    {
        $this->world();
        $this->report($this->p2, 'بند');

        // الموظفةُ (مصفوفةُ التعديل: hr:v) واسعةٌ — ترى الفريق افتراضاً مع امتثال الحضور
        $d = $this->withHeaders($this->h($this->employee))->getJson('/api/mobile/v1/reports/daily')->assertOk()->json('data');
        $this->assertSame('team', $d['scope']);
        $this->assertIsArray($d['compliance']);

        $role = Role::create(['name' => 'عميل', 'scope' => 'all', 'flags' => [],
            'matrix' => ['updates' => ['v' => 1, 'e' => 1], 'hr' => ['v' => 1]]]);
        $client = User::create(['name' => 'عميل', 'email' => 'cl@ext.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'account_type' => 'client', 'password_changed_at' => now()]);
        $C = $this->h($client);
        $this->withHeaders($C)->getJson('/api/mobile/v1/reports/daily')->assertNotFound();
        $w = WorkUpdate::query()->orderBy('id')->firstOrFail();
        $this->withHeaders($C)->postJson('/api/mobile/v1/reports/daily/' . $w->id . '/review', ['action' => 'accept'])
            ->assertNotFound();
    }
}
