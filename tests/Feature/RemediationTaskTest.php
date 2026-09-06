<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\KeyResult;
use App\Models\KpiDef;
use App\Models\Objective;
use App\Models\Task;
use Tests\TestCase;

/**
 * أفعالُ المعالجة (WP-8.5 · §6.13 · §31).
 *
 * «نتيجةٌ» بلا فعلٍ تُغلقها ليست إدارةً — هي لوحةُ شكوى. والفعلُ **لا يُخترع
 * له نظام**: المهامُّ نظامٌ قائم، فزرُّ «أنشئ مهمّةَ معالجة» يكتب مهمّةً كما
 * يفعل `ErrorCenterController::toTask` تماماً — بذاكرةٍ تمنع التكرار، ورابطٍ
 * عكسيّ في `tasks.meta.origin`، وقيدِ تدقيقٍ يقول من فعل ومتى.
 */
class RemediationTaskTest extends TestCase
{
    private function offTargetKpi(): KpiDef
    {
        return KpiDef::create([
            'name' => 'مؤشّرٌ خارج هدفه', 'unit' => 'مشروع', 'target' => 500, 'good' => 'up',
            'formula' => ['a' => ['agg' => 'count', 'module' => 'projects', 'col' => null,
                                  'st' => 'قيد التنفيذ'], 'combine' => 'none'],
            'sort' => 0,
        ]);
    }

    private function stalledObjective(?string $companyId = null): Objective
    {
        $o = Objective::create(['title' => 'هدفٌ متعثّر', 'level' => 'الشركة',
            'status' => 'متعثر', 'company_id' => $companyId,
            'date_start' => now()->subDays(60)->toDateString(),
            'due' => now()->subDays(5)->toDateString()]);
        KeyResult::create(['objective_id' => $o->id, 'title' => 'نتيجة',
            'start_value' => 0, 'target_value' => 100, 'current_value' => 10, 'weight' => 1]);

        return $o;
    }

    /* ────────── مهمّةٌ واحدة لكل نتيجة ────────── */

    public function test_one_task_per_finding_and_the_second_click_returns_the_same_task(): void
    {
        $this->seedCore();
        $k = $this->offTargetKpi();

        $this->actingAs($this->owner)
            ->post('/remediation', ['kind' => 'kpi', 'ref' => $k->id])
            ->assertRedirect();

        $this->assertSame(1, Task::count(), 'لم تُنشأ مهمّةُ المعالجة');
        $task = Task::orderBy('id')->first();
        $this->assertSame('kpi', $task->meta['origin']['kind'] ?? null, 'الرابطُ العكسيّ غائب');
        $this->assertSame('kpi:' . $k->id, $task->meta['origin']['key'] ?? null);

        // النقرةُ الثانية لا تُنشئ ثانيةً — الذاكرةُ في الرابط العكسيّ نفسِه
        $this->actingAs($this->owner)
            ->post('/remediation', ['kind' => 'kpi', 'ref' => $k->id])
            ->assertRedirect();
        $this->assertSame(1, Task::count(), 'نقرةٌ ثانيةٌ ولّدت مهمّةً مكرّرة');
    }

    public function test_a_finding_that_is_not_a_finding_is_refused(): void
    {
        $this->seedCore();
        // مؤشّرٌ **على هدفه**: لا شيء يُعالَج
        $k = KpiDef::create([
            'name' => 'مؤشّرٌ على هدفه', 'target' => 0, 'good' => 'up',
            'formula' => ['a' => ['agg' => 'count', 'module' => 'projects', 'col' => null,
                                  'st' => 'قيد التنفيذ'], 'combine' => 'none'],
            'sort' => 0,
        ]);

        $this->actingAs($this->owner)
            ->post('/remediation', ['kind' => 'kpi', 'ref' => $k->id])
            ->assertStatus(422);
        $this->assertSame(0, Task::count(), 'أُنشئت مهمّةُ معالجةٍ لما لا يحتاج معالجة');
    }

    /* ────────── الصلاحية ────────── */

    public function test_creating_a_remediation_task_requires_the_task_permission(): void
    {
        $this->seedCore();
        $k = $this->offTargetKpi();

        $role = $this->owner->role;
        $role->update(['is_owner' => false, 'flags' => ['monitor' => 1],
            'matrix' => ['tasks' => ['v' => 1, 'a' => 0, 'e' => 1, 'd' => 0],
                         'okrs' => ['v' => 1], 'projects' => ['v' => 1]]]);

        $this->actingAs($this->owner->fresh())
            ->post('/remediation', ['kind' => 'kpi', 'ref' => $k->id])
            ->assertForbidden();
        $this->assertSame(0, Task::count());
    }

    public function test_a_reader_without_monitor_is_refused(): void
    {
        $this->seedCore();
        $k = $this->offTargetKpi();

        $this->actingAs($this->viewer)
            ->post('/remediation', ['kind' => 'kpi', 'ref' => $k->id])
            ->assertForbidden();
    }

    /* ────────── وراثةُ الشركة ────────── */

    public function test_the_task_inherits_the_company_of_its_source(): void
    {
        $this->seedCore();
        $c = \App\Models\Company::create(['name_ar' => 'شركةُ المصدر']);
        $o = $this->stalledObjective($c->id);

        $this->actingAs($this->owner)
            ->post('/remediation', ['kind' => 'okr', 'ref' => $o->id])
            ->assertRedirect();

        $task = Task::orderBy('id')->first();
        $this->assertNotNull($task);
        $this->assertSame($c->id, $task->company_id,
            'مهمّةُ المعالجة خرجت من شركةِ مصدرها — فلا يراها مدقّقُ تلك الشركة');
        $this->assertSame('okr:' . $o->id, $task->meta['origin']['key'] ?? null);
    }

    /* ────────── الأثر ────────── */

    public function test_the_action_is_audited(): void
    {
        $this->seedCore();
        $o = $this->stalledObjective();

        $this->actingAs($this->owner)
            ->post('/remediation', ['kind' => 'okr', 'ref' => $o->id])
            ->assertRedirect();

        $this->assertTrue(
            AuditEntry::where('action', 'إنشاء مهمّة معالجة')->where('record_id', $o->id)->exists(),
            'فعلُ مستوى تحكّمٍ بلا شاهد'
        );
    }

    /* ────────── نتيجةُ الجودة: للمالك وحدَه (المسحُ غيرُ منطَّق) ────────── */

    public function test_a_quality_finding_task_is_owner_only(): void
    {
        $this->seedCore();
        // سجلٌّ بلا شركة = نتيجةُ جودةٍ حقيقية في وحدة المهامّ
        Task::create(['title' => 'مهمّةٌ بلا شركة', 'status' => 'جديدة']);
        \App\Support\DataQuality::scan(true);

        $role = $this->employee->role;
        $role->update(['flags' => ['monitor' => 1]]);
        $this->actingAs($this->employee->fresh())
            ->post('/remediation', ['kind' => 'quality', 'module' => 'tasks', 'rule' => 'co'])
            ->assertForbidden();

        $before = Task::count();
        $this->actingAs($this->owner)
            ->post('/remediation', ['kind' => 'quality', 'module' => 'tasks', 'rule' => 'co'])
            ->assertRedirect();

        $this->assertSame($before + 1, Task::count());
        $task = Task::where('meta->origin->key', 'quality:tasks:co')->first();
        $this->assertNotNull($task, 'لم تُنشأ مهمّةُ معالجةٍ لنتيجة الجودة');
        $this->assertSame('quality:tasks:co', $task->meta['origin']['key'] ?? null);
        $this->assertSame('tasks', $task->meta['origin']['module'] ?? null);
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)
            ->post('/remediation', ['kind' => 'مخترَع', 'ref' => (string) \Illuminate\Support\Str::uuid()])
            ->assertStatus(422);
    }
}
