<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * الموجة الرابعة — متانة الاستيراد (ImportController):
 * (أ) حلُّ الحقل المرجعي كان بـDB::table الخام بلا hub_scope ولا عزل شركات وبلا
 *     orderBy — فالاسم يُحلُّ عبر كل الشركات (تسريب/ربط خاطئ) وعند تكراره «قرعة».
 * (ب) cast() يفحص الصيغة لا طول العمود — قيمةٌ أطول من عمودها تُقبل صامتة على
 *     SQLite وتنفجر 22001 على MySQL الصارمة (وتُلغى المعاملة كلها).
 */
class ImportRobustnessRound4Test extends TestCase
{
    protected function upload(User $u, string $module, string $csv, array $map)
    {
        $this->actingAs($u)->post("/m/{$module}/import",
            ['file' => UploadedFile::fake()->createWithContent('data.csv', $csv)])->assertOk();

        return $this->actingAs($u)->post("/m/{$module}/import/run", ['map' => $map]);
    }

    /** (أ) حلّ المرجع يحترم عزل الشركات — لا يربط بمشروع شركةٍ خارج النطاق */
    public function test_import_ref_resolution_respects_company_isolation(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ', 'status' => 'نشطة']);
        $b = Company::create(['name_ar' => 'شركة ب', 'status' => 'نشطة']);
        Project::create(['name' => 'مشروع سرّي', 'status' => 'قيد التنفيذ', 'company_id' => $b->id]);

        $role = Role::create(['name' => 'مستورِد', 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1, 'a' => 1]]]);
        $u = User::create(['name' => 'مستورِد', 'email' => 'imp@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(), 'companies' => [$a->id]]);

        $this->upload($u, 'tasks', "العنوان,المشروع\nمهمة مستوردة,مشروع سرّي\n",
            [0 => 'title', 1 => 'projectId'])->assertOk();

        $t = Task::where('title', 'مهمة مستوردة')->first();
        $this->assertTrue($t === null || $t->project_id === null,
            'الاستيراد ربط مهمة بمشروع شركةٍ خارج عزل المستورِد');
    }

    /** (ب) قيمةٌ أطول من عرض العمود تُتخطّى لا تُقبل صامتة */
    public function test_import_rejects_value_longer_than_column_width(): void
    {
        $this->seedCore();
        Project::create(['name' => 'مشروع الاستيراد', 'status' => 'قيد التنفيذ']);
        $long = str_repeat('ط', 400);   // tasks.title = varchar(300)

        $this->upload($this->owner, 'tasks',
            "العنوان,المشروع,الاستحقاق\n{$long},مشروع الاستيراد,2024-01-01\n",
            [0 => 'title', 1 => 'projectId', 2 => 'due'])->assertOk();

        $this->assertSame(0, Task::where('title', $long)->count(),
            'قيمة أطول من عرض العمود قُبلت صامتة — تنفجر 22001 على MySQL');
    }
}
