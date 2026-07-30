<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\FinDocument;
use App\Models\Task;
use App\Support\HubEvents;
use Tests\TestCase;

/**
 * ناقل الأحداث: الخام يبقى كما كان حرفياً، والدلالي يُضاف فوقه.
 * أي انزلاق هنا يكسر كل مسار وكل ويبهوك في النظام، فالتغطية على السلوك لا على الشكل.
 */
class HubEventsTest extends TestCase
{
    /** مشترك اختباري يسجّل ما يصله */
    private function record(array &$seen): void
    {
        HubEvents::forgetListeners();
        HubEvents::listen(function (string $e, string $mod, $m, ?string $to) use (&$seen) {
            $seen[] = $e;
        });
    }

    protected function tearDown(): void
    {
        HubEvents::forgetListeners();
        parent::tearDown();
    }

    public function test_raw_events_still_reach_subscribers(): void
    {
        $this->seedCore();
        $seen = []; $this->record($seen);

        $t = Task::create(['title' => 'مهمة', 'status' => 'جديدة']);
        HubEvents::dispatch('created', 'tasks', $t);
        HubEvents::dispatch('updated', 'tasks', $t);

        $this->assertContains('created', $seen);
        $this->assertContains('updated', $seen);
    }

    public function test_semantic_event_is_emitted_alongside_the_raw_one(): void
    {
        $this->seedCore();
        $seen = []; $this->record($seen);

        $t = Task::create(['title' => 'مهمة', 'status' => 'منجزة']);
        HubEvents::dispatch('status', 'tasks', $t, 'منجزة');

        $this->assertContains('status', $seen, 'الخام لا يختفي');
        $this->assertContains('task.completed', $seen, 'والدلالي يُضاف');
    }

    public function test_semantic_matching_ignores_diacritics(): void
    {
        $this->seedCore();
        $seen = []; $this->record($seen);

        // «مُنجزة» بضمّة — الخريطة تحمل «منجزة» والمطابقة مُطبَّعة
        $t = Task::create(['title' => 'مهمة', 'status' => 'مُنجزة']);
        HubEvents::dispatch('status', 'tasks', $t, 'مُنجزة');

        $this->assertContains('task.completed', $seen);
    }

    public function test_non_matching_status_emits_no_semantic_event(): void
    {
        $this->seedCore();
        $seen = []; $this->record($seen);

        $t = Task::create(['title' => 'مهمة', 'status' => 'قيد التنفيذ']);
        HubEvents::dispatch('status', 'tasks', $t, 'قيد التنفيذ');

        $this->assertSame(['status'], $seen);
    }

    /** قاعدة بلا `to` تُطابق أي تحوّل — والمطابقتان تجتمعان بلا تكرار */
    public function test_wildcard_and_specific_rules_both_fire(): void
    {
        $this->seedCore();
        $seen = []; $this->record($seen);

        $p = \App\Models\Project::create(['name' => 'مشروع', 'status' => 'مكتمل']);
        HubEvents::dispatch('status', 'projects', $p, 'مكتمل');

        $this->assertContains('project.completed', $seen);
        $this->assertContains('project.status_changed', $seen);
        $this->assertSame(count($seen), count(array_unique($seen)), 'لا يتكرر حدث');
    }

    public function test_semantic_events_do_not_cascade(): void
    {
        $this->seedCore();
        $seen = []; $this->record($seen);

        $f = FinDocument::create(['doc_no' => 'INV-B', 'kind' => 'فاتورة مبيعات',
            'total' => 10, 'date' => now()->toDateString(), 'state' => 'مدفوعة']);
        HubEvents::dispatch('status', 'fin', $f, 'مدفوعة');

        // حدثان بالضبط: الخام والدلالي — لا ارتداد ولا دورة
        $this->assertSame(['status', 'invoice.paid'], $seen);
    }

    public function test_a_failing_subscriber_does_not_stop_the_others(): void
    {
        $this->seedCore();
        $seen = [];
        HubEvents::forgetListeners();
        HubEvents::listen(function () { throw new \RuntimeException('مشترك متعثّر'); });
        HubEvents::listen(function (string $e) use (&$seen) { $seen[] = $e; });

        $c = Contract::create(['title' => 'عقد', 'type' => 'خدمات', 'status' => 'ساري']);
        HubEvents::dispatch('status', 'contracts', $c, 'ساري');

        $this->assertContains('contract.signed', $seen);
    }

    /** كل قيمة في الخريطة يجب أن تكون حالةً يُصرّح بها السجل فعلاً — وإلا فحدثٌ لا يقع أبداً */
    public function test_every_mapped_status_is_actually_declared(): void
    {
        $declared = array_map('hub_ar_norm', hub_declared_states());

        foreach ((array) config('hub.events') as $module => $rules) {
            $this->assertNotNull(hub_mod($module), "وحدة غير معروفة في الخريطة: {$module}");
            foreach ($rules as $r) {
                foreach ((array) ($r['to'] ?? []) as $s) {
                    $this->assertContains(hub_ar_norm($s), $declared,
                        "«{$s}» في خريطة {$module} ليست حالة مُصرَّحاً بها — حدث لا يقع");
                }
            }
        }
    }

    /** مسارٌ مبنيّ على حدث دلالي يعمل فعلاً عند تحوّل الحالة — لا مجرد بثّ اسم */
    public function test_a_flow_bound_to_a_semantic_event_actually_runs(): void
    {
        $this->seedCore();
        HubEvents::forgetListeners();

        $flow = \App\Models\Flow::create([
            'name' => 'عند إنجاز مهمة', 'module' => 'tasks', 'event' => 'task.completed',
            'enabled' => true, 'cond_op' => 'eq', 'runs' => 0,
            'actions' => [['type' => 'notify', 'to' => 'owners', 'text' => 'أُنجزت']],
        ]);

        $t = Task::create(['title' => 'مهمة', 'status' => 'منجزة']);
        \App\Support\FlowRunner::fire('status', 'tasks', $t, 'منجزة');

        $this->assertSame(1, (int) $flow->fresh()->runs, 'المسار الدلالي لم يُنفَّذ');
    }

    /** الطريق الكامل: تعديل عبر HTTP ← المتحكم ← الناقل ← المسار الدلالي ← إشعار */
    public function test_editing_a_record_over_http_triggers_the_semantic_flow(): void
    {
        $this->seedCore();
        HubEvents::forgetListeners();

        $flow = \App\Models\Flow::create([
            'name' => 'عند إنجاز مهمة', 'module' => 'tasks', 'event' => 'task.completed',
            'enabled' => true, 'cond_op' => 'eq', 'runs' => 0,
            'actions' => [['type' => 'notify', 'to' => 'owners', 'text' => 'أُنجزت مهمة']],
        ]);
        $p = \App\Models\Project::create(['name' => 'مشروع']);
        $t = Task::create(['title' => 'مهمة', 'status' => 'قيد التنفيذ', 'project_id' => $p->id]);

        $this->actingAs($this->owner)
            ->put('/m/tasks/' . $t->id, ['title' => 'مهمة', 'projectId' => $p->id, 'status' => 'منجزة'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('منجزة', $t->fresh()->status, 'التعديل لم يُحفظ');
        $this->assertSame(1, (int) $flow->fresh()->runs, 'المسار الدلالي لم يعمل من المسار الكامل');
        $this->assertGreaterThan(0, \App\Models\HubNotification::count(), 'لم يصل إشعار');
    }

    public function test_flow_runner_fire_still_works_through_the_bus(): void
    {
        $this->seedCore();
        $seen = []; $this->record($seen);

        $t = Task::create(['title' => 'مهمة', 'status' => 'مكتملة']);
        \App\Support\FlowRunner::fire('status', 'tasks', $t, 'مكتملة');

        $this->assertContains('status', $seen);
        $this->assertContains('task.completed', $seen);
    }
}
