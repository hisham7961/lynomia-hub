<?php

namespace Tests\Feature\AiHub;

use App\Models\AiProposal;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkUpdate;
use App\Support\Ai\Proposals\ProposalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **طابورُ اقتراحات الذكاء** (docs/ai-hub/47 §٢ العمود أ) — «الذكاءُ يقترح والبياناتُ تقرّر».
 * كلُّ حارسٍ هنا يسقط لو غاب: الدليلُ الحرفيّ، وإعادةُ فحص الصلاحيّة والنطاق عند الاعتماد،
 * و`superseded` حين تتغيّر القيمة، والإطفاءُ الآليّ بالدقّة، والحجبُ عن غير أهل القرار.
 */
class AiProposalsTest extends TestCase
{
    private Project $project;
    private Task $task;
    private WorkUpdate $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->project = Project::create(['name' => 'بوابة الدفع', 'status' => 'قيد التنفيذ']);
        $this->task = Task::create(['title' => 'ربط بوابة الدفع', 'status' => 'قيد التنفيذ', 'progress' => 40,
            'project_id' => $this->project->id, 'assignee_id' => $this->employee->id]);
        $this->report = WorkUpdate::create(['project_id' => $this->project->id, 'task_id' => $this->task->id,
            'work_date' => now()->toDateString(), 'done' => 'أنهيتُ ربطَ واجهة الدفع مع البنك واختبرتُ ثلاث عمليات ناجحة']);
    }

    private function evidence(string $quote = 'أنهيتُ ربطَ واجهة الدفع مع البنك'): array
    {
        return [['module' => 'updates', 'record_id' => (string) $this->report->id, 'quote' => $quote]];
    }

    private function propose(mixed $value = 80, ?array $ev = null): array
    {
        return ProposalService::propose('task_progress', (string) $this->task->id, $value,
            'التقريرُ يذكر إنهاءَ الربط واختبارَه', $ev ?? $this->evidence(), 85, 'test');
    }

    public function test_a_proposal_needs_a_quote_that_really_exists_in_its_source(): void
    {
        $this->assertTrue($this->propose()['ok']);

        $fake = $this->propose(90, $this->evidence('أنهيتُ المشروعَ كلَّه وسلّمته للعميل'));
        $this->assertFalse($fake['ok'], 'اقتباسٌ مختلَقٌ يُسقط الاقتراح');
        $this->assertFalse($this->propose(90, $this->evidence('الدفع'))['ok'], 'اقتباسٌ أقصرُ من الحدّ لا يشهد');
        $this->assertFalse($this->propose(90, [])['ok'], 'بلا دليلٍ لا اقتراح');
        $this->assertSame(1, AiProposal::count());
    }

    public function test_values_are_validated_and_a_no_op_or_duplicate_is_not_stored(): void
    {
        $this->assertFalse($this->propose(140)['ok'], 'التقدّمُ فوق ١٠٠');
        $this->assertFalse($this->propose('كثير')['ok']);
        $this->assertFalse($this->propose(40)['ok'], 'القيمةُ الحاليّة نفسُها');
        $this->assertFalse(ProposalService::propose('users_role', (string) $this->task->id, 1, 'x', $this->evidence())['ok'],
            'نوعٌ خارج القائمة المغلقة');

        $a = $this->propose(80)['proposal'];
        $b = $this->propose(80)['proposal'];
        $this->assertSame($a->id, $b->id, 'التكرارُ يُعيد القائم');

        $c = $this->propose(90)['proposal'];
        $this->assertSame('superseded', $a->fresh()->status, 'الأحدثُ يحلّ محلّ الأقدم');
        $this->assertSame('open', $c->fresh()->status);
    }

    public function test_applying_writes_through_the_model_and_leaves_an_audit_trail(): void
    {
        $p = $this->propose(80)['proposal'];

        $this->actingAs($this->owner)->post(route('ai.proposals.apply', $p->id))->assertRedirect();

        $this->assertEquals(80, (float) $this->task->fresh()->progress);
        $p->refresh();
        $this->assertSame('applied', $p->status);
        $this->assertSame((string) $this->owner->id, (string) $p->decided_by);
        $this->assertTrue(DB::table('audits')->where('action', 'ai.proposal.apply')
            ->where('record_id', $this->task->id)->exists());
    }

    public function test_the_approver_may_edit_the_value_but_not_to_an_invalid_one(): void
    {
        $p = $this->propose(80)['proposal'];
        $this->actingAs($this->owner)->post(route('ai.proposals.apply', $p->id), ['value' => '250']);
        $this->assertSame('open', $p->fresh()->status, 'قيمةٌ معدّلةٌ غيرُ صالحة لا تُطبَّق');

        $this->actingAs($this->owner)->post(route('ai.proposals.apply', $p->id), ['value' => '70']);
        $this->assertEquals(70, (float) $this->task->fresh()->progress);
        $this->assertSame('70', $p->fresh()->applied_value);
    }

    public function test_a_value_changed_since_the_proposal_is_not_overwritten(): void
    {
        $p = $this->propose(80)['proposal'];
        $this->task->forceFill(['progress' => 55])->saveQuietly();

        $this->actingAs($this->owner)->post(route('ai.proposals.apply', $p->id));

        $this->assertEquals(55, (float) $this->task->fresh()->progress);
        $this->assertSame('superseded', $p->fresh()->status);
    }

    public function test_only_someone_who_can_edit_the_record_sees_and_decides_it(): void
    {
        $p = $this->propose(80)['proposal'];

        // المشاهدُ لا يملك التعديل: لا يرى الاقتراحَ ولا يقرّره
        $this->assertCount(0, ProposalService::openFor($this->viewer));
        $this->actingAs($this->viewer)->post(route('ai.proposals.apply', $p->id))->assertNotFound();
        $this->actingAs($this->viewer)->post(route('ai.proposals.reject', $p->id))->assertNotFound();
        $this->assertStringNotContainsString('تقدّم المهمة',
            $this->actingAs($this->viewer)->get(route('ai.proposals'))->assertOk()->getContent());

        // موظّفٌ يملك التعديل لكنّ المهمّةَ خاصّةٌ ليست له: خارج نطاقه
        $this->task->forceFill(['private' => true])->saveQuietly();
        $other = User::create(['name' => 'زميل', 'email' => Str::random(8) . '@test.local', 'password' => 'Secret!2026x',
            'role_id' => $this->employee->role_id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $this->assertCount(0, ProposalService::openFor($other));
        $this->actingAs($other)->post(route('ai.proposals.apply', $p->id))->assertNotFound();
        $this->assertEquals(40, (float) $this->task->fresh()->progress);

        // والمُسنَد إليه يملكها
        $this->assertCount(1, ProposalService::openFor($this->employee));
    }

    public function test_the_inbox_and_the_record_page_show_the_proposal_with_its_evidence(): void
    {
        $this->propose(80);

        $inbox = $this->actingAs($this->employee)->get(route('ai.proposals'))->assertOk()->getContent();
        $this->assertStringContainsString('تقدّم المهمة', $inbox);
        $this->assertStringContainsString('أنهيتُ ربطَ واجهة الدفع مع البنك', $inbox);
        $this->assertStringContainsString('ربط بوابة الدفع', $inbox);

        $show = $this->actingAs($this->employee)->get(route('m.show', ['tasks', $this->task->id]))->assertOk()->getContent();
        $this->assertStringContainsString('✓ اعتماد', $show);

        $this->assertStringContainsString('اقتراحات الذكاء بانتظارك',
            $this->actingAs($this->employee)->get('/morning')->assertOk()->getContent());
    }

    public function test_evidence_from_a_record_the_reader_cannot_see_is_not_quoted(): void
    {
        $p = $this->propose(80)['proposal'];
        // دورٌ يعدّل المهامّ ولا يرى التقاريرَ اليوميّة
        $matrix = $this->employee->role->matrix;
        $matrix['updates'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role = Role::create(['name' => 'بلا تقارير', 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);
        $u = User::create(['name' => 'مدير مهام', 'email' => Str::random(8) . '@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $ev = ProposalService::evidenceFor($u, $p);
        $this->assertNull($ev[0]['quote']);
        $this->assertStringNotContainsString('أنهيتُ ربطَ واجهة الدفع',
            $this->actingAs($u)->get(route('ai.proposals'))->assertOk()->getContent());
    }

    public function test_reject_and_expire(): void
    {
        $p = $this->propose(80)['proposal'];
        $this->actingAs($this->owner)->post(route('ai.proposals.reject', $p->id), ['reason' => 'التقريرُ متفائل'])->assertRedirect();
        $this->assertSame('rejected', $p->fresh()->status);
        $this->assertSame('التقريرُ متفائل', $p->fresh()->reject_reason);

        $q = $this->propose(85)['proposal'];
        $q->forceFill(['expires_at' => now()->subDay()])->save();
        $this->assertSame(1, ProposalService::expire());
        $this->assertSame('expired', $q->fresh()->status);
    }

    public function test_a_kind_rejected_too_often_stops_proposing_by_itself(): void
    {
        foreach (range(1, 10) as $i) {
            AiProposal::create(['kind' => 'task_progress', 'module' => 'tasks', 'record_id' => (string) Str::uuid(),
                'field' => 'progress', 'proposed_value' => '50', 'status' => $i <= 6 ? 'rejected' : 'applied',
                'decided_at' => now()]);
        }

        $this->assertTrue(ProposalService::accuracy('task_progress')['disabled']);
        $r = $this->propose(80);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('موقوف', $r['why']);
        $this->assertTrue(ProposalService::propose('task_status', (string) $this->task->id, 'مكتملة', 'x', $this->evidence())['ok'],
            'الإطفاءُ لنوعه وحدَه');
    }

    public function test_the_switch_turns_the_queue_off(): void
    {
        $this->hubSetting('ai.proposals', '0');
        $this->assertFalse($this->propose(80)['ok']);
    }
}
