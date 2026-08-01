<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Project;
use App\Support\AppsProjects;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * السجل يقول الحقيقة صراحةً: **التطبيق يتبع مشروعاً**. لكن ثماني وحداتٍ تعرض
 * الحقلين معاً — «المشروع» و«التطبيق» — ولا شيء يربط أحدهما بالآخر. فينشأ
 * الالتباس بأربع صور: سجلٌّ له تطبيقٌ وبلا مشروع (فعدسة المشروع لا تراه
 * ومجاميعه تنقص بصمت)، وسجلٌّ مشروعُه يخالف مشروع تطبيقه، وتطبيقٌ يتيم،
 * ومشروعٌ منتَجُه تطبيقٌ ولا ملفَّ تطبيقٍ له.
 */
class AppsProjectsTest extends TestCase
{
    protected function pair(): array
    {
        $p = Project::create(['name' => 'مشروع الجوال', 'status' => 'قيد التنفيذ']);
        $a = Application::create(['name' => 'تطبيق العملاء', 'project_id' => $p->id,
            'platform' => 'iOS', 'status' => 'منشور']);

        return [$p, $a];
    }

    /** الحفظ يورّث المشروع من التطبيق حين يُترك فارغاً */
    public function test_saving_inherits_the_project_from_the_app(): void
    {
        $this->seedCore();
        [$p, $a] = $this->pair();

        $this->actingAs($this->owner)->post('/m/tickets', [
            'subject' => 'شكوى من التطبيق', 'appId' => $a->id, 'status' => 'جديدة',
        ])->assertRedirect();

        $t = DB::table('tickets')->where('subject', 'شكوى من التطبيق')->first();
        $this->assertSame($p->id, $t->project_id,
            'سجلٌّ رُبط بتطبيقٍ وبقي بلا مشروع — عدسة المشروع لا تراه ومجاميعه تنقص');
    }

    /** ولا يُكتب فوق اختيارٍ صريح — من كتب مشروعاً يُحترم ويُعرض تناقضه */
    public function test_an_explicit_project_is_never_overwritten(): void
    {
        $this->seedCore();
        [$p, $a] = $this->pair();
        $other = Project::create(['name' => 'مشروع آخر', 'status' => 'قيد التنفيذ']);

        $this->actingAs($this->owner)->post('/m/tickets', [
            'subject' => 'تذكرة بمشروع صريح', 'appId' => $a->id,
            'projectId' => $other->id, 'status' => 'جديدة',
        ])->assertRedirect();

        $t = DB::table('tickets')->where('subject', 'تذكرة بمشروع صريح')->first();
        $this->assertSame($other->id, $t->project_id, 'الاستنتاج داس اختياراً صريحاً');

        $this->actingAs($this->owner);
        $c = collect(AppsProjects::scan())->get('conflict');
        $this->assertSame(1, collect($c)->count(), 'التناقض لم يُعرض');
        $this->assertSame('مشروع آخر', $c[0]['have']);
        $this->assertSame('مشروع الجوال', $c[0]['want']);
    }

    /** بقايا ما قبل الاستنتاج تُعرض وتُصلَح بضغطة — والتناقض لا يُمسّ */
    public function test_the_bulk_fix_fills_only_the_empty_ones(): void
    {
        $this->seedCore();
        [$p, $a] = $this->pair();
        $other = Project::create(['name' => 'مشروع آخر', 'status' => 'قيد التنفيذ']);

        $blank = (string) Str::uuid();
        $wrong = (string) Str::uuid();
        foreach ([[$blank, null], [$wrong, $other->id]] as [$id, $pid]) {
            DB::table('tickets')->insert(['id' => $id, 'subject' => 'قديمة ' . $id,
                'app_id' => $a->id, 'project_id' => $pid, 'status' => 'جديدة',
                'created_at' => now(), 'updated_at' => now()]);
        }

        $this->actingAs($this->owner)->post('/apps-projects/fix')->assertRedirect();

        $this->assertSame($p->id, DB::table('tickets')->where('id', $blank)->value('project_id'));
        $this->assertSame($other->id, DB::table('tickets')->where('id', $wrong)->value('project_id'),
            'الإصلاح الجماعي داس مشروعاً كُتب صراحةً');
        $this->assertTrue(DB::table('audits')->where('action', 'ربط سجلات بمشاريع تطبيقاتها')->exists());
    }

    /** تطبيقٌ يتيم: لا يصعد إلى أي مجموع */
    public function test_an_app_without_a_project_is_listed(): void
    {
        $this->seedCore();
        Application::create(['name' => 'تطبيقٌ يتيم', 'platform' => 'أندرويد', 'status' => 'منشور']);

        $this->actingAs($this->owner);
        $o = AppsProjects::scan()['orphanApps'];

        $this->assertSame(1, count($o));
        $this->assertSame('تطبيقٌ يتيم', $o[0]['name']);
    }

    /** مشروعٌ له رابط إنتاج ولا ملفَّ تطبيقٍ له */
    public function test_a_project_with_a_product_link_but_no_app_file_is_listed(): void
    {
        $this->seedCore();
        Project::create(['name' => 'منتَجٌ منشور', 'status' => 'قيد التنفيذ',
            'prod' => 'https://apps.example.com/x']);
        [$p] = $this->pair();

        $this->actingAs($this->owner);
        $a = AppsProjects::scan()['appless'];

        $this->assertSame(1, count($a), 'مشروعٌ له ملفُّ تطبيقٍ عُدّ ناقصاً');
        $this->assertSame('منتَجٌ منشور', $a[0]['name']);
    }

    /** الخريطة: كل مشروعٍ وتطبيقاته */
    public function test_the_map_groups_apps_under_their_project(): void
    {
        $this->seedCore();
        [$p, $a] = $this->pair();
        Application::create(['name' => 'تطبيق الموظفين', 'project_id' => $p->id, 'platform' => 'أندرويد']);

        $this->actingAs($this->owner);
        $map = AppsProjects::scan()['byProject'];

        $this->assertSame(1, count($map));
        $this->assertSame('مشروع الجوال', $map[0]['project']);
        $this->assertSame(2, count($map[0]['apps']));
    }

    /** ولا يقرأ الفحصُ وحدةً لا يراها القارئ */
    public function test_the_scan_respects_module_permission(): void
    {
        $this->seedCore();
        [$p, $a] = $this->pair();
        DB::table('tickets')->insert(['id' => (string) Str::uuid(), 'subject' => 'قديمة',
            'app_id' => $a->id, 'project_id' => null, 'status' => 'جديدة',
            'created_at' => now(), 'updated_at' => now()]);

        $role = $this->employee->role;
        $m = $role->matrix;
        $m['tickets'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->update(['matrix' => $m]);

        $this->actingAs($u = $this->employee->fresh());
        $this->assertSame([], AppsProjects::scan($u)['noProject'],
            'الفحص قرأ وحدةً لا يراها القارئ');
    }

    /** والشاشة تعرض ما وجدته وخلف صلاحيتها */
    public function test_the_screen_renders_and_is_gated(): void
    {
        $this->seedCore();
        Application::create(['name' => 'تطبيقٌ يتيم', 'platform' => 'iOS']);

        $this->actingAs($this->owner)->get('/apps-projects')->assertOk()
            ->assertSee('التطبيقات والمشاريع')->assertSee('تطبيقٌ يتيم');

        $role = $this->employee->role;
        $m = $role->matrix;
        $m['apps'] = ['v' => 0];
        $m['projects'] = ['v' => 0];
        $role->update(['matrix' => $m]);

        $this->actingAs($this->employee->fresh())->get('/apps-projects')->assertForbidden();
    }
}
