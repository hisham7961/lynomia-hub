<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **من أُسنِد إليه العملُ يراه** — وإلّا فالإسنادُ كلامٌ لا يُنفَّذ.
 *
 * كان نطاقُ `hub_scope` للمحدودِ بمشاريعه يرشّح بعمودِ المشروعِ وحدَه، فمهمّةٌ
 * تُسنَد إلى موظّفٍ على مشروعٍ ليس من مشاريعه **تختفي عنه**: لا في قائمتِه،
 * ولا بالرابطِ المباشر (٤٠٤).
 *
 * والأسوأُ أنّ الحلقةَ تُغلَق على المستخدم: منتقي الإسنادِ يعرض **كلَّ** الزملاء
 * بلا تمييز، والحفظُ ينجح، **ويصل الموظّفَ إشعارٌ صريح** «أُسند إليك في المهام:
 * …» — فيضغطه فيُردّ ٤٠٤ عن عملٍ أُخبر أنّه له. (وقعت حيّاً في اليوم ٦ من
 * محاكاة الشهر: سالم أسند إلى أحمد، والإشعارُ وصل، والبابُ مغلق.)
 *
 * فالإسنادُ نفسُه سببُ وصولٍ — كما هي مِلكيّةُ السجلّ في وحداتٍ أخرى. والتوسعةُ
 * هنا **لا تكشف جديداً**: الإشعارُ أفشى العنوانَ سلفاً، وإنّما تَصِل صاحبَه به.
 */
class AssigneeSeesAssignedWorkTest extends TestCase
{
    public function test_a_project_scoped_employee_sees_a_task_assigned_to_them(): void
    {
        $this->seedCore();

        $role = Role::create(['name' => 'منفّذٌ محدودُ النطاق', 'scope' => 'proj',
            'flags' => [], 'matrix' => ['tasks' => ['v' => 1, 'e' => 1]]]);

        $emp = User::create(['name' => 'منفّذ', 'email' => 'assignee@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'internal', 'password_changed_at' => now()]);

        // العضويّةُ من المشروعِ نفسِه (manager_id أو members) لا من عمودٍ في المستخدم
        $mine   = Project::create(['name' => 'مشروعٌ من مشاريعه', 'members' => [$emp->id]]);
        $theirs = Project::create(['name' => 'مشروعٌ ليس من مشاريعه']);
        \Illuminate\Support\Facades\Cache::flush();   // visibleProjectIds مخبّأةٌ ٣٠٠ ثانية

        $near = Task::create(['title' => 'مهمّةٌ على مشروعه',     'project_id' => $mine->id]);
        $far  = Task::create(['title' => 'مهمّةٌ أُسندت إليه بعيداً', 'project_id' => $theirs->id,
                              'assignee_id' => $emp->id]);
        $none = Task::create(['title' => 'مهمّةٌ بعيدةٌ ليست له',   'project_id' => $theirs->id]);

        $this->actingAs($emp);

        $seen = hub_scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks')
            ->pluck('id')->all();

        $this->assertContains($near->id, $seen, 'مهمّةُ مشروعِه تبقى مرئيّةً كما كانت');
        $this->assertContains($far->id, $seen,
            'المهمّةُ المسنَدةُ إليه تُرى ولو كانت على مشروعٍ خارجَ نطاقه — وإلّا فالإشعارُ يقود إلى ٤٠٤');
        $this->assertNotContains($none->id, $seen,
            'ولا يتوسّع النطاقُ إلى ما لم يُسنَد إليه — العزلُ باقٍ على ما سواه');
    }

    /**
     * **والتوسعةُ لا تخترق عزلَ الشركة.** فرعُ «المسنَدُ إليه» يُضاف داخلَ شرطِ
     * النطاقِ وحدَه، وعزلُ الشركةِ يُطبَّق **بعدَه** بـ`whereIn` مستقلّة — فيضيّق
     * ولا يوسّع. وهذا هو الضمانُ الذي يجعل التوسعةَ آمنة، فيُثبَّت لا يُدَّعى.
     */
    public function test_the_assignee_widening_never_crosses_company_isolation(): void
    {
        $this->seedCore();

        $mine   = \App\Models\Company::create(['name_ar' => 'شركتُه']);
        $theirs = \App\Models\Company::create(['name_ar' => 'شركةٌ أخرى']);

        $role = Role::create(['name' => 'منفّذٌ معزولٌ على شركة', 'scope' => 'proj',
            'flags' => [], 'matrix' => ['tasks' => ['v' => 1]]]);

        $emp = User::create(['name' => 'معزول', 'email' => 'isolated@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'internal', 'password_changed_at' => now(),
            'companies' => [$mine->id]]);

        $far = Project::create(['name' => 'مشروعٌ خارجَ نطاقه']);
        \Illuminate\Support\Facades\Cache::flush();

        $inCompany  = Task::create(['title' => 'مسنَدةٌ إليه داخلَ شركتِه',
            'project_id' => $far->id, 'assignee_id' => $emp->id, 'company_id' => $mine->id]);
        $outCompany = Task::create(['title' => 'مسنَدةٌ إليه في شركةٍ أخرى',
            'project_id' => $far->id, 'assignee_id' => $emp->id, 'company_id' => $theirs->id]);

        $this->actingAs($emp);

        $seen = hub_scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks')->pluck('id')->all();

        $this->assertContains($inCompany->id, $seen,
            'المسنَدةُ إليه داخلَ شركتِه تُرى — التوسعةُ تعمل');
        $this->assertNotContains($outCompany->id, $seen,
            'والمسنَدةُ إليه في شركةٍ أخرى تبقى محجوبة — عزلُ الشركةِ فوقَ التوسعةِ لا تحتَها');
    }
}
