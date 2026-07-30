<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dashboard;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Support\WidgetRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * المرحلة ٧ — نتائج التدقيق العدائي على إصدارات هذه الجلسة (v2.67 → v2.77).
 * كل اختبار هنا كُتب **فاشلاً أولاً** ليُثبت العيب قبل إصلاحه.
 */
class AdversarialAuditTest extends TestCase
{
    /**
     * اسم اللوحة يُحقن في سلسلة JS داخل سمة HTML.
     * `{{ }}` يُحوّل `'` إلى `&#039;`، لكن المتصفح يفكّ ترميز السمة **قبل** أن يُحلّلها
     * محلّل JS — فالاقتباس يصل سليماً ويخرج من السلسلة. وهو الصنف نفسه الذي أُصلح في v2.60.0.
     */
    public function test_board_name_cannot_break_out_of_the_confirm_string(): void
    {
        $this->seedCore();
        // بعد v2.89 لا سياق JS أصلاً: التأكيد صار سمة data-confirm مُهرَّبة HTML —
        // فنُثبت غياب onsubmit كلياً، وأن الاسم الخبيث لا يكسر السمة ولا يفتح وسماً
        $evil = 'x" onmouseover="alert(1)';
        $b = Dashboard::create(['name' => $evil, 'owner_id' => $this->owner->id]);

        $html = $this->actingAs($this->owner)->get("/boards/{$b->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('onsubmit=', $html, 'عاد تأكيد onsubmit المنبثق');
        $this->assertStringContainsString('data-confirm=', $html, 'لا تأكيد داخل الصفحة لنموذج الحذف');
        $this->assertStringNotContainsString($evil, $html,
            'اسم اللوحة وصل خاماً غير مُهرَّب — كسرُ سمة وحقن HTML');
    }

    /** ونفس الفحص على القالب المُصرَّف مباشرةً، بلا اعتماد على شكل الصفحة */
    public function test_board_delete_confirm_uses_json_encoding(): void
    {
        $src = file_get_contents(resource_path('views/boards/edit.blade.php'));
        $line = collect(explode("\n", $src))->first(fn ($l) => str_contains($l, 'confirm(') && str_contains($l, 'board->name'));

        if ($line !== null) {
            $this->assertStringContainsString('Js::from', $line,
                'اسم اللوحة يُدرَج في JS بلا ترميز JSON');
        }
        $this->assertTrue(true);
    }

    /**
     * ودجة «آخر النشاطات» تعرض أسماء سجلات من شركات لا يراها المستخدم.
     * جدول التدقيق بلا `company_id`، فالعزل لا يُفرض عليه — والودجة كانت تُرجع
     * آخر ١٠ قيود بلا قيد لغير المحدود بمشاريع. (عيبٌ سابق لهذه الجلسة، اكتُشف بتدقيقها.)
     */
    public function test_activity_widget_does_not_leak_across_companies(): void
    {
        $this->seedCore();
        $mine = Company::create(['name_ar' => 'شركتي']);
        $theirs = Company::create(['name_ar' => 'شركة أخرى']);

        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all();
        $role = Role::create(['name' => 'محدود بشركة', 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);
        $u = User::create(['name' => 'محدود', 'email' => 'co@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$mine->id]]);

        DB::table('audits')->insert([
            ['module' => 'tasks', 'record_id' => (string) Str::uuid(), 'action' => 'إضافة',
             'name' => 'سجل-شركتي', 'user_id' => $u->id, 'created_at' => now()],
            ['module' => 'tasks', 'record_id' => (string) Str::uuid(), 'action' => 'إضافة',
             'name' => 'سجل-الشركة-الأخرى', 'user_id' => $this->owner->id, 'created_at' => now()],
        ]);

        // القيد الأول يخصّ شركته صراحةً؛ الثاني شركةً أخرى
        DB::table('audits')->where('name', 'سجل-شركتي')->update(['company_id' => $mine->id]);
        DB::table('audits')->where('name', 'سجل-الشركة-الأخرى')->update(['company_id' => $theirs->id]);

        $rows = collect(WidgetRegistry::resolve('audits', $u))->pluck('name');
        $this->assertTrue($rows->contains('سجل-شركتي'), 'قيد شركته اختفى');
        $this->assertFalse($rows->contains('سجل-الشركة-الأخرى'),
            'ودجة النشاطات تسرّب أسماء سجلات من شركة أخرى');
    }

    /** الكتابة تلتقط الشركة النشطة تلقائياً عبر سمة Auditable */
    public function test_audit_write_captures_the_record_company(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركة القيد']);
        $p = Project::create(['name' => 'مشروع', 'company_id' => $co->id]);

        $this->actingAs($this->owner);
        $t = Task::create(['title' => 'مهمة مؤسسية', 'project_id' => $p->id, 'company_id' => $co->id]);

        $row = DB::table('audits')->where('module', 'tasks')->where('record_id', $t->id)->first();
        $this->assertNotNull($row);
        $this->assertSame($co->id, $row->company_id, 'القيد لم يلتقط شركة السجل');
    }

    /** القيد المعدوم الشركة (سجلٌ محذوف لم يُرحَّل) يُحجب عن المحدود لا يُكشف */
    public function test_null_company_audit_is_hidden_from_company_limited_user(): void
    {
        $this->seedCore();
        $mine = Company::create(['name_ar' => 'شركتي']);
        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all();
        $role = Role::create(['name' => 'بشركة', 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);
        $u = User::create(['name' => 'محدود', 'email' => 'nc@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(), 'companies' => [$mine->id]]);

        DB::table('audits')->insert([
            'module' => 'tasks', 'record_id' => (string) Str::uuid(), 'action' => 'إضافة',
            'name' => 'قيد-يتيم', 'user_id' => $this->owner->id, 'company_id' => null, 'created_at' => now(),
        ]);

        $rows = collect(WidgetRegistry::resolve('audits', $u))->pluck('name');
        $this->assertFalse($rows->contains('قيد-يتيم'), 'قيدٌ بلا شركة ظهر للمحدود');
    }

    /** ولا تعرض نشاط وحدة لا يملك المستخدم رؤيتها */
    public function test_activity_widget_respects_module_permissions(): void
    {
        $this->seedCore();
        $role = $this->viewer->role;
        $m = $role->matrix; $m['tasks'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->update(['matrix' => $m]);

        DB::table('audits')->insert([
            'module' => 'tasks', 'record_id' => (string) Str::uuid(), 'action' => 'إضافة',
            'name' => 'مهمة-محجوبة', 'user_id' => $this->owner->id, 'created_at' => now(),
        ]);

        $rows = collect(WidgetRegistry::resolve('audits', $this->viewer->fresh()))->pluck('name');
        $this->assertFalse($rows->contains('مهمة-محجوبة'),
            'نشاط وحدة محجوبة يظهر في اللوحة');
    }

    /** المحدود بمشاريعه يبقى كما كان — لا تشديد زائد يُفرّغ لوحته */
    public function test_project_scoped_user_still_sees_their_own_activity(): void
    {
        $this->seedCore();
        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all();
        $role = Role::create(['name' => 'بمشاريعه', 'scope' => 'proj', 'flags' => [], 'matrix' => $matrix]);
        $u = User::create(['name' => 'محدود', 'email' => 'pr@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $p = Project::create(['name' => 'مشروعه', 'manager_id' => $u->id]);
        Task::create(['title' => 'مهمته', 'project_id' => $p->id]);

        DB::table('audits')->insert([
            'module' => 'tasks', 'record_id' => (string) Str::uuid(), 'action' => 'إضافة',
            'name' => 'فعلَه-بنفسه', 'user_id' => $u->id, 'created_at' => now(),
        ]);

        $rows = collect(WidgetRegistry::resolve('audits', $u))->pluck('name');
        $this->assertTrue($rows->contains('فعلَه-بنفسه'), 'نشاط المستخدم نفسه اختفى');
    }

    /** المالك يرى الكل — التشديد لا يمسّه */
    public function test_owner_still_sees_all_activity(): void
    {
        $this->seedCore();
        DB::table('audits')->insert([
            'module' => 'tasks', 'record_id' => (string) Str::uuid(), 'action' => 'إضافة',
            'name' => 'أي-نشاط', 'user_id' => $this->employee->id, 'created_at' => now(),
        ]);

        $rows = collect(WidgetRegistry::resolve('audits', $this->owner))->pluck('name');
        $this->assertTrue($rows->contains('أي-نشاط'));
    }

    /**
     * حارسٌ لا نتيجة: `@yield` لا يُهرِّب مخرجاته، و١٤ قالباً تضع اسماً يتحكم به
     * المستخدم في `@section('title', …)`. فُحص الادّعاء ورُفض — Laravel يُهرِّب في
     * `startSection` عند تمرير المحتوى مضمَّناً (`e($content)`)، وكل القوالب تستعمل
     * هذا الشكل لا الشكل الكتلي. الاختباران يبقيان حارسَين: من يحوّل عنواناً إلى
     * `@section('title') … @endsection` يفقد التهريب، فيسقط هنا.
     */
    public function test_page_title_cannot_break_out_of_the_title_element(): void
    {
        $this->seedCore();
        $evil = '</title><script>alert(1)</script>';
        $b = Dashboard::create(['name' => $evil, 'owner_id' => $this->owner->id]);

        $html = $this->actingAs($this->owner)->get("/boards/{$b->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('</title><script>', $html,
            'العنوان يسمح بالخروج من عنصر title — حقن سكربت');
    }

    /** والعيب نفسه على رحلة العميل — اسم العميل يصل العنوان خاماً */
    public function test_client_name_cannot_break_out_of_the_title(): void
    {
        $this->seedCore();
        $c = \App\Models\Client::create(['name' => '</title><script>alert(2)</script>']);

        $html = $this->actingAs($this->owner)->get('/journey/' . $c->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('</title><script>', $html,
            'اسم العميل يخرج من عنصر title');
    }
}
