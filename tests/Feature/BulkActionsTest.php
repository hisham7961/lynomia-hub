<?php

namespace Tests\Feature;

use App\Models\Task;
use Tests\TestCase;

/** v2.114: الإجراءات الجماعية — كل سجل يمر ببوابات مساره الفردي (نطاق/صلاحية/مسارات عمل) */
class BulkActionsTest extends TestCase
{
    public function test_bulk_status_changes_selected_records_only(): void
    {
        $this->seedCore();
        $a = Task::create(['title' => 'أ', 'status' => 'جديدة']);
        $b = Task::create(['title' => 'ب', 'status' => 'جديدة']);
        $c = Task::create(['title' => 'ج', 'status' => 'جديدة']);

        $this->actingAs($this->owner)
            ->post('/m/tasks/bulk', ['do' => 'status', 'status' => 'قيد التنفيذ', 'ids' => [$a->id, $b->id]])
            ->assertRedirect();

        $this->assertSame('قيد التنفيذ', $a->fresh()->status);
        $this->assertSame('قيد التنفيذ', $b->fresh()->status);
        $this->assertSame('جديدة', $c->fresh()->status);       // غير المحدد لا يُمس
    }

    public function test_bulk_status_rejects_unknown_status_value(): void
    {
        $this->seedCore();
        $a = Task::create(['title' => 'أ', 'status' => 'جديدة']);

        $this->actingAs($this->owner)
            ->post('/m/tasks/bulk', ['do' => 'status', 'status' => 'حالة مخترعة', 'ids' => [$a->id]])
            ->assertStatus(422);
        $this->assertSame('جديدة', $a->fresh()->status);
    }

    public function test_bulk_delete_requires_delete_permission(): void
    {
        $this->seedCore();
        $a = Task::create(['title' => 'محمية', 'status' => 'جديدة']);

        // الموظف (d=0) يُصد بـ403، والسجل يبقى
        $this->actingAs($this->employee)
            ->post('/m/tasks/bulk', ['do' => 'delete', 'ids' => [$a->id]])
            ->assertStatus(403);
        $this->assertNotNull(Task::find($a->id));

        // المالك يحذف فعلاً (سلة لا محو)
        $this->actingAs($this->owner)
            ->post('/m/tasks/bulk', ['do' => 'delete', 'ids' => [$a->id]])
            ->assertRedirect();
        $this->assertNull(Task::find($a->id));
        $this->assertNotNull(Task::withTrashed()->find($a->id));
    }

    public function test_bulk_export_returns_only_selected_rows_and_audits(): void
    {
        $this->seedCore();
        $a = Task::create(['title' => 'مُصدَّرة', 'status' => 'جديدة']);
        Task::create(['title' => 'خارج التحديد', 'status' => 'جديدة']);

        $res = $this->actingAs($this->owner)
            ->post('/m/tasks/bulk', ['do' => 'export', 'ids' => [$a->id]])
            ->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringContainsString('مُصدَّرة', $csv);
        $this->assertStringNotContainsString('خارج التحديد', $csv);

        // بصمة التصدير الجماعي في التدقيق
        $this->assertTrue(
            \Illuminate\Support\Facades\DB::table('audits')->where('action', 'تصدير')
                ->where('name', 'like', '%جماعي%')->exists()
        );
    }

    public function test_bulk_without_ids_or_unknown_action_fails(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/m/tasks/bulk', ['do' => 'status', 'status' => 'جديدة'])->assertStatus(400);
        $this->actingAs($this->owner)->post('/m/tasks/bulk', ['do' => 'evil', 'ids' => ['x']])->assertStatus(400);
    }

    public function test_list_page_shows_selection_ui_only_with_bulk_rights(): void
    {
        $this->seedCore();
        Task::create(['title' => 'صف', 'status' => 'جديدة']);

        $owner = $this->actingAs($this->owner)->get('/m/tasks')->assertOk()->getContent();
        $this->assertStringContainsString('id="ballsel"', $owner);
        $this->assertStringContainsString('id="bulkbar"', $owner);
        $this->assertStringContainsString('تغيير الحالة', $owner);

        // المشاهد (عرض فقط، بلا تصدير): لا أعمدة تحديد ولا شريط
        $viewer = $this->actingAs($this->viewer)->get('/m/tasks')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="ballsel"', $viewer);
        $this->assertStringNotContainsString('id="bulkbar"', $viewer);
    }
}
