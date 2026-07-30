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
        $this->assertStringContainsString('class="modhero"', $html);
        $this->assertStringContainsString('✅', $html);                    // أيقونة المهام
        $this->assertStringContainsString('#0E7C66', $html);               // لون مجموعة «العمل»
        $this->assertStringContainsString('2 سجل', $html);
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
