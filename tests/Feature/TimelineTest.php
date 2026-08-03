<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الخط الزمني الموحَّد: البيانات كانت موجودة في أربعة جداول ولا أحد يدمجها.
 */
class TimelineTest extends TestCase
{
    private function seedRecord(): Task
    {
        $this->seedCore();
        $p = \App\Models\Project::create(['name' => 'مشروع']);
        $t = Task::create(['title' => 'مهمة الخط الزمني', 'status' => 'جديدة', 'project_id' => $p->id]);

        DB::table('comments')->insert(['id' => (string) Str::uuid(), 'module' => 'tasks',
            'record_id' => $t->id, 'user_id' => $this->owner->id, 'body' => 'تعليق على المهمة',
            'created_at' => now()->subMinutes(3), 'updated_at' => now()]);

        DB::table('attachments')->insert(['id' => (string) Str::uuid(), 'module' => 'tasks',
            'record_id' => $t->id, 'disk' => 'local', 'path' => 'x/y.pdf',
            'original_name' => 'المواصفات.pdf', 'uploaded_by' => $this->owner->id,
            'created_at' => now()->subMinutes(2), 'updated_at' => now()]);

        DB::table('record_versions')->insert(['module' => 'tasks',
            'record_id' => $t->id, 'version' => 2, 'snapshot' => '{}',
            'changed_by' => $this->owner->id, 'created_at' => now()->subMinute()]);

        return $t;
    }

    public function test_timeline_merges_all_four_sources(): void
    {
        $t = $this->seedRecord();
        $labels = collect(hub_timeline('tasks', $t->id))->pluck('label');

        $this->assertTrue($labels->contains('تعليق'));
        $this->assertTrue($labels->contains('مرفق'));
        $this->assertTrue($labels->contains('نسخة'));
        // الإنشاء يُسجَّل تلقائياً بسمة Auditable
        $this->assertTrue($labels->contains('إضافة'), 'قيد التدقيق التلقائي غائب');
    }

    public function test_timeline_is_newest_first_and_capped(): void
    {
        $t = $this->seedRecord();
        $ev = hub_timeline('tasks', $t->id);

        $ats = array_column($ev, 'at');
        $sorted = $ats; rsort($sorted);
        $this->assertSame($sorted, $ats, 'الأحدث أولاً');

        $this->assertLessThanOrEqual(5, count(hub_timeline('tasks', $t->id, 5)));
    }

    public function test_timeline_names_the_actor(): void
    {
        $t = $this->seedRecord();
        $who = collect(hub_timeline('tasks', $t->id))->pluck('who')->filter()->unique();
        $this->assertTrue($who->contains($this->owner->name));
    }

    /** الخط الزمني يقول من فعل ماذا — لا يُخرج القيم القديمة، لها سجل الإصدارات */
    public function test_timeline_never_leaks_before_after_values(): void
    {
        $t = $this->seedRecord();
        DB::table('audits')->insert(['module' => 'tasks',
            'record_id' => $t->id, 'action' => 'تعديل', 'user_id' => $this->owner->id,
            'before' => json_encode(['secret' => 'قيمة-سرية-قديمة']),
            'after' => json_encode(['secret' => 'قيمة-سرية-جديدة']),
            'created_at' => now()]);

        $blob = json_encode(hub_timeline('tasks', $t->id), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('قيمة-سرية-قديمة', $blob);
        $this->assertStringNotContainsString('قيمة-سرية-جديدة', $blob);
    }

    public function test_timeline_is_isolated_per_record(): void
    {
        $t = $this->seedRecord();
        $other = Task::create(['title' => 'مهمة أخرى', 'project_id' => $t->project_id]);

        $titles = collect(hub_timeline('tasks', $other->id))->pluck('title');
        $this->assertFalse($titles->contains('تعليق على المهمة'));
        $this->assertFalse($titles->contains('المواصفات.pdf'));
    }

    public function test_timeline_renders_on_every_module_record_page(): void
    {
        $t = $this->seedRecord();

        $this->actingAs($this->owner)->get('/m/tasks/' . $t->id)
            ->assertOk()
            ->assertSee('ما جرى على هذا السجل')
            ->assertSee('تعليق على المهمة')
            ->assertSee('المواصفات.pdf');
    }
}
