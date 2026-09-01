<?php

namespace Tests\Feature;

use App\Models\ChangeOrder;
use App\Models\Client;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * CPQ المرحلة ج — أوامرُ التغيير: تمديدُ خطّ الأساس بعد الاعتماد بلا مسّ العرض.
 */
class ChangeOrderTest extends TestCase
{
    private function projectWithBaseline(): Project
    {
        $c = Client::create(['name' => 'عميل مشروع']);

        return Project::create([
            'name' => 'مشروع خارجيّ', 'client_id' => $c->id, 'status' => 'تخطيط',
            'rev_exp' => 50000, 'budget' => 30000, 'currency' => 'د.ك',
            'meta' => ['baseline' => ['quote_no' => 'QT-2026-0001', 'amount' => '50000', 'currency' => 'د.ك']],
        ]);
    }

    public function test_doc_no_auto_generates(): void
    {
        $this->seedCore();
        $p = $this->projectWithBaseline();
        $co = ChangeOrder::create(['title' => 'نطاقٌ إضافيّ', 'project_id' => $p->id, 'value_delta' => 10000]);
        $this->assertMatchesRegularExpression('/^CO-\d{4}-\d{4}$/', $co->doc_no);
    }

    public function test_apply_requires_approved_status(): void
    {
        $this->seedCore();
        $p = $this->projectWithBaseline();
        $co = ChangeOrder::create(['title' => 'تغيير', 'project_id' => $p->id, 'value_delta' => 5000, 'status' => 'مسودة']);

        // مسودة لا تُطبَّق
        $this->actingAs($this->owner)->post("/changeorder/{$co->id}/apply")->assertStatus(422);
    }

    public function test_apply_extends_project_baseline_and_is_idempotent(): void
    {
        $this->seedCore();
        $p = $this->projectWithBaseline();
        $co = ChangeOrder::create(['title' => 'ميزةٌ إضافية', 'project_id' => $p->id,
            'value_delta' => 10000, 'cost_delta' => 4000, 'timeline_days' => 14,
            'currency' => 'د.ك', 'status' => 'معتمد', 'approved_by' => 'المالك', 'approved_at' => now()]);

        $this->actingAs($this->owner)->post("/changeorder/{$co->id}/apply")->assertRedirect();

        $p->refresh(); $co->refresh();
        $this->assertSame('مطبَّق', $co->status);
        $this->assertNotNull($co->applied_at);
        // القيمةُ التعاقدية تطوّرت، والتكلفةُ زادت، والأصلُ محفوظ
        $this->assertSame(60000.0, (float) $p->rev_exp);
        $this->assertSame(34000.0, (float) $p->budget);
        $baseline = ((array) $p->meta)['baseline'];
        $this->assertSame('50000', (string) $baseline['amount'], 'القيمةُ الأصلية لا تتغيّر');
        $this->assertCount(1, $baseline['change_orders']);

        // إعادةُ التطبيق لا تُضاعف (idempotent)
        $this->actingAs($this->owner)->post("/changeorder/{$co->id}/apply")->assertRedirect();
        $p->refresh();
        $this->assertSame(60000.0, (float) $p->rev_exp, 'لا تطبيقٌ مزدوج');
        $this->assertCount(1, ((array) $p->meta)['baseline']['change_orders']);
    }

    /**
     * التطبيقُ يُعدّل ماليّةَ المشروع، فيتطلّب صلاحيةَ تعديلِ المشاريع لا أوامرِ
     * التغيير وحدها. حاملُ `changeorders.e` بلا `projects.e` = ٤٠٣.
     */
    public function test_apply_requires_projects_edit_permission(): void
    {
        $this->seedCore();
        $p = $this->projectWithBaseline();
        $co = ChangeOrder::create(['title' => 'تغيير', 'project_id' => $p->id,
            'value_delta' => 5000, 'currency' => 'د.ك', 'status' => 'معتمد']);

        // دورٌ: أوامرُ التغيير تعديل، والمشاريعُ مشاهدةٌ فقط
        $role = Role::create(['name' => 'منسّق أوامر', 'scope' => 'all', 'flags' => [],
            'matrix' => ['changeorders' => ['v' => 1, 'e' => 1], 'projects' => ['v' => 1, 'e' => 0]]]);
        $u = User::create(['name' => 'منسّق', 'email' => 'co@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($u)->post("/changeorder/{$co->id}/apply")->assertForbidden();

        // ماليّةُ المشروع لم تُمَسّ
        $p->refresh();
        $this->assertSame(50000.0, (float) $p->rev_exp, 'طُبِّق أمرُ التغيير بلا صلاحية تعديل المشاريع');
    }

    public function test_pdf_renders_without_internal_cost(): void
    {
        $this->seedCore();
        $p = $this->projectWithBaseline();
        $co = ChangeOrder::create(['title' => 'توسعة', 'project_id' => $p->id,
            'value_delta' => 7000, 'cost_delta' => 2500, 'currency' => 'د.ك', 'status' => 'معتمد']);

        $html = \App\Support\ChangeOrderDoc::html($co->fresh());
        $this->assertStringContainsString('أمرُ تغيير', $html);
        $this->assertStringContainsString('7,000', $html);
        // **لا تكلفةَ داخلية في مستند العميل**
        $this->assertStringNotContainsString('2,500', $html);
        $this->assertStringNotContainsString('2500', $html);

        $res = $this->actingAs($this->owner)->get("/changeorder/{$co->id}/pdf");
        $res->assertOk();
        $this->assertTrue(str_contains($res->headers->get('content-type'), 'pdf')
            || str_contains($res->headers->get('content-type'), 'html'));
    }

    public function test_module_screens_render(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/m/changeorders')->assertOk();
        $this->actingAs($this->owner)->get('/m/changeorders/create')->assertOk();
    }
}
