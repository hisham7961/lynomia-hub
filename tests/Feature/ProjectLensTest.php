<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\Idea;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * كل لوحةٍ تحليلية كانت تعرض **المنشأة كلها مجموعةً**: لا تُعرف حصة مشروعٍ
 * بعينه من القدرات ولا من التوصيات ولا من الأثر ولا من الجودة ولا من العقود
 * ولا من الأفكار. عدسةٌ واحدة (`?p=`) تخدمها كلها.
 *
 * وفيها فخّان لا يمسكهما اختبارُ وجود:
 *  • **البسط ينكمش والمقام لا**: ترشيح مهام المشروع مع إبقاء «المتاح» على
 *    طاقة الموظف الكاملة يجعل الاستغلال كسراً صغيراً زوراً، فيُقال «الفريق
 *    بلا شغل» وهو محترقٌ على مشاريع أخرى — إنذارٌ كاذب يقود لقرار توظيف خاطئ.
 *  • **مفتاح تخبئةٍ بلا مشروع**: من يفتح عدسة مشروعٍ أولاً يُخبّئ نتائجه لمن
 *    بعده. ومفتاح التوصيات كان مسطّحاً بلا مستخدمٍ ولا دورٍ أصلاً.
 */
class ProjectLensTest extends TestCase
{
    protected Project $a;
    protected Project $b;

    protected function scene(): void
    {
        $this->seedCore();
        $this->a = Project::create(['name' => 'مشروع ألف', 'status' => 'قيد التنفيذ']);
        $this->b = Project::create(['name' => 'مشروع باء', 'status' => 'قيد التنفيذ']);
    }

    // ── العقد المشترك ──
    public function test_lens_refuses_a_project_outside_your_scope(): void
    {
        $this->scene();
        $hidden = Project::create(['name' => 'مشروع محجوب', 'status' => 'قيد التنفيذ']);

        $role = Role::create(['name' => 'مقيّد', 'scope' => 'proj', 'flags' => ['monitor' => 1],
            'matrix' => collect(array_keys(config('hub.modules')))
                ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all()]);
        $u = User::create(['name' => 'مقيّد', 'email' => 'lens@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($u);
        request()->merge([]);
        $this->app['request']->query->set('p', $hidden->id);

        $lens = hub_lens($u);
        $this->assertFalse($lens['on'], 'عدسةٌ فُتحت على مشروعٍ خارج نطاق المستخدم — بابٌ خلفي');
        $this->assertNull($lens['id']);
    }

    public function test_lens_declares_what_it_cannot_filter(): void
    {
        $this->scene();
        // سبع وحدات لا طريق لها إلى مشروع — تُقال صراحةً لا تُعرض كأنها مُرشَّحة
        foreach (['competitors', 'media', 'events', 'plans', 'compliance'] as $m) {
            $this->assertSame('none', hub_lens_path($m)['mode'], "توقّعنا $m بلا طريق");
        }
        foreach (['tasks', 'contracts', 'ideas', 'apps', 'issues'] as $m) {
            $this->assertSame('direct', hub_lens_path($m)['mode']);
        }
        $this->assertSame('via', hub_lens_path('skills')['mode'], 'المهارات تصل بقفزة عبر الموظف');
    }

    // ── القدرات: الفخّ الدلالي ──
    public function test_capacity_does_not_report_a_busy_team_as_idle(): void
    {
        $this->scene();
        $e = Employee::create(['name' => 'مهندسة', 'status' => 'نشط', 'user_id' => $this->employee->id]);

        // ٨٠ ساعة على «ألف» و٨٠ على «باء» — الموظفة محترقة لا عاطلة
        foreach ([[$this->a, 80], [$this->b, 80]] as [$p, $h]) {
            Task::create(['title' => 'عمل ' . $p->name, 'project_id' => $p->id,
                'assignee_id' => $this->employee->id, 'est_h' => $h, 'status' => 'جديدة']);
        }

        $all = hub_capacity(null, null, null);
        $lensed = hub_capacity(null, null, $this->a->id);

        $rowAll = collect($all['rows'])->firstWhere('id', $e->id);
        $rowLens = collect($lensed['rows'])->firstWhere('id', $e->id);
        $this->assertNotNull($rowLens, 'الموظفة لها مهام في المشروع فيجب أن تظهر');

        $this->assertLessThan($rowAll['booked'], $rowLens['booked'], 'المحجوز ينكمش لحصة المشروع');
        $this->assertSame(0, $lensed['totals']['idle'] ?? 0,
            'عدّاد «بلا شغل» اشتعل تحت العدسة — البسط انكمش والمقام لم ينكمش');
        $this->assertTrue($lensed['lensed'] ?? false, 'الناتج يُعلن أنه محصور بمشروع');
    }

    public function test_capacity_drops_employees_with_no_work_in_the_project(): void
    {
        $this->scene();
        Employee::create(['name' => 'خارج المشروع', 'status' => 'نشط', 'user_id' => $this->viewer->id]);
        $inside = Employee::create(['name' => 'داخل المشروع', 'status' => 'نشط', 'user_id' => $this->employee->id]);
        Task::create(['title' => 'مهمة', 'project_id' => $this->a->id,
            'assignee_id' => $this->employee->id, 'est_h' => 10, 'status' => 'جديدة']);

        $names = collect(hub_capacity(null, null, $this->a->id)['rows'])->pluck('name');
        $this->assertTrue($names->contains('داخل المشروع'));
        $this->assertFalse($names->contains('خارج المشروع'),
            'موظفٌ بلا عملٍ في المشروع يُحسب ضمن طاقته فيمدّد المقام كذباً');
    }

    // ── التخبئة ──
    public function test_cached_centers_do_not_serve_one_project_to_another(): void
    {
        $this->scene();
        \App\Models\Application::create(['name' => 'تطبيق ألف', 'status' => 'منشور', 'project_id' => $this->a->id]);
        \App\Models\Application::create(['name' => 'تطبيق باء', 'status' => 'منشور', 'project_id' => $this->b->id]);

        $this->actingAs($this->owner);
        $qa = collect(hub_app_quality(false, $this->a->id))->pluck('name');
        $qb = collect(hub_app_quality(false, $this->b->id))->pluck('name');

        $this->assertTrue($qa->contains('تطبيق ألف'));
        $this->assertFalse($qa->contains('تطبيق باء'));
        $this->assertTrue($qb->contains('تطبيق باء'), 'المخبأ قدّم نتيجة المشروع الأول للثاني');
    }

    public function test_recommendations_cache_key_is_not_a_flat_string(): void
    {
        $this->scene();
        $this->actingAs($this->owner);

        // مفتاحٌ مسطّح «recs» بلا مستخدمٍ ولا دورٍ ولا مشروع — تسريبٌ قائم قبل العدسة
        Cache::flush();
        hub_recommendations(false, $this->a->id);
        $this->assertNull(Cache::get('recs'),
            'ما زال المفتاح المسطّح «recs» مستعملاً — نتيجة مستخدمٍ تُقدَّم لآخر');
    }

    // ── المراكز الستة تستجيب للعدسة ──
    public function test_every_center_answers_the_lens(): void
    {
        $this->scene();
        Task::create(['title' => 'مهمة ألف', 'project_id' => $this->a->id, 'status' => 'جديدة']);
        // ضمن نافذة «يستحق التجديد» كي يسردها القالب فعلاً
        Contract::create(['title' => 'عقد ألف', 'type' => 'خدمات', 'status' => 'ساري',
                          'date_end' => now()->addDays(20)->toDateString(), 'project_id' => $this->a->id]);
        Contract::create(['title' => 'عقد باء', 'type' => 'خدمات', 'status' => 'ساري',
                          'date_end' => now()->addDays(25)->toDateString(), 'project_id' => $this->b->id]);
        Idea::create(['title' => 'فكرة ألف', 'status' => 'جديدة', 'project_id' => $this->a->id]);
        Idea::create(['title' => 'فكرة باء', 'status' => 'جديدة', 'project_id' => $this->b->id]);

        foreach (['/capacity', '/recommendations', '/impact', '/app-quality', '/legal', '/innovation'] as $url) {
            $this->actingAs($this->owner)->get($url . '?p=' . $this->a->id)
                ->assertOk()->assertSee('عدسة المشروع');
        }

        $this->actingAs($this->owner)->get('/legal?p=' . $this->a->id)
            ->assertOk()->assertSee('عقد ألف')->assertDontSee('عقد باء');
        $this->actingAs($this->owner)->get('/innovation?p=' . $this->a->id)
            ->assertOk()->assertSee('فكرة ألف')->assertDontSee('فكرة باء');
    }

    public function test_ideas_reach_the_project_through_their_service_too(): void
    {
        $this->scene();
        $s = \App\Models\Service::create(['name' => 'خدمة ألف', 'status' => 'نشطة', 'project_id' => $this->a->id]);
        Idea::create(['title' => 'فكرة عبر الخدمة', 'status' => 'جديدة', 'service_id' => $s->id]);

        $this->actingAs($this->owner)->get('/innovation?p=' . $this->a->id)
            ->assertOk()->assertSee('فكرة عبر الخدمة');
    }

    // ── حارس الانحدار: بلا عدسة لا يتغير شيء ──
    public function test_without_a_lens_nothing_changes(): void
    {
        $this->scene();
        $d = fn (int $n) => now()->addDays($n)->toDateString();
        Contract::create(['title' => 'عقد ألف', 'type' => 'خدمات', 'status' => 'ساري',
                          'date_end' => $d(20), 'project_id' => $this->a->id]);
        Contract::create(['title' => 'عقد باء', 'type' => 'خدمات', 'status' => 'ساري',
                          'date_end' => $d(21), 'project_id' => $this->b->id]);
        Contract::create(['title' => 'عقد بلا مشروع', 'type' => 'خدمات', 'status' => 'ساري',
                          'date_end' => $d(22)]);

        $this->actingAs($this->owner)->get('/legal')->assertOk()
            ->assertSee('عقد ألف')->assertSee('عقد باء')->assertSee('عقد بلا مشروع');
    }
}
