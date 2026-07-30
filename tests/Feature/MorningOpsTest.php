<?php

namespace Tests\Feature;

use App\Models\FinDocument;
use App\Models\Project;
use App\Models\Task;
use Tests\TestCase;

class MorningOpsTest extends TestCase
{
    public function test_quiet_day_shows_nothing_to_do(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/morning')->assertOk()
            ->assertSee('لا شيء يحتاج تدخلك الآن');
    }

    public function test_overdue_task_and_due_invoice_surface(): void
    {
        $this->seedCore();
        Task::create(['title' => 'مهمة فاتت', 'due' => now()->subDays(3)->toDateString(), 'status' => 'جديدة']);
        FinDocument::create(['doc_no' => 'INV-M1', 'kind' => 'فاتورة', 'partner' => 'عميل',
            'total' => 900, 'paid' => 100, 'due' => now()->addDays(2)->toDateString(),
            'date' => now()->toDateString()]);

        $r = $this->actingAs($this->owner)->get('/morning')->assertOk();
        $r->assertSee('مهمة فاتت');
        $r->assertSee('مهام تجاوزت موعدها');
        $r->assertSee('INV-M1');
        $r->assertSee('مستحقات خلال أسبوع');
    }

    public function test_a_paid_invoice_and_a_done_task_do_not_surface(): void
    {
        $this->seedCore();
        Task::create(['title' => 'مهمة منجزة قديمة', 'due' => now()->subDays(5)->toDateString(), 'status' => 'منجزة']);
        FinDocument::create(['doc_no' => 'INV-PAID', 'kind' => 'فاتورة', 'total' => 500, 'paid' => 500,
            'due' => now()->addDay()->toDateString(), 'date' => now()->toDateString()]);

        $r = $this->actingAs($this->owner)->get('/morning')->assertOk();
        $r->assertDontSee('مهمة منجزة قديمة');
        $r->assertDontSee('INV-PAID');
    }

    public function test_owner_only_operational_alerts(): void
    {
        $this->seedCore();
        \App\Support\ErrorLog::capture('php', 'خطأ للاختبار', '/x.php', 1);

        $this->actingAs($this->owner)->get('/morning')->assertOk()->assertSee('تنبيهات تشغيلية وأمنية');
        $this->actingAs($this->employee)->get('/morning')->assertOk()->assertDontSee('تنبيهات تشغيلية وأمنية');
    }

    public function test_project_health_score_is_explainable(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع صحي', 'status' => 'قيد التنفيذ',
            'start_date' => now()->subDays(30)->toDateString(),
            'launch_exp' => now()->addDays(30)->toDateString()]);

        $h = hub_project_health($p->id, true);
        $this->assertSame(100, $h['score']);                 // بلا تأخير ولا تجاوز ولا مخاطر
        $this->assertSame('سليم', $h['label']);
        $this->assertCount(6, $h['factors']);
        $this->assertSame(100, array_sum(array_column($h['factors'], 'w')));   // الأوزان تساوي ١٠٠٪

        // مشروع متأخر بمهام فائتة يهبط
        $bad = Project::create(['name' => 'مشروع متعثر', 'status' => 'قيد التنفيذ',
            'start_date' => now()->subDays(120)->toDateString(),
            'launch_exp' => now()->subDays(40)->toDateString(), 'budget' => 100]);
        Task::create(['title' => 'متأخرة', 'project_id' => $bad->id,
            'due' => now()->subDays(20)->toDateString(), 'status' => 'جديدة']);

        $hb = hub_project_health($bad->id, true);
        $this->assertLessThan($h['score'], $hb['score']);
        $this->assertContains($hb['tone'], ['wn', 'bad']);
    }
}
