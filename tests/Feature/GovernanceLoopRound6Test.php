<?php

namespace Tests\Feature;

use App\Models\Decision;
use App\Models\Issue;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\Task;
use Tests\TestCase;

/**
 * المرحلة ٦ — إغلاق حلقات الحوكمة:
 *  1) المحضر كان نصّاً حرّاً لا يولّد شيئاً، والقرارات تُعاد كتابتها يدوياً.
 *  2) القرار كان أرشيفاً لا يُقاس بإنجاز مهامه (لا `tasks.decision_id`).
 *  3) سجل المخاطر بلا احتمال — و«صحة المشروع» تعدّ الأخطار عدّاً فخطرٌ حرج
 *     يساوي خطراً منخفضاً.
 */
class GovernanceLoopRound6Test extends TestCase
{
    public function test_minutes_extraction_creates_linked_decisions_and_tasks(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع الاجتماع', 'status' => 'نشط']);
        $m = Meeting::create(['title' => 'اجتماع الربع', 'project_id' => $p->id,
            'dt' => now()->toDateTimeString(),
            'decs' => "اعتماد ميزانية التسويق\nتأجيل إطلاق الباقة",
            'notes' => "تجهيز عرض العميل\nمراجعة العقد"]);

        $this->actingAs($this->owner)->post('/meetings/' . $m->id . '/extract', [
            'decisions' => ['اعتماد ميزانية التسويق', 'تأجيل إطلاق الباقة'],
            'tasks' => ['تجهيز عرض العميل'],
        ]);

        $decs = Decision::where('meeting_id', $m->id)->get();
        $this->assertCount(2, $decs, 'أسطر المحضر تصير قرارات مربوطة بالاجتماع');
        $this->assertSame($p->id, $decs->first()->project_id, 'وترث مشروعه');

        $task = Task::where('title', 'تجهيز عرض العميل')->first();
        $this->assertNotNull($task);
        $this->assertNotNull($task->decision_id, 'المهمة تُنسب لقرار الاجتماع فيُقاس تنفيذه');

        $this->assertSame('انعقد', $m->fresh()->status, 'توثيق المحضر يعني أن الاجتماع انعقد');
    }

    public function test_extraction_requires_selection_and_permissions(): void
    {
        $this->seedCore();
        $m = Meeting::create(['title' => 'اجتماع فارغ', 'dt' => now()->toDateTimeString(), 'decs' => 'سطر']);

        $this->actingAs($this->owner)->post('/meetings/' . $m->id . '/extract', [])->assertStatus(422);
        $this->assertSame(0, Decision::count());

        $this->actingAs($this->viewer)->post('/meetings/' . $m->id . '/extract',
            ['decisions' => ['سطر']])->assertForbidden();
    }

    public function test_risk_score_is_probability_times_impact(): void
    {
        $this->seedCore();
        $this->assertSame(16, hub_risk_score('شبه مؤكد', 'حرجة')['score']);
        $this->assertSame('حرجة', hub_risk_score('شبه مؤكد', 'حرجة')['band']);
        $this->assertSame(2, hub_risk_score('محتمل', 'منخفضة')['score']);
        $this->assertSame('منخفضة', hub_risk_score('محتمل', 'منخفضة')['band']);
        $this->assertNull(hub_risk_score(null, 'حرجة'), 'الأثر وحده لا يصنع درجة');

        $i = Issue::create(['title' => 'خطر التسريب', 'kind' => 'خطر', 'status' => 'مفتوحة',
            'likelihood' => 'مرجّح', 'severity' => 'عالية']);
        $this->actingAs($this->owner)->get('/m/issues/' . $i->id)
            ->assertOk()->assertSee('تقييم المخاطرة')->assertSee('9/١٦');
    }

    public function test_project_health_weights_severity(): void
    {
        $this->seedCore();
        $light = Project::create(['name' => 'مشروع بأخطار خفيفة', 'status' => 'نشط']);
        $heavy = Project::create(['name' => 'مشروع بخطر حرج', 'status' => 'نشط']);

        foreach (range(1, 3) as $n) {
            Issue::create(['title' => "خطر منخفض {$n}", 'kind' => 'خطر', 'status' => 'مفتوحة',
                'severity' => 'منخفضة', 'project_id' => $light->id]);
        }
        Issue::create(['title' => 'خطر حرج واحد', 'kind' => 'خطر', 'status' => 'مفتوحة',
            'severity' => 'حرجة', 'project_id' => $heavy->id]);

        $lightRisk = collect(hub_project_health($light->id)['factors'] ?? [])->firstWhere('k', 'المخاطر المفتوحة');
        $heavyRisk = collect(hub_project_health($heavy->id)['factors'] ?? [])->firstWhere('k', 'المخاطر المفتوحة');

        $this->assertGreaterThan($heavyRisk['s'], $lightRisk['s'],
            'ثلاثة أخطار منخفضة أهون من خطرٍ حرج واحد — كان العدّ يساوي بينها');
        $this->assertStringContainsString('حرجة', $heavyRisk['note']);
    }
}
