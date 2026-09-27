<?php

namespace Tests\Feature\Mobile;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **قرارُ الإجازة على الجوال** (خطّةُ التطبيق · 3.2) — سلسلةُ الويب نفسُها (`LeaveDecision`).
 *
 * المديرُ يوصي ثم الموارد البشريّة تحسم؛ الرفضُ بسببٍ إلزاميّ؛ لا قرارَ في طلبِ النفس ولا فوق
 * قرار؛ غيرُ الطرف ٤٠٣؛ وخارجُ النطاق ٤٠٤؛ والعميلُ محجوب. كلُّ رفضٍ بكودٍ وسببٍ آليَّين.
 */
class MobileLeavesDecideTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function person(string $name, array $matrix, array $flags = [], string $scope = 'all'): User
    {
        $role = Role::create(['name' => $name . Str::random(4), 'scope' => $scope, 'flags' => $flags, 'matrix' => $matrix]);

        return User::create(['name' => $name, 'email' => Str::random(9) . '@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /** طلبٌ «مقدّم» لموظّفٍ مديرُه `$mgr` */
    private function world(): array
    {
        $this->seedCore();
        $mgr = $this->person('المدير', ['leaves' => ['v' => 1]]);
        $hr = $this->person('الموارد', ['leaves' => ['v' => 1], 'hr' => ['v' => 1, 'e' => 1]]);
        $requester = $this->person('صاحبُ الطلب', ['leaves' => ['v' => 1, 'a' => 1]]);
        $stranger = $this->person('زميلٌ غريب', ['leaves' => ['v' => 1]]);
        $emp = Employee::create(['name' => 'صاحبُ الطلب', 'status' => 'نشط', 'user_id' => $requester->id,
            'manager_id' => $mgr->id, 'leave_bal' => 30]);
        $req = LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية',
            'date_from' => '2026-10-01', 'date_to' => '2026-10-05', 'days' => 5, 'status' => 'مقدّم']);

        return compact('mgr', 'hr', 'requester', 'stranger', 'emp', 'req');
    }

    private function decide(User $u, string $id, array $body, array $extra = [])
    {
        return $this->withHeaders($this->bearer($this->mobileLogin($u)['access_token']) + $extra)
            ->postJson('/api/mobile/v1/leaves/' . $id . '/decide', $body);
    }

    public function test_manager_recommends_then_hr_finalizes(): void
    {
        $w = $this->world();

        $this->decide($w['mgr'], $w['req']->id, ['decision' => 'approve'])->assertOk()
            ->assertJsonPath('data.status', 'موافقة المدير')->assertJsonPath('data.previous_status', 'مقدّم');
        $this->assertSame('موافقة المدير', (string) $w['req']->fresh()->status);

        $this->decide($w['hr'], $w['req']->id, ['decision' => 'approve'])->assertOk()
            ->assertJsonPath('data.status', 'معتمد');
        $this->assertSame('معتمد', (string) $w['req']->fresh()->status);

        // لا قرارَ فوق قرار
        $this->decide($w['hr'], $w['req']->id, ['decision' => 'reject', 'reason' => 'متأخّر'])
            ->assertStatus(422)->assertJsonPath('details.reason', 'already_decided');
        // وصاحبُ الطلب أُخطر بالقرارين
        $this->assertSame(2, \Illuminate\Support\Facades\DB::table('notifications_hub')
            ->where('user_id', $w['requester']->id)->where('module', 'leaves')->count());
    }

    public function test_reject_requires_a_reason_and_records_it(): void
    {
        $w = $this->world();

        $this->decide($w['mgr'], $w['req']->id, ['decision' => 'reject'])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('details.reason', 'reason_required');
        $this->assertSame('مقدّم', (string) $w['req']->fresh()->status);

        $this->decide($w['mgr'], $w['req']->id, ['decision' => 'reject', 'reason' => 'ضغطُ تسليمٍ في الأسبوع نفسه'])
            ->assertOk()->assertJsonPath('data.status', 'مرفوض');
        $this->assertSame('ضغطُ تسليمٍ في الأسبوع نفسه', (string) $w['req']->fresh()->note);
    }

    public function test_self_stranger_and_out_of_scope_are_refused(): void
    {
        $w = $this->world();

        $this->decide($w['requester'], $w['req']->id, ['decision' => 'approve'])
            ->assertStatus(403)->assertJsonPath('details.reason', 'self_request');
        $this->decide($w['stranger'], $w['req']->id, ['decision' => 'approve'])
            ->assertStatus(403)->assertJsonPath('details.reason', 'not_decider');

        // مَن لا يرى الإجازاتِ أصلاً ٤٠٣، ومَن عُزل على شركةٍ أخرى لا يبلغ الطلبَ ٤٠٤ (hub_scope)
        $blind = $this->person('بلا رؤية', ['tasks' => ['v' => 1]]);
        $this->decide($blind, $w['req']->id, ['decision' => 'approve'])->assertStatus(403);
        $coA = \App\Models\Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = \App\Models\Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $w['req']->forceFill(['company_id' => $coA->id])->saveQuietly();
        $isolated = $this->person('مواردُ شركةٍ أخرى', ['leaves' => ['v' => 1], 'hr' => ['v' => 1, 'e' => 1]]);
        $isolated->forceFill(['companies' => [$coB->id]])->save();
        $this->decide($isolated, $w['req']->id, ['decision' => 'approve'])->assertStatus(404)
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');

        $this->assertSame('مقدّم', (string) $w['req']->fresh()->status, 'لم يتغيّر شيءٌ بأيِّ رفض');
    }

    public function test_idempotent_retry_does_not_double_decide(): void
    {
        $w = $this->world();
        $key = ['Idempotency-Key' => 'lv-' . Str::random(8)];

        $h = $this->bearer($this->mobileLogin($w['hr'])['access_token']) + $key;
        $a = $this->withHeaders($h)->postJson('/api/mobile/v1/leaves/' . $w['req']->id . '/decide', ['decision' => 'approve'])->assertOk();
        $b = $this->withHeaders($h)->postJson('/api/mobile/v1/leaves/' . $w['req']->id . '/decide', ['decision' => 'approve'])->assertOk();
        $b->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($a->json('data.status'), $b->json('data.status'));
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('audits')->where('action', 'قرار إجازة')->count());
    }

    public function test_client_accounts_cannot_reach_the_decision(): void
    {
        $w = $this->world();
        $client = \App\Models\Client::create(['name' => 'عميل']);
        $cu = User::create(['name' => 'عميل', 'email' => 'c@ext.local', 'password' => 'Secret!2026x',
            'role_id' => $w['hr']->role_id, 'status' => 'نشط', 'password_changed_at' => now(), 'account_type' => 'client']);
        \App\Models\ClientMembership::create(['client_id' => $client->id, 'user_id' => $cu->id,
            'role' => 'lead', 'status' => 'active', 'activated_at' => now()]);

        $this->decide($cu, $w['req']->id, ['decision' => 'approve'])->assertStatus(404);
        $this->assertSame('مقدّم', (string) $w['req']->fresh()->status);
    }
}
