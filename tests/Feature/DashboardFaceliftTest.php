<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use Tests\TestCase;

/** وجه اللوحة الجديد: ترويسة ترحيب حية، بطاقات باتجاه أسبوعي، روابط سريعة، سيل نشاط بصور رمزية */
class DashboardFaceliftTest extends TestCase
{
    public function test_dashboard_renders_new_face(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $p = Project::create(['name' => 'مشروع']);
        Task::create(['title' => 'مهمة', 'project_id' => $p->id]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('class="welcome"', $html);          // ترويسة الترحيب
        $this->assertStringContainsString(auth()->user()->name, $html);
        $this->assertStringContainsString('class="qlinks"', $html);           // روابط سريعة
        $this->assertStringContainsString('التقويم', $html);
        $this->assertStringContainsString('class="actfeed"', $html);          // سيل النشاط
        $this->assertStringContainsString('class="avx"', $html);              // صورة رمزية
        $this->assertStringContainsString('عن الأسبوع الماضي', $html);        // اتجاه أسبوعي (مهمة أنشئت الآن)
    }

    /** سطر «ما ينتظرك»: يذكر المهام المفتوحة على المستخدم */
    public function test_pending_line_counts_my_open_tasks(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'م']);
        Task::create(['title' => 'مفتوحة عليّ', 'project_id' => $p->id,
                      'assignee_id' => $this->owner->id, 'status' => 'جديدة']);

        $html = $this->actingAs($this->owner)->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('مهام مفتوحة عليك', $html);
    }

    /** ودجة الروابط تحترم الصلاحية: بلا رؤية مالية لا تظهر بلاطة التقارير المالية */
    public function test_quick_links_respect_permissions(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->viewer)->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('class="qlinks"', $html);

        $noFin = \App\Models\Role::create(['name' => 'بلا مالية', 'scope' => 'all', 'flags' => [],
            'matrix' => collect(array_keys(config('hub.modules')))
                ->mapWithKeys(fn ($m) => [$m => ['v' => $m === 'fin' ? 0 : 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all()]);
        $u = \App\Models\User::create(['name' => 'محدود', 'email' => 'nofin@test.local',
            'password' => 'Secret!2026x', 'role_id' => $noFin->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $html2 = $this->actingAs($u)->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('التقارير المالية', $html2);
    }
}
