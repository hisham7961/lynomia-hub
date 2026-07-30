<?php

namespace Tests\Feature;

use App\Models\Change;
use App\Models\CodeRelease;
use App\Models\Deployment;
use App\Models\InternalRequest;
use App\Models\PlanItem;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Str;
use Tests\TestCase;

class TraceTest extends TestCase
{
    /** يزرع خيطاً كاملاً: طلب → متطلب → مهمة → تغيير → إصدار → نشر → حادث */
    protected function seedThread(): array
    {
        $p = Project::create(['name' => 'مشروع الخيط', 'status' => 'قيد التنفيذ']);
        $req = InternalRequest::create(['title' => 'طلب تقارير', 'status' => 'معتمد', 'project_id' => $p->id]);
        $feat = PlanItem::create(['title' => 'ميزة التقارير', 'project_id' => $p->id,
            'request_id' => $req->id, 'test' => 'نجح', 'status' => 'قيد التطوير']);
        $task = Task::create(['title' => 'برمجة التقارير', 'project_id' => $p->id,
            'feat_id' => $feat->id, 'status' => 'مكتملة']);
        $chg = Change::create(['title' => 'نشر محرك التقارير', 'project_id' => $p->id,
            'task_id' => $task->id, 'status' => 'منفّذ']);
        $rel = CodeRelease::create(['ver' => '9.1.0', 'project_id' => $p->id]);
        $inc = \App\Models\Incident::create(['title' => 'بطء التقارير', 'severity' => 'متوسط', 'status' => 'مغلق']);
        $dep = Deployment::create(['ver' => '9.1.0', 'project_id' => $p->id, 'change_id' => $chg->id,
            'release_id' => $rel->id, 'incident_id' => $inc->id, 'status' => 'ناجح', 'deployed_at' => now()]);

        return compact('p', 'req', 'feat', 'task', 'chg', 'rel', 'inc', 'dep');
    }

    public function test_mid_chain_seed_walks_both_directions(): void
    {
        $this->seedCore();
        $t = $this->seedThread();

        $this->actingAs($this->owner)->get('/trace/tasks/' . $t['task']->id)
            ->assertOk()
            ->assertSee('طلب تقارير')          // صعوداً حتى الأصل
            ->assertSee('ميزة التقارير')
            ->assertSee('اختبار: نجح')
            ->assertSee('نشر محرك التقارير')   // نزولاً حتى النتيجة
            ->assertSee('9.1.0')
            ->assertSee('بطء التقارير');
    }

    public function test_side_seed_incident_resolves_the_whole_thread(): void
    {
        $this->seedCore();
        $t = $this->seedThread();

        $this->actingAs($this->owner)->get('/trace/incidents/' . $t['inc']->id)
            ->assertOk()->assertSee('طلب تقارير')->assertSee('برمجة التقارير');
    }

    public function test_sibling_branches_are_excluded(): void
    {
        $this->seedCore();
        $t = $this->seedThread();
        // متطلب شقيق من نفس الطلب — يظهر من بذرة الطلب لا من بذرة المهمة
        PlanItem::create(['title' => 'ميزة شقيقة', 'project_id' => $t['p']->id,
            'request_id' => $t['req']->id, 'status' => 'مقترحة']);

        $this->actingAs($this->owner)->get('/trace/tasks/' . $t['task']->id)
            ->assertOk()->assertDontSee('ميزة شقيقة');
        $this->actingAs($this->owner)->get('/trace/requests/' . $t['req']->id)
            ->assertOk()->assertSee('ميزة شقيقة');
    }

    public function test_orphan_seed_shows_both_break_warnings(): void
    {
        $this->seedCore();
        $task = Task::create(['title' => 'يتيمة', 'status' => 'جديدة']);

        $r = $this->actingAs($this->owner)->get('/trace/tasks/' . $task->id)->assertOk();
        $r->assertSee('لا يصل لطلب أصلي');
        $r->assertSee('لم يبلغ النشر بعد');

        // الخيط الكامل بلا تحذير انقطاع
        $t = $this->seedThread();
        $this->actingAs($this->owner)->get('/trace/tasks/' . $t['task']->id)
            ->assertOk()->assertDontSee('لا يصل لطلب أصلي');
    }

    public function test_gating_and_hidden_stages(): void
    {
        $this->seedCore();
        $t = $this->seedThread();

        // وحدة خارج السلسلة أو سجل غير موجود
        $this->actingAs($this->owner)->get('/trace/clients/' . $t['task']->id)->assertNotFound();
        $this->actingAs($this->owner)->get('/trace/tasks/' . (string) Str::uuid())->assertNotFound();

        // دور لا يرى وحدة التغييرات: العمود محجوب لا الصفحة
        $role = $this->viewer->role;
        $m = $role->matrix; $m['changes'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->update(['matrix' => $m]);

        $r = $this->actingAs($this->viewer)->get('/trace/tasks/' . $t['task']->id)->assertOk();
        $r->assertSee('لا تملك رؤية هذه الوحدة');
        $r->assertDontSee('نشر محرك التقارير');

        // ومن لا يرى وحدة البذرة نفسها يُمنع
        $m['tasks'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->update(['matrix' => $m]);
        $this->actingAs($this->viewer)->get('/trace/tasks/' . $t['task']->id)->assertForbidden();
    }
}
