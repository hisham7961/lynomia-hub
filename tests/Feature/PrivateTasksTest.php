<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **المهمّةُ الخاصّة — تظهر لصاحبها وحدَه** (طلبُ المالك: «لمّا نرسل مهمّة لموظّف تظهر له هو لحاله»).
 *
 * كانت كلُّ مهمّةٍ مرئيّةً لكلِّ من نطاقُه «الكل»: تُسنَد إلى موظّفٍ فيقرؤها زملاؤه جميعاً،
 * ولا يجد صاحبُها في مركز التشغيل اليوميّ بطاقةً بمهامّه — فقط «متأخّراتُ» الجميع.
 * الآن: علَمُ `private` على المهمّة يحصرها في المُسنَد إليه والمشاركين ومُنشئها ومدير
 * مشروعها والمالك — والحصرُ في `hub_scope` فيسري على القائمة والسجلّ والبحث والـAPI واسأل Hub.
 */
class PrivateTasksTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->project = Project::create(['name' => 'مشروعٌ مشترك', 'status' => 'قيد التنفيذ']);
    }

    private function colleague(string $name): User
    {
        return User::create(['name' => $name, 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    private function task(array $attrs, ?User $creator = null): Task
    {
        $t = Task::create($attrs + ['status' => 'جديدة', 'project_id' => $this->project->id]);
        if ($creator) $t->forceFill(['created_by' => $creator->id])->saveQuietly();

        return $t;
    }

    public function test_a_private_task_is_seen_by_its_people_and_hidden_from_everyone_else(): void
    {
        $assignee = $this->colleague('المُسنَد إليه');
        $part = $this->colleague('مشارك');
        $other = $this->colleague('زميلٌ آخر');
        $t = $this->task(['title' => 'مهمةٌ خاصةٌ جداً 7391', 'assignee_id' => $assignee->id,
            'parts' => [(string) $part->id], 'private' => true], $this->employee);

        foreach (['المسند' => $assignee, 'المشارك' => $part, 'المنشئ' => $this->employee, 'المالك' => $this->owner] as $who => $u) {
            $this->actingAs($u)->get(route('m.show', ['tasks', $t->id]))->assertOk();
            $this->assertStringContainsString('مهمةٌ خاصةٌ جداً 7391',
                $this->actingAs($u)->get(route('m.index', 'tasks'))->getContent(), "$who يرى المهمة في القائمة");
        }

        $this->actingAs($other)->get(route('m.show', ['tasks', $t->id]))->assertNotFound();
        $this->assertStringNotContainsString('مهمةٌ خاصةٌ جداً 7391',
            $this->actingAs($other)->get(route('m.index', 'tasks'))->getContent(), 'الزميلُ الآخر لا يراها');
        $this->assertStringNotContainsString('مهمةٌ خاصةٌ جداً 7391',
            $this->actingAs($other)->get(route('m.index', ['tasks', 'q' => '7391']))->getContent(), 'ولا بالبحث');
    }

    public function test_the_project_manager_sees_private_tasks_of_the_project(): void
    {
        $pm = $this->colleague('مدير المشروع');
        $this->project->forceFill(['manager_id' => $pm->id])->save();
        $t = $this->task(['title' => 'خاصة', 'assignee_id' => $this->employee->id, 'private' => true]);

        $this->actingAs($pm)->get(route('m.show', ['tasks', $t->id]))->assertOk();
    }

    public function test_a_public_task_stays_visible_to_colleagues(): void
    {
        $t = $this->task(['title' => 'مهمةٌ عامّة', 'assignee_id' => $this->employee->id]);

        $this->actingAs($this->colleague('زميل'))->get(route('m.show', ['tasks', $t->id]))->assertOk();
    }

    public function test_the_ask_tools_do_not_leak_a_private_task(): void
    {
        // دورٌ نطاقُه «الكل» ويملك استعمالَ المساعد — أوسعُ ما يكون لغير المالك
        $role = Role::create(['name' => 'سائل', 'scope' => 'all',
            'flags' => [\App\Support\Ai\Ask\AskPolicy::FLAG => 1], 'matrix' => $this->employee->role->matrix]);
        $asker = $this->colleague('سائلٌ آخر');
        $asker->forceFill(['role_id' => $role->id])->save();
        $mine = $this->colleague('صاحبُ المهمة');
        $mine->forceFill(['role_id' => $role->id])->save();
        $this->task(['title' => 'سرّيةٌ 55821', 'assignee_id' => $mine->id, 'private' => true]);

        $run = fn (User $u) => json_encode(\App\Support\Ai\Ask\AskTools::run('hub_list', ['module' => 'tasks'], $u->fresh(), 400), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('55821', (string) $run($mine), 'صاحبُها يجدها — فالأداةُ تبحث فعلاً');
        $this->assertStringNotContainsString('55821', (string) $run($asker), 'وغيرُه لا يجدها');
    }

    public function test_the_daily_operations_center_shows_my_tasks_card(): void
    {
        $mine = $this->task(['title' => 'مهمتي الجديدة 1122', 'assignee_id' => $this->employee->id,
            'private' => true, 'due' => now()->addDays(2)->toDateString()]);
        $this->task(['title' => 'مهمةُ غيري 3344', 'assignee_id' => $this->viewer->id, 'private' => true]);
        $this->task(['title' => 'مهمةٌ منجزة 5566', 'assignee_id' => $this->employee->id, 'status' => 'منجزة']);

        $html = $this->actingAs($this->employee)->get('/morning')->assertOk()->getContent();
        $this->assertStringContainsString('مهامي', $html);
        $this->assertStringContainsString('مهمتي الجديدة 1122', $html);
        $this->assertStringContainsString(route('m.show', ['tasks', $mine->id]), $html);
        $this->assertStringNotContainsString('مهمةُ غيري 3344', $html);
        $this->assertStringNotContainsString('مهمةٌ منجزة 5566', $html);
    }

    public function test_the_create_form_defaults_to_private_per_setting_and_the_list_has_a_mine_button(): void
    {
        $html = $this->actingAs($this->owner)->get(route('m.create', 'tasks'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="private" value="1"\s+checked/', $html, 'خاصّةٌ افتراضاً');

        $this->hubSetting('tasks.private_default', '0');
        $html = $this->actingAs($this->owner)->get(route('m.create', 'tasks'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/name="private" value="1"\s+checked/', $html);

        $list = $this->actingAs($this->employee)->get(route('m.index', 'tasks'))->getContent();
        $this->assertStringContainsString('f%5BassigneeId%5D=' . $this->employee->id, $list, 'زرُّ «المُسنَد إليّ»');
    }

    public function test_saving_a_task_keeps_the_private_flag_from_the_form(): void
    {
        $this->actingAs($this->owner)->post(route('m.store', 'tasks'), [
            'title' => 'من النموذج', 'projectId' => $this->project->id,
            'assigneeId' => $this->employee->id, 'status' => 'جديدة', 'private' => '1',
        ])->assertRedirect();

        $this->assertTrue((bool) Task::where('title', 'من النموذج')->value('private'));
    }
}
