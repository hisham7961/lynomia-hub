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
