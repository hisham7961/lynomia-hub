<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * جولة تدقيقٍ بوكلاء كشفت أن الحارس الثلاثي (`hub_scope` + `hub_can` +
 * `hub_field_mode`) سقط في شاشاتٍ محسوبة: تجمع من عدّة وحدات فتُقاس بصلاحية
 * **واحدة** منها، أو تقرأ الجدول خاماً بلا نطاق.
 *
 * القاعدة التي تحرسها هذه الاختبارات: **الشاشة التي تجمع لا تفتح باباً مغلقاً**
 * — لا وحدةً لا يملكها القارئ، ولا سجلاً خارج نطاقه، ولا حقلاً محجوباً عنه.
 */
class ScopeLeakAuditTest extends TestCase
{
    /** مستخدمٌ بدورٍ مخصَّص: مصفوفةٌ وقيودُ حقولٍ كما تُعطى في الواقع */
    protected function withRole(array $matrix, array $fieldRules = [], array $companies = [],
                                string $scope = 'all', array $flags = []): User
    {
        $role = Role::create(['name' => 'دور مخصّص ' . Str::random(5), 'scope' => $scope,
            'flags' => $flags, 'matrix' => $matrix, 'field_rules' => $fieldRules]);

        return User::create(['name' => 'مقيَّد', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'companies' => $companies ?: null, 'password_changed_at' => now()]);
    }

    /* ── الملف الشامل للموظف: نطاق الشركة يسري كما في كل قارئ ── */

    public function test_the_full_employee_file_respects_company_isolation(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ', 'status' => 'نشطة']);
        $b = Company::create(['name_ar' => 'شركة ب', 'status' => 'نشطة']);

        $mine = Employee::create(['name' => 'موظف شركتي', 'company_id' => $a->id, 'status' => 'نشط']);
        $theirs = Employee::create(['name' => 'موظف الشركة الأخرى', 'company_id' => $b->id, 'status' => 'نشط']);

        $u = $this->withRole(['hr' => ['v' => 1]], [], [$a->id]);

        $this->actingAs($u)->get("/employee/{$mine->id}")->assertOk();
        $this->actingAs($u)->get("/employee/{$theirs->id}")->assertNotFound(
            'الملف الشامل تخطّى عزل الشركات — ويعرض الإقامة والجواز والراتب والسجل التأديبي');
    }

    /** والملف لا يعرض وحداتٍ لا يملكها قارئه */
    public function test_the_full_employee_file_hides_modules_the_reader_lacks(): void
    {
        $this->seedCore();
        $emp = Employee::create(['name' => 'موظف', 'status' => 'نشط', 'user_id' => $this->employee->id]);

        DB::table('leave_requests')->insert(['id' => (string) Str::uuid(), 'emp_id' => $emp->id,
            'type' => 'سنوية', 'date_from' => now()->toDateString(), 'date_to' => now()->addDays(3)->toDateString(),
            'days' => 3, 'status' => 'معتمد', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('attendance')->insert(['id' => (string) Str::uuid(), 'emp_id' => $emp->id,
            'date' => now()->toDateString(), 'time_in' => '08:00', 'time_out' => '17:00',
            'hours' => 9, 'status' => 'حاضر', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('employee_records')->insert(['id' => (string) Str::uuid(), 'emp_id' => $emp->id,
            'kind' => 'إنذار', 'title' => 'إنذار تأديبي سرّي', 'date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now()]);

        // يملك عرض الموارد البشرية وحدها — لا الإجازات ولا الحضور ولا السجل
        $u = $this->withRole(['hr' => ['v' => 1]]);
        $html = $this->actingAs($u)->get("/employee/{$emp->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('إنذار تأديبي سرّي', $html,
            'شاشةٌ مقاسةٌ بصلاحية HR عرضت السجل التأديبي لمن لا يملك وحدته');
        $this->assertStringNotContainsString('08:00', $html,
            'أوقات الحضور عُرضت لمن لا يملك وحدة الحضور');
    }

    /* ── مسار التسليم: يجمع ستّ وحدات، ولكلٍّ نطاقها ── */

    public function test_the_delivery_path_respects_project_scope(): void
    {
        $this->seedCore();
        $mine = Project::create(['name' => 'مشروعي', 'status' => 'قيد التنفيذ']);
        $theirs = Project::create(['name' => 'مشروعٌ سرّي', 'status' => 'قيد التنفيذ']);

        foreach ([[$mine, 'ميزة مشروعي'], [$theirs, 'ميزة المشروع السرّي']] as [$p, $t]) {
            DB::table('plan_items')->insert(['id' => (string) Str::uuid(), 'title' => $t,
                'project_id' => $p->id, 'status' => 'منشورة',
                'created_at' => now(), 'updated_at' => now()]);
        }

        $u = $this->withRole(['feats' => ['v' => 1], 'projects' => ['v' => 1], 'updates' => ['v' => 1]],
            [], [], 'proj');
        $mine->update(['members' => [$u->id]]);

        $html = $this->actingAs($u->fresh())->get('/delivery')->assertOk()->getContent();

        $this->assertStringNotContainsString('ميزة المشروع السرّي', $html,
            'مسار التسليم سرّب مزايا مشروعٍ خارج نطاق القارئ');
        $this->assertStringNotContainsString('مشروعٌ سرّي', $html,
            'مسار التسليم سرّب اسم مشروعٍ خارج نطاق القارئ');
    }

    /** ولا يعرض مشاريعَ لمن لا يملك وحدة المشاريع */
    public function test_the_delivery_path_needs_the_projects_permission_to_name_projects(): void
    {
        $this->seedCore();
        Project::create(['name' => 'مشروعٌ صامت', 'status' => 'قيد التنفيذ']);

        $u = $this->withRole(['feats' => ['v' => 1], 'updates' => ['v' => 1]]);
        $html = $this->actingAs($u)->get('/delivery')->assertOk()->getContent();

        $this->assertStringNotContainsString('مشروعٌ صامت', $html,
            'الشاشة سمّت مشروعاً لمن لا يملك وحدة المشاريع');
    }

    /* ── الباقات: تُقاس بصلاحية «الباقات» وتعرض تكلفة «الخدمات» ── */

    public function test_pricing_hides_service_cost_when_the_field_is_masked(): void
    {
        $this->seedCore();
        // معرّفاتٌ ثابتةٌ بحروفٍ فقط (لا رقمَ إلا نبضةُ الإصدار 4): معرّفٌ عشوائيّ
        // قد يحوي «4242» صدفةً فيظهر في رابط السجل ويُسقط تأكيدَ الحجب — قرعةٌ
        // تخضرّ محليّاً وتسقط على MySQL. الثابتُ يقطع القرعة فالرقمُ لا يأتي إلا من الحقل.
        $sid = 'aaaaaaaa-aaaa-4aaa-baaa-aaaaaaaaaaaa';
        DB::table('services')->insert(['id' => $sid, 'name' => 'خدمة', 'price' => 100,
            'cost' => 4242, 'status' => 'نشطة', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pricing_plans')->insert(['id' => 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb', 'name' => 'الباقة',
            'service_id' => $sid, 'price' => 100, 'unit_cost' => 4242, 'status' => 'نشطة',
            'created_at' => now(), 'updated_at' => now()]);

        $u = $this->withRole(['plans' => ['v' => 1], 'services' => ['v' => 1]],
            ['services' => ['cost' => 'hide']]);

        $html = $this->actingAs($u)->get('/pricing')->assertOk()->getContent();
        $this->assertStringNotContainsString('4242', $html,
            'تكلفة الخدمة محجوبةٌ بقيود الحقول وظهرت في شاشة الباقات');
    }

    /* ── الصندوق الموحّد: السياسات والمعرفة بنطاقهما ── */

    public function test_the_inbox_respects_company_isolation_for_policies(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ', 'status' => 'نشطة']);
        $b = Company::create(['name_ar' => 'شركة ب', 'status' => 'نشطة']);

        foreach ([[$a, 'سياسة شركتي'], [$b, 'سياسة الشركة الأخرى']] as [$co, $t]) {
            DB::table('policies')->insert(['id' => (string) Str::uuid(), 'title' => $t,
                'ver' => '1', 'ack_required' => 1, 'company_id' => $co->id,
                'created_at' => now(), 'updated_at' => now()]);
        }

        $u = $this->withRole(['policies' => ['v' => 1]], [], [$a->id]);
        $titles = collect(\App\Support\Inbox::items($u->fresh()))->pluck('title')->all();

        $this->assertContains('سياسة شركتي', $titles);
        $this->assertNotContains('سياسة الشركة الأخرى', $titles,
            'الصندوق الموحّد سرّب سياسةَ شركةٍ أخرى — ويظهر في كل صفحة عبر شارة الشريط');
    }

    /* ── العهدة: الثمن حقلٌ قد يكون محجوباً ── */

    public function test_asset_life_hides_price_when_the_field_is_masked(): void
    {
        $this->seedCore();
        // معرّفٌ ثابتٌ بحروفٍ فقط: عشوائيٌّ قد يحوي «7777» فيتسرّب عبر رابط السجل
        // لا عبر الحقل المحجوب — فيسقط التأكيد قرعةً على MySQL دون خللٍ حقيقيّ.
        \App\Models\Asset::forceCreate(['id' => 'dddddddd-dddd-4ddd-bddd-dddddddddddd',
            'name' => 'جهاز', 'price' => 7777, 'status' => 'قيد الاستخدام',
            'holder_id' => $this->employee->id]);

        $u = $this->withRole(['assets' => ['v' => 1]], ['assets' => ['price' => 'hide']]);
        $html = $this->actingAs($u)->get('/assets-life')->assertOk()->getContent();

        $this->assertStringNotContainsString('7,777', $html,
            'ثمن الأصل محجوبٌ بقيود الحقول وظهر مجموعاً في شاشة العهدة');
        $this->assertStringNotContainsString('7777', $html);
    }

    /* ── الامتثال: الرسوم كذلك ── */

    public function test_compliance_hides_fees_when_the_field_is_masked(): void
    {
        $this->seedCore();
        // معرّفٌ ثابتٌ بحروفٍ فقط: عشوائيٌّ قد يحوي «8888» فيظهر في رابط السجل
        // (كما سقطت هذه الحالةُ فعلاً على MySQL) لا في الحقل المحجوب. الثابتُ يقطع القرعة.
        DB::table('compliance_items')->insert(['id' => 'cccccccc-cccc-4ccc-bccc-cccccccccccc', 'title' => 'رخصة',
            'kind' => 'ترخيص تجاري', 'due' => now()->addDays(10)->toDateString(), 'fee' => 8888,
            'status' => 'مطلوب إجراء', 'created_at' => now(), 'updated_at' => now()]);

        $u = $this->withRole(['compliance' => ['v' => 1]], ['compliance' => ['fee' => 'hide']]);
        $html = $this->actingAs($u)->get('/compliance-board')->assertOk()->getContent();

        $this->assertStringNotContainsString('8,888', $html, 'رسوم الامتثال محجوبةٌ وظهرت');
        $this->assertStringNotContainsString('8888', $html);
    }

    /* ── التطبيقات والمشاريع: الأسماء نفسها بيانات ── */

    public function test_apps_projects_does_not_name_records_the_reader_cannot_see(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروعٌ مكتوم', 'status' => 'قيد التنفيذ']);
        $app = \App\Models\Application::create(['name' => 'تطبيقٌ مكتوم', 'project_id' => $p->id]);
        DB::table('tickets')->insert(['id' => (string) Str::uuid(), 'subject' => 'تذكرة',
            'app_id' => $app->id, 'project_id' => null, 'status' => 'جديدة',
            'created_at' => now(), 'updated_at' => now()]);

        // يرى التذاكر وحدها — لا التطبيقات ولا المشاريع
        $u = $this->withRole(['tickets' => ['v' => 1], 'projects' => ['v' => 1]]);
        $html = $this->actingAs($u)->get('/apps-projects')->assertOk()->getContent();
        $this->assertStringNotContainsString('تطبيقٌ مكتوم', $html,
            'الشاشة سمّت تطبيقاً لمن لا يملك وحدة التطبيقات');
    }

    /* ── مركز الإعلام: بابان لا باب ── */

    public function test_the_media_center_does_not_show_press_to_an_events_only_reader(): void
    {
        $this->seedCore();
        DB::table('media_items')->insert(['id' => (string) Str::uuid(), 'title' => 'تغطيةٌ صحفية سرّية',
            'type' => 'خبر', 'status' => 'منشور', 'date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now()]);

        $u = $this->withRole(['events' => ['v' => 1]]);
        $html = $this->actingAs($u)->get('/media-center')->assertOk()->getContent();

        $this->assertStringNotContainsString('تغطيةٌ صحفية سرّية', $html,
            'قارئٌ يملك الأحداث وحدها قرأ كتالوج الإعلام كاملاً');
    }
}
