<?php

namespace Tests\Feature;

use Tests\TestCase;

class PwaTest extends TestCase
{
    public function test_manifest_reflects_settings(): void
    {
        $this->seedCore();
        \App\Models\Setting::create(['key' => 'app.name', 'value' => 'شركتي الرائعة']);
        \Illuminate\Support\Facades\Cache::forget('settings:all');

        $this->get('/manifest.webmanifest')->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json; charset=UTF-8')
            ->assertJsonPath('name', 'شركتي الرائعة')
            ->assertJsonPath('dir', 'rtl');
    }

    public function test_icon_and_offline_are_public(): void
    {
        $this->seedCore();
        $this->get('/pwa-icon.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8');
        $this->get('/offline')->assertOk()->assertSee('لا اتصال');
    }

    public function test_layout_links_manifest_and_sw_exists(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/')->assertOk()
            ->assertSee('manifest.webmanifest', false)
            ->assertSee('theme-color', false);
        $this->assertFileExists(public_path('sw.js'));
    }

    public function test_module_form_carries_draft_key(): void
    {
        $this->seedCore();
        // المفتاحُ يبدأ بمعرّف المستخدم (v2.323): مسوّدةُ حسابٍ كانت تُسترجَع في
        // نموذج حسابٍ آخر على المتصفح نفسه — والحارسُ الدقيق في
        // PrintAndBrowserLeaksRound6Test
        $this->actingAs($this->owner)->get('/m/clients/create')->assertOk()
            ->assertSee('data-draft="' . $this->owner->id . ':clients:new"', false);
    }
}
