<?php

namespace Tests\Feature;

use App\Models\Task;
use Tests\TestCase;

/** v2.112: البحث الشامل بوصفه لوحة أوامر — وضع البداية، الإجراءات، الاختصارات، والوصولية */
class SearchPaletteTest extends TestCase
{
    public function test_focus_mode_returns_destinations_and_actions_without_typing(): void
    {
        $this->seedCore();

        $html = $this->actingAs($this->owner)->get('/search/mini?q=')->assertOk()->getContent();
        $this->assertStringContainsString('اكتب للبحث في كل شيء', $html);   // سطر الإرشاد
        $this->assertStringContainsString('صفحة', $html);                    // وجهات فورية
        $this->assertStringContainsString('إجراء', $html);                   // ＋ جديد
        $this->assertStringContainsString('سجل جديد', $html);
    }

    public function test_single_char_still_returns_nothing(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/search/mini?q=م')->assertOk()->assertSee('');
        $this->assertSame('', $this->actingAs($this->owner)->get('/search/mini?q=م')->getContent());
    }

    public function test_actions_respect_add_permission(): void
    {
        $this->seedCore();

        // المشاهد (عرض فقط): وجهاتٌ نعم، إجراءات إضافة لا
        $html = $this->actingAs($this->viewer)->get('/search/mini?q=')->assertOk()->getContent();
        $this->assertStringContainsString('صفحة', $html);
        $this->assertStringNotContainsString('سجل جديد', $html);
    }

    public function test_typed_query_returns_matching_action_and_records(): void
    {
        $this->seedCore();
        Task::create(['title' => 'مهمة للبحث الحي', 'status' => 'جديدة']);

        $html = $this->actingAs($this->owner)->get('/search/mini?q=' . urlencode('مهام'))->assertOk()->getContent();
        $this->assertStringContainsString('سجل جديد', $html);               // إجراء «＋ المهام»
        $this->assertStringContainsString('/m/tasks/create', $html);

        $html2 = $this->actingAs($this->owner)->get('/search/mini?q=' . urlencode('للبحث الحي'))->assertOk()->getContent();
        $this->assertStringContainsString('مهمة للبحث الحي', $html2);       // السجل نفسه
    }

    public function test_recent_records_appear_in_focus_mode(): void
    {
        $this->seedCore();
        $t = Task::create(['title' => 'مهمة زرتها للتو', 'status' => 'جديدة']);

        // زيارة صفحة السجل تُسجَّل عبر TrackVisits ثم تظهر في «الأخيرة»
        $this->actingAs($this->owner)->get("/m/tasks/{$t->id}")->assertOk();
        $html = $this->actingAs($this->owner)->get('/search/mini?q=')->assertOk()->getContent();
        $this->assertStringContainsString('🕘', $html);
        $this->assertStringContainsString('مهمة زرتها للتو', $html);
    }

    public function test_recent_records_revalidate_deleted_and_foreign_paths(): void
    {
        $this->seedCore();
        $t = Task::create(['title' => 'ستُحذف بعد الزيارة', 'status' => 'جديدة']);
        $this->actingAs($this->owner)->get("/m/tasks/{$t->id}")->assertOk();
        $t->delete();

        // زيارة مزروعة لمسار لا وحدة له — تُتجاهل بصمت
        \Illuminate\Support\Facades\DB::table('page_visits')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->owner->id,
            'path' => '/m/ghost-module/123', 'route' => 'm.show', 'at' => now(),
        ]);

        $html = $this->actingAs($this->owner)->get('/search/mini?q=')->assertOk()->getContent();
        $this->assertStringNotContainsString('ستُحذف بعد الزيارة', $html);   // المحذوف لا يعود عبر «الأخيرة»
        $this->assertStringNotContainsString('ghost-module', $html);
    }

    public function test_topbar_is_accessible_combobox_with_palette_wiring(): void
    {
        $this->seedCore();

        $html = $this->actingAs($this->owner)->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('role="combobox"', $html);
        $this->assertStringContainsString('aria-controls="gsr"', $html);
        $this->assertStringContainsString('role="listbox"', $html);
        $this->assertStringContainsString('class="kbdhint"', $html);        // تلميح Ctrl K مرئي
        $this->assertStringContainsString('input changed delay:300ms, focus', $html); // التركيز يفتح اللوحة

        $js = file_get_contents(public_path('js/app.js'));
        $this->assertStringContainsString("e.key === 'k'", $js);            // اختصار Ctrl+K
        $this->assertStringContainsString("ArrowDown", $js);                // تنقّل ↑↓
        $this->assertStringContainsString("classList.add('sel')", $js);
    }
}
