<?php

namespace Tests\Feature;

use App\Models\SavedView;
use App\Models\Task;
use Tests\TestCase;

class SavedViewsTest extends TestCase
{
    public function test_save_strips_page_and_view_then_redirects_applied(): void
    {
        $this->seedCore();

        $r = $this->actingAs($this->employee)->post('/views', [
            'module' => 'tasks', 'name' => 'المتأخرة',
            'query' => 'status=قيد التنفيذ&s=due&d=asc&page=7&view=zzz',
        ]);

        $v = SavedView::first();
        $this->assertSame('المتأخرة', $v->name);
        $this->assertStringNotContainsString('page', (string) $v->query);
        $this->assertStringNotContainsString('view', (string) $v->query);
        $r->assertRedirect($v->url());
    }

    public function test_default_view_auto_applies_on_bare_visit_only(): void
    {
        $this->seedCore();
        $v = SavedView::create(['user_id' => $this->employee->id, 'module' => 'tasks',
            'name' => 'افتراضي', 'query' => 's=due&d=asc', 'is_default' => true]);

        $this->actingAs($this->employee)->get('/m/tasks')->assertRedirect($v->url());
        $this->actingAs($this->employee)->get('/m/tasks?q=بحث')->assertOk();     // استعلام صريح لا يُخطف
        $this->actingAs($this->employee)->get($v->url())->assertOk();            // لا دوران تحويلات
    }

    public function test_views_are_private_per_user(): void
    {
        $this->seedCore();
        $v = SavedView::create(['user_id' => $this->employee->id, 'module' => 'tasks',
            'name' => 'خاص', 'query' => null, 'is_default' => true]);

        // مستخدم آخر: لا يراه في قائمته، ولا يحذفه، ولا يُحوَّل إليه
        $this->actingAs($this->viewer)->get('/m/tasks')->assertOk()->assertDontSee('خاص');
        $this->actingAs($this->viewer)->delete('/views/' . $v->id)->assertNotFound();
        $this->assertSame(1, SavedView::count());
    }

    public function test_single_default_per_module_and_toggle(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->post('/views', ['module' => 'tasks', 'name' => 'أ', 'default' => 1]);
        $this->actingAs($this->employee)->post('/views', ['module' => 'tasks', 'name' => 'ب', 'default' => 1]);

        $this->assertSame(1, SavedView::where('is_default', true)->count());
        $this->assertSame('ب', SavedView::where('is_default', true)->value('name'));

        $b = SavedView::firstWhere('name', 'ب');
        $this->actingAs($this->employee)->post('/views/' . $b->id . '/default');
        $this->assertSame(0, SavedView::where('is_default', true)->count());
    }

    public function test_user_columns_override_registry_and_respect_field_permissions(): void
    {
        $this->seedCore();
        Task::create(['title' => 'مهمة أعمدة', 'status' => 'جديدة']);

        $this->actingAs($this->employee)->post('/personalize/cols', [
            'module' => 'tasks', 'cols' => ['title', 'status', 'nope'],
        ])->assertRedirect();
        $this->assertSame(['title', 'status'], $this->employee->fresh()->prefs['cols']['tasks']);

        $html = $this->actingAs($this->employee->fresh())->get('/m/tasks?q=')->assertOk()->getContent();
        preg_match('/<thead>.*?<\/thead>/su', $html, $m);
        $this->assertStringContainsString('عنوان المهمة', $m[0]);
        $this->assertStringNotContainsString('المشروع', $m[0], 'عمود غير مختار لا يظهر');

        // حقل محجوب عن الدور لا يظهر ولو اختاره المستخدم
        $role = $this->employee->role;
        $role->update(['field_rules' => ['tasks' => ['status' => 'hide']]]);
        $html = $this->actingAs($this->employee->fresh())->get('/m/tasks?q=')->assertOk()->getContent();
        preg_match('/<thead>.*?<\/thead>/su', $html, $m);
        $this->assertStringNotContainsString('>الحالة', $m[0]);
    }

    /** حفظ شاشة التخصيص لا يمحو أعمدة الجداول المحفوظة بمسار آخر */
    public function test_personalize_save_preserves_column_prefs(): void
    {
        $this->seedCore();
        $this->employee->update(['prefs' => ['cols' => ['tasks' => ['title', 'status']]]]);

        $this->actingAs($this->employee->fresh())->post('/personalize', ['home' => 'dashboard'])
            ->assertRedirect();

        $this->assertSame(['title', 'status'], $this->employee->fresh()->prefs['cols']['tasks'] ?? null,
            'أعمدة الجداول يجب أن تنجو من حفظ شاشة التخصيص');
    }

    /** بلوغ حد ٣٠ عرضاً يعيد رسالة فلاش لا رد ٤٢٢ صامت */
    public function test_view_cap_flashes_instead_of_aborting(): void
    {
        $this->seedCore();
        for ($i = 0; $i < 30; $i++) {
            \App\Models\SavedView::create(['user_id' => $this->employee->id, 'module' => 'tasks',
                'name' => 'ع' . $i, 'query' => null]);
        }

        $this->actingAs($this->employee)->post('/views', ['module' => 'tasks', 'name' => 'الحادي والثلاثون'])
            ->assertRedirect()->assertSessionHas('err');
        $this->assertSame(30, \App\Models\SavedView::where('module', 'tasks')->count());
    }

    /** إزالة الفلتر تُسقط علامة العرض view= فلا تبقى «مطبَّقة» على بيانات لا تطابقها */
    public function test_removing_filter_chip_drops_view_marker(): void
    {
        $this->seedCore();
        $p = \App\Models\Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        \App\Models\Task::create(['title' => 'مهمة', 'project_id' => $p->id, 'status' => 'جديدة']);
        $v = \App\Models\SavedView::create(['user_id' => $this->employee->id, 'module' => 'tasks',
            'name' => 'عرض', 'query' => 'f[projectId]=' . $p->id, 'is_default' => false]);

        $html = $this->actingAs($this->employee)
            ->get('/m/tasks?f[projectId]=' . $p->id . '&view=' . $v->id)->assertOk()->getContent();
        // رابط إزالة الرقاقة (داخل <span class="chip">…<a href=…>✕) يُسقط view تماماً
        $this->assertMatchesRegularExpression('/class="chip"[^<]*<a href="([^"]*)"/u', $html);
        preg_match('/class="chip"[^<]*<a href="([^"]*)"/u', $html, $m);
        $chipHref = html_entity_decode($m[1]);
        $this->assertStringNotContainsString('view=', $chipHref,
            'إزالة الفلتر يجب ألا تُبقي علامة العرض view=');
    }

    public function test_reset_returns_default_columns(): void
    {
        $this->seedCore();
        $this->employee->update(['prefs' => ['cols' => ['tasks' => ['title']]]]);

        $this->actingAs($this->employee->fresh())->post('/personalize/cols', [
            'module' => 'tasks', 'cols' => ['title'], 'reset' => 1,
        ])->assertRedirect();

        $this->assertNull($this->employee->fresh()->prefs['cols']['tasks'] ?? null);
    }
}
