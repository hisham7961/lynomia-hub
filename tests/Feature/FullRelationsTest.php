<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ٣٦٠ كاملة: المراجع المتعددة كانت تُسقَط صراحةً، وأعمدة الربط الفعلية
 * (company_id / project_id) لم تكن معلنة — فصفحة الشركة لا تعرض مهامها،
 * ولا جواب لسؤال «أي موظف يحمل هذا الجهاز؟».
 */
class FullRelationsTest extends TestCase
{
    public function test_multi_refs_now_appear_as_reverse_relations(): void
    {
        // كانت hub_children تشترط empty($f['multi']) فتُسقط ١١ علاقة
        $svc = collect(hub_children('services'))->map(fn ($c) => $c[0]);
        $this->assertTrue($svc->contains('clients'), 'أي عميل يشتري هذه الخدمة؟');
        $this->assertTrue($svc->contains('brands'));

        $assets = collect(hub_children('assets'))->map(fn ($c) => $c[0]);
        $this->assertTrue($assets->contains('hr'), 'أي موظف يحمل هذا الجهاز؟');

        $users = collect(hub_children('users'))->map(fn ($c) => $c[0]);
        foreach (['projects', 'tasks', 'meetings', 'decisions', 'approvals'] as $m) {
            $this->assertTrue($users->contains($m));
        }
    }

    public function test_implicit_fk_columns_become_relations_on_the_two_hubs(): void
    {
        $co = collect(hub_children('companies'))->map(fn ($c) => $c[0]);
        foreach (['tasks', 'tickets', 'issues', 'entries'] as $m) {
            $this->assertTrue($co->contains($m), "صفحة الشركة يجب أن تعرض {$m}");
        }

        $pr = collect(hub_children('projects'))->map(fn ($c) => $c[0]);
        foreach (['clients', 'assets', 'hr'] as $m) {
            $this->assertTrue($pr->contains($m), "صفحة المشروع يجب أن تعرض {$m}");
        }
    }

    /** الاحتواء في JSON يعمل فعلاً — لا مجرد إدراج في الخريطة */
    public function test_multi_ref_reverse_lookup_returns_the_right_rows(): void
    {
        $this->seedCore();

        $s1 = \App\Models\Service::create(['name' => 'استضافة']);
        $s2 = \App\Models\Service::create(['name' => 'تصميم']);

        $buyer = Client::create(['name' => 'مشترٍ', 'services' => [$s1->id]]);
        $other = Client::create(['name' => 'غير مشترٍ', 'services' => [$s2->id]]);

        $this->actingAs($this->owner);
        $rel = collect(hub_related('services', $s1->id))->keyBy('module');

        $this->assertArrayHasKey('clients', $rel->all());
        $this->assertSame(1, $rel['clients']['count']);
        $this->assertSame('مشترٍ', $rel['clients']['rows']->first()->name);
    }

    public function test_asset_holder_lookup_answers_who_holds_this_device(): void
    {
        $this->seedCore();
        $a1 = \App\Models\Asset::create(['name' => 'حاسوب محمول']);
        $a2 = \App\Models\Asset::create(['name' => 'شاشة']);

        Employee::create(['name' => 'حاملة الجهاز', 'asset_ids' => [$a1->id]]);
        Employee::create(['name' => 'موظف آخر', 'asset_ids' => [$a2->id]]);

        $this->actingAs($this->owner);
        $rel = collect(hub_related('assets', $a1->id))->keyBy('module');

        $this->assertArrayHasKey('hr', $rel->all());
        $this->assertSame('حاملة الجهاز', $rel['hr']['rows']->first()->name);
    }

    public function test_company_page_now_shows_its_tasks(): void
    {
        $this->seedCore();
        $c1 = Company::create(['name_ar' => 'شركتي']);
        $c2 = Company::create(['name_ar' => 'شركة أخرى']);
        $p = Project::create(['name' => 'مشروع']);

        Task::create(['title' => 'مهمة شركتي', 'company_id' => $c1->id, 'project_id' => $p->id]);
        Task::create(['title' => 'مهمة الأخرى', 'company_id' => $c2->id, 'project_id' => $p->id]);

        $this->actingAs($this->owner);
        $rel = collect(hub_related('companies', $c1->id))->keyBy('module');

        $this->assertArrayHasKey('tasks', $rel->all());
        $this->assertSame(1, $rel['tasks']['count'], 'مهمة الشركة الأخرى تسرّبت');
        $this->assertSame('مهمة شركتي', $rel['tasks']['rows']->first()->title);
    }

    /** رابط «عرض الكل» يجب أن يُرشّح فعلاً، وإلا عرض قائمة كاملة موهماً أنها مفلترة */
    public function test_view_all_link_filters_by_implicit_column(): void
    {
        $this->seedCore();
        $c1 = Company::create(['name_ar' => 'شركتي']);
        $c2 = Company::create(['name_ar' => 'شركة أخرى']);
        $p = Project::create(['name' => 'مشروع']);
        Task::create(['title' => 'مهمة-داخل', 'company_id' => $c1->id, 'project_id' => $p->id]);
        Task::create(['title' => 'مهمة-خارج', 'company_id' => $c2->id, 'project_id' => $p->id]);

        $this->actingAs($this->owner)
            ->get('/m/tasks?' . http_build_query(['f' => ['company_id' => $c1->id]]))
            ->assertOk()->assertSee('مهمة-داخل')->assertDontSee('مهمة-خارج');
    }

    public function test_view_all_link_filters_by_multi_ref(): void
    {
        $this->seedCore();
        $s1 = \App\Models\Service::create(['name' => 'استضافة']);
        $s2 = \App\Models\Service::create(['name' => 'تصميم']);
        Client::create(['name' => 'عميل-داخل', 'services' => [$s1->id]]);
        Client::create(['name' => 'عميل-خارج', 'services' => [$s2->id]]);

        $this->actingAs($this->owner)
            ->get('/m/clients?' . http_build_query(['f' => ['services' => $s1->id]]))
            ->assertOk()->assertSee('عميل-داخل')->assertDontSee('عميل-خارج');
    }

    /** الترشيح الضمني محصور بعمودين — وإلا صار «عرض الكل» كاشفاً للقيم بالاستدلال */
    public function test_arbitrary_columns_are_not_filterable(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع']);
        Task::create(['title' => 'أ', 'project_id' => $p->id, 'priority' => 'عاجلة']);
        Task::create(['title' => 'ب', 'project_id' => $p->id, 'priority' => 'عادية']);

        // عمود حقيقي لكنه خارج القائمة البيضاء — يُتجاهل ولا يُرشّح
        $this->actingAs($this->owner)
            ->get('/m/tasks?' . http_build_query(['f' => ['priority' => 'عاجلة']]))
            ->assertOk()->assertSee('أ')->assertSee('ب');
    }

    /** التنطيق يبقى مفروضاً على العلاقات الجديدة كما على القديمة */
    public function test_new_relations_still_respect_scope(): void
    {
        $this->seedCore();
        $modules = array_keys(config('hub.modules'));
        $full = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all();
        $role = \App\Models\Role::create(['name' => 'بمشاريعه', 'scope' => 'proj', 'flags' => [], 'matrix' => $full]);
        $u = \App\Models\User::create(['name' => 'محدود', 'email' => 'rel4@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $mine = Project::create(['name' => 'لي', 'manager_id' => $u->id]);
        $theirs = Project::create(['name' => 'لغيري', 'manager_id' => $this->owner->id]);
        $co = Company::create(['name_ar' => 'شركة']);

        Task::create(['title' => 'مهمة داخل', 'company_id' => $co->id, 'project_id' => $mine->id]);
        Task::create(['title' => 'مهمة خارج', 'company_id' => $co->id, 'project_id' => $theirs->id]);

        $this->actingAs($u);
        $rel = collect(hub_related('companies', $co->id))->keyBy('module');
        $this->assertSame(1, $rel['tasks']['count'], 'العلاقة الضمنية تجاوزت النطاق');
        $this->assertSame('مهمة داخل', $rel['tasks']['rows']->first()->title);
    }

    /** العلاقات تضاعفت — فالعدّ يجب أن يبقى استعلاماً واحداً لكل علاقة غير فارغة */
    public function test_query_count_stays_one_per_related_module(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركة فارغة']);

        $this->actingAs($this->owner);
        hub_related('companies', $co->id);      // تسخين: بناء خريطة العلاقات وتخبئتها

        DB::enableQueryLog();
        hub_related('companies', $co->id);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        // ٥٩ علاقة كلها فارغة ⇒ استعلام واحد لكل منها، بلا count إضافي
        $this->assertLessThanOrEqual(count(hub_children('companies')) + 2, $n,
            "استعلامات زائدة: {$n}");
    }
}
