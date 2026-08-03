<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **أربعة أبوابٍ تكتب أو تقرأ بلا حارسها.**
 *
 * المسار الفردي محروسٌ في كل حالة — والباب المجاور يفعل الشيء نفسه بلا حارس.
 * الحارسُ الذي يُطبَّق في بابٍ ويُنسى في آخر ليس حارساً، بل قناعةٌ كاذبة:
 *
 *   ١) **بوابةُ الملفات لها بابٌ خلفيّ**: بعد فحصٍ دقيق للوحدة والنطاق، تعود
 *      الدالةُ `true` لأي مسارٍ موجودٍ في `attachments` أو `inbox_documents`
 *      **بلا صلاحيةٍ ولا نطاق**. تعليقُها يقول «لهما بوابتاهما المحروستان» —
 *      وهذه هي البوابة.
 *
 *   ٢) **الحالةُ بالجملة بلا حارس**: المسار الفردي يفحص وضع الحقل ويمرّ
 *      بالموافقات، والجماعيُّ يكتب مباشرة — ويقرأ **مفتاح** الحقل لا عموده.
 *
 *   ٣) **استعادةُ نسخةٍ قديمة تكتب كل عمود** بلا وضع حقلٍ ولا موافقة: من لا
 *      يرى الراتب يستعيد نسخةً فيُعيد كتابته.
 *
 *   ٤) **تحويلُ تعليقٍ لمهمة بلا فحص هدفه**: نصُّ التعليق يُنسخ في وصف المهمة،
 *      فيقرأ المُحوِّل تعليقاً على سجلٍّ لا يملك وحدته أصلاً.
 */
class GuardedWritePathTest extends TestCase
{
    /** مستخدمٌ بصلاحياتٍ محددة */
    protected function userWith(array $matrix, array $flags = [], string $scope = 'all'): User
    {
        $role = Role::create(['name' => 'دور ' . Str::random(5), 'scope' => $scope,
            'flags' => $flags, 'matrix' => $matrix]);

        return User::create(['name' => 'مستخدم', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /* ── ١) بوابة الملفات ── */

    public function test_a_path_in_attachments_is_not_a_free_pass(): void
    {
        $this->seedCore();
        $emp = Employee::create(['name' => 'موظف', 'status' => 'نشط']);
        DB::table('attachments')->insert(['id' => (string) Str::uuid(), 'module' => 'hr',
            'record_id' => $emp->id, 'path' => 'hub/att/secret-contract.pdf',
            'original_name' => 'عقد.pdf', 'disk' => 'local',
            'created_at' => now(), 'updated_at' => now()]);

        // لا صلاحية على الموارد البشرية بتاتاً
        $u = $this->userWith(['tasks' => ['v' => 1]]);

        $this->actingAs($u)->get('/files/hub/att/secret-contract.pdf')->assertForbidden();
    }

    public function test_a_path_in_the_document_inbox_is_not_a_free_pass(): void
    {
        $this->seedCore();
        DB::table('inbox_documents')->insert(['id' => (string) Str::uuid(), 'orig' => 'وارد.pdf',
            'path' => 'hub/inboxdocs/incoming.pdf', 'status' => 'وارد',
            'uploaded_by' => $this->owner->id, 'created_at' => now()]);

        $u = $this->userWith(['tasks' => ['v' => 1]]);

        $this->actingAs($u)->get('/files/hub/inboxdocs/incoming.pdf')->assertForbidden();
    }

    /** ومن يملك الوحدة يقرأ مرفقها كما كان — الإصلاح لا يكسر ما يعمل */
    public function test_the_owner_of_the_module_still_reads_its_attachment(): void
    {
        $this->seedCore();
        $emp = Employee::create(['name' => 'موظف', 'status' => 'نشط']);
        DB::table('attachments')->insert(['id' => (string) Str::uuid(), 'module' => 'hr',
            'record_id' => $emp->id, 'path' => 'hub/att/ok.pdf',
            'original_name' => 'ورقة.pdf', 'disk' => 'local',
            'created_at' => now(), 'updated_at' => now()]);

        $u = $this->userWith(['hr' => ['v' => 1]]);

        // الملف غير موجودٍ على القرص فيقع ٤٠٤ — والمهم أنه ليس ٤٠٣
        $this->actingAs($u)->get('/files/hub/att/ok.pdf')->assertNotFound();
    }

    /* ── ٢) الحالة بالجملة ── */

    public function test_bulk_status_respects_the_field_mode(): void
    {
        $this->seedCore();
        $p = \App\Models\Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $t = \App\Models\Task::create(['title' => 'مهمة', 'status' => 'جديدة', 'project_id' => $p->id]);

        // حقل الحالة للقراءة فقط عن هذا الدور
        $role = Role::create(['name' => 'قارئ حالة', 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1, 'e' => 1]],
            'field_rules' => ['tasks' => ['status' => 'ro']]]);
        $u = User::create(['name' => 'محدود', 'email' => 'ro@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($u)->post('/m/tasks/bulk',
            ['do' => 'status', 'ids' => [$t->id], 'status' => 'مكتملة']);

        $this->assertSame('جديدة', $t->fresh()->status,
            'حقلُ الحالة للقراءة فقط في المسار الفردي، ويُكتب بالجملة');
    }

    public function test_bulk_status_does_not_bypass_the_approval_queue(): void
    {
        $this->seedCore();
        $this->hubSetting('approval.rules', 'tasks:e');
        $p = \App\Models\Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $t = \App\Models\Task::create(['title' => 'مهمة', 'status' => 'جديدة', 'project_id' => $p->id]);

        $this->actingAs($this->employee)->post('/m/tasks/bulk',
            ['do' => 'status', 'ids' => [$t->id], 'status' => 'مكتملة']);

        $this->assertSame('جديدة', $t->fresh()->status,
            'تعديلُه يمرّ بالموافقات فرداً، ويمرّ بالجملة بلا طلبٍ ولا توثيق');
    }

    /** وحالةُ الوحدة تُكتب في عمودها لا في مفتاح حقلها */
    public function test_bulk_status_writes_the_real_column(): void
    {
        $this->seedCore();
        $id = (string) Str::uuid();
        DB::table('documents')->insert(['id' => $id, 'name' => 'وثيقة',
            'doc_status' => 'فعال', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->owner)->post('/m/files/bulk',
            ['do' => 'status', 'ids' => [$id], 'status' => 'ملغى']);

        $this->assertSame('ملغى', DB::table('documents')->where('id', $id)->value('doc_status'),
            'كُتبت الحالة في مفتاح الحقل لا في عموده — على MySQL: Unknown column');
    }

    /* ── ٣) استعادة نسخة ── */

    public function test_restoring_a_version_respects_field_modes(): void
    {
        $this->seedCore();
        $emp = Employee::create(['name' => 'موظف', 'status' => 'نشط', 'salary' => 1000]);
        $emp->update(['salary' => 2000, 'name' => 'موظف مُعدَّل']);

        // من لا يرى الراتب أصلاً
        $role = Role::create(['name' => 'بلا راتب', 'scope' => 'all', 'flags' => [],
            'matrix' => ['hr' => ['v' => 1, 'e' => 1]],
            'field_rules' => ['hr' => ['salary' => 'hide']]]);
        $u = User::create(['name' => 'محدود', 'email' => 'nosal@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($u)->post("/m/hr/{$emp->id}/versions/1");

        $this->assertEquals(2000, (float) $emp->fresh()->salary,
            'استعاد نسخةً فأعاد كتابة راتبٍ لا يراه أصلاً');
    }

    public function test_restoring_a_version_does_not_bypass_the_approval_queue(): void
    {
        $this->seedCore();
        $this->hubSetting('approval.rules', 'hr:e');
        $emp = Employee::create(['name' => 'الأصل', 'status' => 'نشط']);
        $emp->update(['name' => 'المعدَّل']);

        $this->actingAs($this->employee)->post("/m/hr/{$emp->id}/versions/1");

        $this->assertSame('المعدَّل', $emp->fresh()->name,
            'تعديلُه يمرّ بالموافقات، واستعادةُ نسخةٍ تكتب مباشرةً');
    }

    /* ── ٤) تحويل تعليق لمهمة ── */

    public function test_turning_a_comment_into_a_task_checks_the_comment_target(): void
    {
        $this->seedCore();
        $emp = Employee::create(['name' => 'موظف', 'status' => 'نشط']);
        $cid = (string) Str::uuid();
        DB::table('comments')->insert(['id' => $cid, 'module' => 'hr', 'record_id' => $emp->id,
            'body' => 'راتبه سيُخفَّض الشهر القادم — سرّي', 'user_id' => $this->owner->id,
            'created_at' => now(), 'updated_at' => now()]);

        // يملك إضافة المهام ولا يملك الموارد البشرية
        $u = $this->userWith(['tasks' => ['v' => 1, 'a' => 1]]);

        $this->actingAs($u)->post("/comments/{$cid}/task");

        $this->assertSame(0, \App\Models\Task::where('description', 'LIKE', '%سرّي%')->count(),
            'نُسخ نصُّ تعليقٍ على سجلٍّ لا يملك قارئُه وحدته إلى مهمةٍ يقرؤها');
    }

    /** ومن يملك الوحدة يحوّل كما كان */
    public function test_the_permitted_still_turn_comments_into_tasks(): void
    {
        $this->seedCore();
        $emp = Employee::create(['name' => 'موظف', 'status' => 'نشط']);
        $cid = (string) Str::uuid();
        DB::table('comments')->insert(['id' => $cid, 'module' => 'hr', 'record_id' => $emp->id,
            'body' => 'جهّز عقده', 'user_id' => $this->owner->id,
            'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->owner)->post("/comments/{$cid}/task")->assertRedirect();

        $this->assertSame(1, \App\Models\Task::where('title', 'LIKE', '%جهّز عقده%')->count());
    }
}
