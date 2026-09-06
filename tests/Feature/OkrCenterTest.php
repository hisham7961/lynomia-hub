<?php

namespace Tests\Feature;

use App\Models\KeyResult;
use App\Models\Objective;
use App\Support\OkrCentre;
use Tests\TestCase;

/**
 * مركزُ الأهداف (WP-8.5 · §6.10 · §47).
 *
 * **مصدرٌ واحد.** كانت `/okrs` تحسب النسبةَ من نتائجها (`hub_okr_progress`)
 * وكانت `/performance` تقرأ عمودَ `objectives.progress` المخزَّن — رقمان لهدفٍ
 * واحدٍ على شاشتين، وأحدُهما كاذبٌ دائماً. الرقمُ المحسوبُ هو الحقيقة، والعمودُ
 * أثرٌ لآخر تثبيتٍ مأذون.
 *
 * وعدّاداتُ «متأخّر · متعثّر · راكد» محسوبةٌ من مواعيدها وقراءاتها
 * (`key_results.read_at` · `objectives.computed_at`) لا مكتوبةٌ باليد.
 */
class OkrCenterTest extends TestCase
{
    private function obj(array $x = []): Objective
    {
        return Objective::create(array_merge([
            'title' => 'هدفٌ للاختبار', 'level' => 'الشركة', 'period' => 'ر١',
            'status' => 'قيد التنفيذ',
            'date_start' => now()->subDays(30)->toDateString(),
            'due' => now()->addDays(30)->toDateString(),
        ], $x));
    }

    private function kr(Objective $o, array $x = []): KeyResult
    {
        return KeyResult::create(array_merge([
            'objective_id' => $o->id, 'title' => 'نتيجة',
            'start_value' => 0, 'target_value' => 100, 'current_value' => 50,
            'weight' => 1, 'source' => 'manual',
        ], $x));
    }

    /* ────────── مصدرٌ واحدٌ للرقم ────────── */

    public function test_both_screens_report_the_same_computed_progress(): void
    {
        $this->seedCore();
        // عمودٌ مخزَّنٌ **كاذب** (٩٩) ونتائجُ الهدف تقول ٥٠ — الحقيقةُ محسوبة
        $o = $this->obj(['title' => 'هدفُ المصدر الواحد', 'progress' => 99]);
        $this->kr($o);

        $okrs = $this->actingAs($this->owner)->get('/okrs')->assertOk()->getContent();
        $perf = $this->actingAs($this->owner)->get('/performance')->assertOk()->getContent();

        $this->assertStringContainsString('50٪', $okrs);
        $this->assertStringContainsString('50٪', $perf,
            'لوحةُ الأداء تقرأ عموداً مخزَّناً بدل الحساب — رقمان لهدفٍ واحد');
        $this->assertStringNotContainsString('99٪', $perf,
            'العمودُ المخزَّن الكاذب ما زال يُعرض');
    }

    /* ────────── العدّادات ────────── */

    public function test_counters_count_overdue_blocked_and_stalled(): void
    {
        $this->seedCore();

        $late = $this->obj(['title' => 'هدفٌ فات موعدُه',
            'due' => now()->subDays(5)->toDateString()]);
        $this->kr($late);

        $blocked = $this->obj(['title' => 'هدفٌ متعثّر', 'status' => 'متعثر']);
        $this->kr($blocked);

        $stalled = $this->obj(['title' => 'هدفٌ راكد',
            'computed_at' => now()->subDays(30)]);
        $this->kr($stalled, ['source' => 'count', 'src_module' => 'projects',
            'read_at' => now()->subDays(30)]);

        $fresh = $this->obj(['title' => 'هدفٌ حيّ', 'computed_at' => now()]);
        $this->kr($fresh, ['read_at' => now()]);

        $this->actingAs($this->owner);
        $b = OkrCentre::board($this->owner);

        $this->assertSame(1, $b['overdue'], 'عدّادُ المتأخّر');
        $this->assertSame(1, $b['blocked'], 'عدّادُ المتعثّر');
        $this->assertSame(1, $b['stalled'], 'عدّادُ الراكد');

        $ids = collect($b['rows'])->keyBy(fn ($r) => $r['o']->id);
        $this->assertTrue($ids[$late->id]['overdue']);
        $this->assertTrue($ids[$blocked->id]['blocked']);
        $this->assertTrue($ids[$stalled->id]['stalled']);
        $this->assertFalse($ids[$fresh->id]['stalled']);
    }

    public function test_the_board_shows_the_counters_on_screen(): void
    {
        $this->seedCore();
        $o = $this->obj(['title' => 'هدفٌ فات موعدُه', 'due' => now()->subDays(9)->toDateString()]);
        $this->kr($o);

        $this->actingAs($this->owner)->get('/okrs')->assertOk()
            ->assertSee('فات موعدَه')
            ->assertSee('راكد');
    }

    /* ────────── التسلسل بالمستوى والمشروع، والمالكُ باسمه ────────── */

    public function test_hierarchy_groups_by_level_then_project_and_names_the_owner(): void
    {
        $this->seedCore();
        $p = \App\Models\Project::create(['name' => 'مشروعُ التسلسل', 'status' => 'قيد التنفيذ']);

        $company = $this->obj(['title' => 'هدفُ الشركة', 'level' => 'الشركة',
            'owner_id' => $this->employee->id]);
        $this->kr($company);
        $proj = $this->obj(['title' => 'هدفُ المشروع', 'level' => 'مشروع', 'project_id' => $p->id]);
        $this->kr($proj);

        $this->actingAs($this->owner);
        $b = OkrCentre::board($this->owner);
        $this->assertSame(['الشركة', 'مشروع'], array_keys($b['levels']),
            'المستوياتُ لا تُرتَّب بترتيب السجل');

        $rows = collect($b['rows'])->keyBy(fn ($r) => $r['o']->id);
        $this->assertSame('موظفة', $rows[$company->id]['owner'], 'المالكُ بمعرّفه لا باسمه');
        $this->assertSame('مشروعُ التسلسل', $rows[$proj->id]['project']);

        $this->actingAs($this->owner)->get('/okrs')->assertOk()
            ->assertSee('موظفة')->assertSee('مشروعُ التسلسل');
    }

    public function test_an_objective_without_key_results_is_not_counted_as_stalled(): void
    {
        $this->seedCore();
        $this->obj(['title' => 'هدفٌ بلا نتائج']);

        $this->actingAs($this->owner);
        $b = OkrCentre::board($this->owner);
        $this->assertSame(0, $b['stalled'], 'هدفٌ لا يُقاس أصلاً ليس «راكداً» — هو غيرُ مقيس');
    }
}
