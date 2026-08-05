<?php

namespace Tests\Feature;

use Tests\TestCase;

/** ترويسة هوية الوحدة: أيقونة ولون مجموعتها + العدد — في كل صفحات الوحدات */
class ModuleHeroTest extends TestCase
{
    public function test_module_index_shows_identity_hero(): void
    {
        $this->seedCore();
        \App\Models\Task::create(['title' => 'م١']);
        \App\Models\Task::create(['title' => 'م٢']);

        $html = $this->actingAs($this->owner)->get('/m/tasks')->assertOk()->getContent();
        // v2.294: الترويسة المرتقاة — رأسٌ موحّد (lx-head) وعدّادٌ حيّ كوسام (lx-count)
        $this->assertStringContainsString('class="lx-head"', $html);
        $this->assertStringContainsString('class="lx-count"', $html);
        $this->assertStringContainsString('✅', $html);                    // أيقونة المهام
        $this->assertStringContainsString('#0E7C66', $html);               // لون مجموعة «العمل»
        $this->assertStringContainsString('2 سجل', $html);
    }

    /** صفحة السجل v2.108: ترويسة موحدة (مسار ← هوية ← حالة ← إجراءات) وجسم بعمودين وسكة جانبية */
    public function test_record_show_page_has_identity_hero_with_status(): void
    {
        $this->seedCore();
        $t = \App\Models\Task::create(['title' => 'مهمة الترويسة', 'status' => 'قيد التنفيذ']);

        $html = $this->actingAs($this->owner)->get("/m/tasks/{$t->id}")->assertOk()->getContent();
        $this->assertStringContainsString('class="rechead"', $html);      // ترويسة السجل الموحدة
        $this->assertStringContainsString('class="crumbs"', $html);       // مسار التنقل
        $this->assertStringContainsString('class="rec-rail"', $html);     // السكة الجانبية
        $this->assertStringContainsString('مهمة الترويسة', $html);
        $this->assertStringContainsString('bdg wn', $html);               // «قيد التنفيذ» برتقالية
        $this->assertStringContainsString('tlv2', $html);                 // الخط الزمني الجديد
    }

    /**
     * صفحةُ النموذج (v2.303): رأسٌ موحّد (lx-head) وبطاقةٌ مرتقاة (lx-form) تحمل لون
     * الوحدة — فالنموذج يتّسق مع رأسه وقائمته بدل بطاقةٍ عارية، والعنوانُ الداخليّ
     * المكرّر (mhead) يُخفى على الصفحة الكاملة (يبقى في النافذة المنبثقة).
     */
    public function test_module_form_uses_lifted_identity_card(): void
    {
        $this->seedCore();

        $html = $this->actingAs($this->owner)->get('/m/tasks/create')->assertOk()->getContent();
        $this->assertStringContainsString('class="lx-head"', $html);          // الرأس الموحّد
        $this->assertStringContainsString('class="card lx-form"', $html);     // البطاقة المرتقاة
        $this->assertStringContainsString('#0E7C66', $html);                  // لون مجموعة «العمل» يُمرَّر للنموذج
    }

    public function test_look_helper_covers_every_module(): void
    {
        $this->seedCore();
        foreach (array_keys(config('hub.modules')) as $mk) {
            $look = hub_mod_look($mk);
            $this->assertNotEmpty($look['icon'], "وحدة {$mk} بلا أيقونة");
            $this->assertNotEmpty($look['color'], "وحدة {$mk} بلا لون");
        }
    }
}
