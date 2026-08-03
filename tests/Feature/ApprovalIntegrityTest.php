<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * سلامة طابور الموافقات — عيوب الموجة الثانية من الفحص:
 * (١) سحب الكانبان كان يكتب الحالة مباشرةً متجاوزاً hub_needs_approval
 *     الذي يفرضه التعديل الفردي والجماعي والاستعادة كلها؛
 * (٢) «approvals» وحدة CRUD وحقل status فيها sel قابل للكتابة — فمن يملك
 *     approvals:e يقلب «مرفوض» إلى «معلّق» أو يزوّر «معتمد» بلا حاسمٍ ولا وقت؛
 * (٣) الحسم يقرأ ويكتب بلا قفلٍ ولا معاملة — سباق اعتماد/رفض ينفّذ ويقول «رُفض»؛
 * (٤) حارس التقادم (meta.ver) كان للتعديل فقط — اعتمادُ حذفٍ ينفّذ على سجلٍ
 *     تغيّر بعد الطلب.
 */
class ApprovalIntegrityTest extends TestCase
{
    protected function seedTask(): Task
    {
        $p = Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);

        return Task::create(['title' => 'مهمة', 'status' => 'جديدة', 'project_id' => $p->id]);
    }

    /* ── ١) الكانبان لا يلتفّ على الطابور ── */

    public function test_kanban_drag_respects_approval_rules(): void
    {
        $this->seedCore();
        $this->hubSetting('approval.rules', 'tasks:e');
        $t = $this->seedTask();

        // موظفٌ تعديلُه مشروط بالموافقات يسحب البطاقة — يُردّ للمسار الموثَّق
        $this->actingAs($this->employee)
            ->post("/m/tasks/{$t->id}/status", ['status' => 'قيد التنفيذ'])
            ->assertStatus(422);

        $this->assertSame('جديدة', $t->fresh()->status,
            'سحبُ الكانبان كتب الحالة مباشرةً متجاوزاً طابور الموافقات');

        // والمعتمِد والمالك يسحبان بحرية — القاعدة لغير المعفيين وحدهم
        $this->actingAs($this->owner)
            ->post("/m/tasks/{$t->id}/status", ['status' => 'قيد التنفيذ'])->assertOk();
        $this->assertSame('قيد التنفيذ', $t->fresh()->status);
    }

    /* ── ٢) حالة الحسم لا تُكتب من CRUD ── */

    public function test_decision_state_cannot_be_forged_from_crud(): void
    {
        $this->seedCore();
        $id = (string) Str::uuid();
        DB::table('approvals')->insert(['id' => $id, 'title' => 'طلب مرفوض', 'type' => 'مصروف',
            'status' => 'مرفوض', 'decided_by' => $this->owner->id, 'decided_at' => now(),
            'requested_by' => $this->employee->id, 'created_at' => now(), 'updated_at' => now()]);

        // حتى المالك لا يقلب الحسم من نموذج CRUD — الحسم من مساره وحده كي
        // لا توجد حالةُ «معتمد» بلا حاسمٍ ووقتٍ يُحتجّ بهما
        $this->actingAs($this->owner)->put("/m/approvals/{$id}", [
            'title' => 'طلب مرفوض', 'type' => 'مصروف', 'status' => 'معلّق',
        ]);
        $this->assertSame('مرفوض', Approval::find($id)->status,
            'حالة الحسم قُلبت من نموذج CRUD — الرفض يُنقض والاعتماد يُزوَّر');

        // والنموذج يعرضها «قراءة فقط» لا قائمة اختيار
        $html = $this->actingAs($this->owner)->get("/m/approvals/{$id}/edit")->getContent();
        $this->assertMatchesRegularExpression('/حالة الموافقة.*قراءة فقط/su', $html);
    }

    /* ── ٣) لا حسم مزدوج ولو عُبث بالحالة ── */

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $this->seedCore();
        $t = $this->seedTask();
        $id = (string) Str::uuid();
        // الحالة تقول «معلّق» لكن decided_at مختوم — عبثٌ أو سباق: يُرفض
        DB::table('approvals')->insert(['id' => $id, 'title' => 'تعديل', 'type' => 'تعديل',
            'mod' => 'tasks', 'record_id' => $t->id, 'op' => 'e', 'status' => 'معلّق',
            'decided_by' => $this->owner->id, 'decided_at' => now(),
            'payload' => json_encode(['title' => 'مزوّر'], JSON_UNESCAPED_UNICODE),
            'requested_by' => $this->employee->id,
            'meta' => json_encode(['ver' => $t->version]),
            'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->owner)->post("/approvals/{$id}/approve")->assertStatus(422);
        $this->assertSame('مهمة', $t->fresh()->title, 'نُفّذ طلبٌ سبق حسمه');

        $this->actingAs($this->owner)->post("/approvals/{$id}/reject")->assertStatus(422);
    }

    /* ── ٤) حارس التقادم يشمل الحذف ── */

    public function test_delete_approval_on_a_changed_record_is_refused(): void
    {
        $this->seedCore();
        $t = $this->seedTask();
        $id = (string) Str::uuid();
        DB::table('approvals')->insert(['id' => $id, 'title' => 'حذف مهمة', 'type' => 'حذف',
            'mod' => 'tasks', 'record_id' => $t->id, 'op' => 'd', 'status' => 'معلّق',
            'requested_by' => $this->employee->id,
            'meta' => json_encode(['ver' => (int) $t->version]),
            'created_at' => now(), 'updated_at' => now()]);

        // السجل يتغيّر بعد تصفيف الطلب — المعتمِد رأى نسخةً لم تعد موجودة
        $t->update(['title' => 'أُثري بعد الطلب']);

        $this->actingAs($this->owner)->post("/approvals/{$id}/approve")->assertSessionHasErrors();
        $this->assertNull($t->fresh()->deleted_at,
            'اعتمادُ الحذف نُفّذ على سجلٍ تغيّر بعد الطلب — حُذف محتوى لم يره المعتمِد');
        $this->assertSame('معلّق', Approval::find($id)->status);
    }

    /* ── ٥) الحسم داخل معاملة بقفل — حارس مصدر ── */

    public function test_decision_path_locks_inside_a_transaction(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Web/ApprovalDecisionController.php'));
        $this->assertStringContainsString('lockForUpdate', $src,
            'الحسم بلا قفل — معتمِدان متزامنان يجتازان فحص «معلّق» معاً');
        $this->assertStringContainsString('DB::transaction', $src,
            'الحسم بلا معاملة — سباق اعتماد/رفض ينفّذ ويكتب «مرفوض»');
    }
}
