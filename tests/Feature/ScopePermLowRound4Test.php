<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * الموجة الرابعة — عيوب النطاق/الصلاحية المنخفضة:
 * (١) apiStore لا يورّث الشركة النشطة كما store الويب: مستخدمٌ معزولٌ شركاتيّاً
 *     ينشئ سجلاً عبر API في وحدةٍ لها عمود company_id بلا حقلٍ مرجعيّ (tasks) →
 *     يُحفَظ بـ company_id=NULL فيختفي من قائمته هو (whereIn يُقصي NULL).
 * (٧) فلتر القائمة f[refKey] يتجاوز hub_field_mode('hide') — بخلاف الفلاتر
 *     المتقدمة المحروسة — فيصير الترشيح بحقلٍ محجوبٍ قناةَ استدلالٍ على قيمته.
 * (١١) كاش قرار وصول الملفات (١٢٠ث) بمفتاحٍ لا يحمل ختم الأدوار — فسحبُ صلاحية
 *     رؤية وحدةٍ من دورٍ يُبقي ملفاتها متاحةً لحامليه دقيقتين.
 */
class ScopePermLowRound4Test extends TestCase
{
    /** (١) سجل API لمستخدمٍ معزول يرث شركته فلا يختفي عنه */
    public function test_api_store_inherits_active_company_for_isolated_user(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $this->employee->update(['companies' => [$coA->id]]);   // معزولة على ألف
        $p = Project::create(['name' => 'مشروع ألف', 'status' => 'نشط', 'company_id' => $coA->id]);
        $tok = $this->apiToken($this->employee);

        $this->postJson('/api/v1/tasks', ['title' => 'مهمة API معزولة', 'projectId' => $p->id],
            ['Authorization' => "Bearer $tok"])->assertStatus(201);

        $task = Task::where('title', 'مهمة API معزولة')->firstOrFail();
        $this->assertSame($coA->id, $task->company_id,
            'سجل API لمستخدمٍ معزول حُفظ بـ company_id=NULL فيختفي عنه');
    }

    /** (٧) حقلٌ محجوبٌ لا يُرشَّح به — غير المطابق يبقى ظاهراً */
    public function test_hidden_ref_field_cannot_be_used_as_filter(): void
    {
        $this->seedCore();
        // الموظف يرى apps لكن حقل «المشروع» (ref) محجوبٌ عنه
        $this->employee->role->update(['field_rules' => ['apps' => ['projectId' => 'hide']]]);

        $p = Project::create(['name' => 'مشروع الترشيح', 'status' => 'نشط']);
        Application::create(['name' => 'تطبيقٌ داخل المشروع', 'project_id' => $p->id]);
        Application::create(['name' => 'تطبيقٌ بلا مشروع', 'project_id' => null]);

        // يُرشّح بالحقل المحجوب — يجب أن يُهمَل الفلتر فيظهر غير المطابق أيضاً
        $this->actingAs($this->employee)->get('/m/apps?f[projectId]=' . $p->id)
            ->assertOk()
            ->assertSee('تطبيقٌ بلا مشروع');
    }

    /** (١١) سحبُ رؤية وحدةٍ من الدور يمنع ملفاتها فوراً لا بعد دقيقتين */
    public function test_file_access_cache_invalidates_on_role_permission_change(): void
    {
        $this->seedCore();
        Storage::disk('local')->put('hub/roleacc.png', 'x');
        $t = Task::create(['title' => 'مهمة بملف', 'status' => 'جديدة']);
        Attachment::create(['module' => 'tasks', 'record_id' => $t->id, 'path' => 'hub/roleacc.png',
            'disk' => 'local', 'mime' => 'image/png', 'original_name' => 'r.png',
            'av_status' => 'clean', 'uploaded_by' => $this->owner->id]);

        try {
            // الموظف يرى tasks → يُخدَم الملف ويُخبّأ القرار
            $this->actingAs($this->employee)->get('/files/hub/roleacc.png')->assertOk();

            // سُحبت رؤية tasks من دوره
            $role = $this->employee->role;
            $matrix = (array) $role->matrix;
            $matrix['tasks']['v'] = 0;
            $role->update(['matrix' => $matrix]);

            // يجب أن يُمنع فوراً لا بعد انقضاء الـ١٢٠ث
            $this->actingAs($this->employee->fresh())->get('/files/hub/roleacc.png')->assertStatus(403);
        } finally {
            Storage::disk('local')->delete('hub/roleacc.png');
        }
    }
}
