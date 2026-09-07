<?php

namespace Tests\Feature\Mobile;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **D.2/D.3 · إجراءاتُ المورد (allowlist + التنفيذ)** — Mobile Readiness · الطور D.
 *
 * `GET {module}/{id}/actions` يعيد **الانتقالاتِ الصالحةَ لحالة السجل الحاليّة فقط**
 * (لا «نفّذ أيَّ شيء»)، و`POST {module}/{id}/actions/{action}` ينفّذ ما في الـallowlist
 * وحدَه تحت الحرّاس (صلاحية + نطاق + حالة + تصعيد + Idempotency + تدقيقٌ source=mobile).
 * الحالةُ الخاطئةُ/الممنوعةُ خطأٌ آليّ، وإجراءٌ يتطلّب تصعيداً ⇒ STEP_UP_REQUIRED حتى منحةٍ سارية.
 */
class MobileActionsTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function auth(User $u, ?string $uuid = null): array
    {
        return $this->bearer($this->mobileLogin($u, $uuid)['access_token']);
    }

    private function seedTask(string $status = 'جديدة'): Task
    {
        $p = Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);

        return Task::create(['title' => 'مهمة', 'status' => $status, 'project_id' => $p->id]);
    }

    // ═══════════════════════ D.2 · الـallowlist ═══════════════════════

    public function test_actions_lists_only_valid_transitions_for_current_state(): void
    {
        $this->seedCore();
        $t = $this->seedTask('جديدة');
        $h = $this->auth($this->owner, 'inst-act-list-1111');

        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/tasks/' . $t->id . '/actions')->assertOk();

        // غلافُ الإجراءات
        $this->assertSame('tasks', $res->json('data.module'));
        $this->assertSame((string) $t->id, $res->json('data.id'));
        $this->assertSame('جديدة', $res->json('data.status'));
        $this->assertFalse($res->json('data.trashed'));

        $targets = collect($res->json('data.actions'))->where('action', 'status')->pluck('to')->all();
        $this->assertContains('قيد التنفيذ', $targets, 'انتقالٌ صالحٌ معروض');
        $this->assertNotContains('جديدة', $targets, 'الحالةُ الحاليّةُ ليست انتقالاً — لا يُعرَض ما لا يُنفَّذ');
    }

    public function test_actions_of_a_trashed_record_offer_only_restore(): void
    {
        $this->seedCore();
        $t = $this->seedTask();
        $t->delete();   // في السلة

        $h = $this->auth($this->owner, 'inst-act-trash-111');
        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/tasks/' . $t->id . '/actions')->assertOk();

        $this->assertTrue($res->json('data.trashed'));
        $actions = collect($res->json('data.actions'))->pluck('action')->all();
        $this->assertSame(['restore'], $actions, 'السلة ⇒ الإجراءُ الوحيدُ استعادة');
    }

    // ═══════════════════════ D.3 · التنفيذ ═══════════════════════

    public function test_executing_an_allowed_status_transition_applies_and_audits_as_mobile(): void
    {
        $this->seedCore();
        $t = $this->seedTask('جديدة');
        $h = $this->auth($this->owner, 'inst-act-exec-111');

        $this->withHeaders($h)->postJson('/api/mobile/v1/tasks/' . $t->id . '/actions/status', ['to' => 'قيد التنفيذ'])
            ->assertOk()
            ->assertJsonPath('data.action', 'status')
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.status', 'قيد التنفيذ');

        $this->assertSame('قيد التنفيذ', $t->fresh()->status, 'الانتقالُ طُبِّق');

        // تدقيقٌ في نفس السجلّ بمصدر mobile (لا مركزُ أمنٍ منفصل)
        $this->assertTrue(
            DB::table('audits')->where('source', 'mobile')->where('module', 'tasks')
                ->where('record_id', $t->id)->exists(),
            'تنفيذُ الإجراء يجب أن يُدقَّق بمصدر mobile'
        );
    }

    public function test_executing_a_transition_to_the_current_state_is_a_business_rule_violation(): void
    {
        $this->seedCore();
        $t = $this->seedTask('جديدة');
        $h = $this->auth($this->owner, 'inst-act-same-111');

        // الحالةُ الحاليّةُ ليست في الـallowlist ⇒ لا «نفّذ أيَّ شيء»
        $this->withHeaders($h)->postJson('/api/mobile/v1/tasks/' . $t->id . '/actions/status', ['to' => 'جديدة'])
            ->assertStatus(422)->assertJsonPath('code', 'BUSINESS_RULE_VIOLATION');
        $this->assertSame('جديدة', $t->fresh()->status);
    }

    public function test_a_view_only_user_cannot_execute_an_edit_action(): void
    {
        $this->seedCore();
        $t = $this->seedTask('جديدة');
        $h = $this->auth($this->viewer, 'inst-act-forbid-11');

        // المشاهدُ لا يملك e ⇒ resolveApi يُسقطه بـFORBIDDEN قبل أيّ تنفيذ
        $this->withHeaders($h)->postJson('/api/mobile/v1/tasks/' . $t->id . '/actions/status', ['to' => 'قيد التنفيذ'])
            ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
        $this->assertSame('جديدة', $t->fresh()->status);
    }

    public function test_restoring_a_trashed_record_via_action_works(): void
    {
        $this->seedCore();
        $t = $this->seedTask();
        $t->delete();

        $h = $this->auth($this->owner, 'inst-act-restore-11');
        $this->withHeaders($h)->postJson('/api/mobile/v1/tasks/' . $t->id . '/actions/restore')
            ->assertOk()->assertJsonPath('data.action', 'restore')->assertJsonPath('data.trashed', false);

        $this->assertNull($t->fresh()->deleted_at, 'استُعيد من السلة');
    }

    // ═══════════════════════ D.3 · تصعيدُ المصادقة (Step-Up) ═══════════════════════

    public function test_a_stepup_gated_action_requires_a_fresh_grant_then_applies(): void
    {
        $this->seedCore();
        // إجراءُ الحالة على tasks مُصعَّدٌ بالإعداد
        config(['hub.mobile.stepup_actions' => ['tasks:status']]);
        $t = $this->seedTask('جديدة');

        $data = $this->mobileLogin($this->owner, 'inst-act-stepup-11');
        $h = $this->bearer($data['access_token']);

        // بلا منحةٍ ⇒ STEP_UP_REQUIRED بغرضٍ حتميّ
        $blocked = $this->withHeaders($h)->postJson('/api/mobile/v1/tasks/' . $t->id . '/actions/status', ['to' => 'قيد التنفيذ']);
        $blocked->assertStatus(428)->assertJsonPath('code', 'STEP_UP_REQUIRED')
            ->assertJsonPath('details.purpose', 'action:tasks:status');
        $this->assertSame('جديدة', $t->fresh()->status, 'لم يُنفَّذ قبل التصعيد');

        // منحةُ تصعيدٍ لنفس الجلسة والغرض (كلمةُ المرور — المالكُ بلا TOTP)
        $this->withHeaders($h)->postJson('/api/mobile/v1/auth/step-up', [
            'purpose' => 'action:tasks:status', 'credential' => 'Secret!2026x',
        ])->assertOk()->assertJsonPath('data.granted', true);

        // إعادةُ المحاولة تنجح
        $this->withHeaders($h)->postJson('/api/mobile/v1/tasks/' . $t->id . '/actions/status', ['to' => 'قيد التنفيذ'])
            ->assertOk()->assertJsonPath('data.changed', true);
        $this->assertSame('قيد التنفيذ', $t->fresh()->status);
    }

    // ═══════════════════════ D.3 · تنفيذٌ عديمُ الأثر (Idempotency) ═══════════════════════

    public function test_action_execution_is_idempotent_under_a_key(): void
    {
        $this->seedCore();
        $t = $this->seedTask('جديدة');
        $h = $this->auth($this->owner, 'inst-act-idem-111') + ['Idempotency-Key' => 'act-key-1'];

        $a = $this->withHeaders($h)->postJson('/api/mobile/v1/tasks/' . $t->id . '/actions/status', ['to' => 'قيد التنفيذ'])
            ->assertOk();
        // إعادةٌ بنفس المفتاح ⇒ الردُّ المخزَّن (changed:true) لا تنفيذٌ ثانٍ يقول changed:false
        $b = $this->withHeaders($h)->postJson('/api/mobile/v1/tasks/' . $t->id . '/actions/status', ['to' => 'قيد التنفيذ'])
            ->assertOk();

        $b->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($a->json('data.changed'), $b->json('data.changed'));
        $this->assertTrue($b->json('data.changed'), 'الردُّ المُعاد هو ردُّ التنفيذ الأول (changed:true)');
        $this->assertSame('قيد التنفيذ', $t->fresh()->status);
    }
}
