<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\HubNotification;
use App\Models\Task;
use App\Support\FlowRunner;
use Tests\TestCase;

class FlowSandboxTest extends TestCase
{
    protected function flow(): Flow
    {
        return Flow::create([
            'name' => 'تنبيه إنجاز العاجل', 'module' => 'tasks', 'event' => 'status',
            'status_to' => 'منجزة', 'cond_field' => 'priority', 'cond_op' => 'eq', 'cond_value' => 'عاجلة',
            'actions' => [
                ['type' => 'notify', 'to' => 'owners', 'text' => 'أُنجزت: {title} بواسطة {_by}'],
                ['type' => 'task', 'text' => 'مراجعة تسليم {title}'],
            ],
            'enabled' => true, 'created_by' => $this->owner->id,
        ]);
    }

    public function test_simulate_matches_and_resolves_templates_with_no_side_effects(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $f = $this->flow();
        $t = Task::create(['title' => 'إصلاح البوابة', 'priority' => 'عاجلة', 'status' => 'منجزة']);

        $before = [HubNotification::count(), Task::count()];
        $res = FlowRunner::simulate($f, 'tasks', $t, 'منجزة');

        $this->assertTrue($res['ok']);
        $this->assertTrue($res['statusMatch']);
        $this->assertTrue($res['condPass']);
        $this->assertStringContainsString('أُنجزت: إصلاح البوابة بواسطة', $res['actions'][0]['detail']);
        $this->assertStringContainsString('مراجعة تسليم إصلاح البوابة', $res['actions'][1]['detail']);

        // صفر آثار جانبية — لا إشعار ولا مهمة أُنشئا
        $this->assertSame($before, [HubNotification::count(), Task::count()]);
    }

    public function test_simulate_reports_condition_failure(): void
    {
        $this->seedCore();
        $f = $this->flow();
        $t = Task::create(['title' => 'تحديث الشعار', 'priority' => 'منخفضة', 'status' => 'منجزة']);

        $res = FlowRunner::simulate($f, 'tasks', $t, 'منجزة');
        $this->assertFalse($res['ok']);
        $this->assertTrue($res['statusMatch']);
        $this->assertFalse($res['condPass']);
        $this->assertStringContainsString('عاجلة', $res['condWhy']);
    }

    public function test_simulate_reports_status_mismatch(): void
    {
        $this->seedCore();
        $f = $this->flow();
        $t = Task::create(['title' => 'مهمة', 'priority' => 'عاجلة', 'status' => 'قيد التنفيذ']);

        $res = FlowRunner::simulate($f, 'tasks', $t, 'قيد التنفيذ');
        $this->assertFalse($res['ok']);
        $this->assertFalse($res['statusMatch']);
    }

    public function test_sandbox_page_is_owner_only_and_renders(): void
    {
        $this->seedCore();
        $f = $this->flow();
        $t = Task::create(['title' => 'إصلاح البوابة', 'priority' => 'عاجلة', 'status' => 'منجزة']);

        $this->actingAs($this->employee)->get('/admin/flows/' . $f->id . '/sandbox')->assertForbidden();

        $this->actingAs($this->owner)->get('/admin/flows/' . $f->id . '/sandbox?rid=' . $t->id)
            ->assertOk()
            ->assertSee('سيعمل هذا المسار')
            ->assertSee('أُنجزت: إصلاح البوابة');

        // ولا آثار جانبية من فتح الصفحة
        $this->assertSame(0, HubNotification::where('kind', 'flow')->count());
    }
}
