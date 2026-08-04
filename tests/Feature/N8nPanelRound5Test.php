<?php

namespace Tests\Feature;

use App\Models\Setting;
use Tests\TestCase;

/**
 * بند ٥ (n8n): لوحةُ ربط n8n في مركز التكامل — يُوصَل مثيلٌ منفصلٌ يعمل على الخادم
 * (Docker) بالنظام. الرابط يمرّ بحارس SSRF، والمفتاح يُخزَّن مشفَّراً، والمفاتيح
 * داخليةٌ لا تظهر في الإعدادات العامة.
 */
class N8nPanelRound5Test extends TestCase
{
    public function test_owner_sees_panel_and_saves_connection(): void
    {
        $this->seedCore();
        $this->app->instance('hub.dns', fn (string $h) => ['93.184.216.34']);

        $this->actingAs($this->owner)->get(route('integrations.n8n'))->assertOk()
            ->assertSee('n8n');

        $this->actingAs($this->owner)->post(route('integrations.n8n.save'),
            ['url' => 'https://n8n.example.com', 'key' => 'n8n_api_secret'])->assertRedirect();

        $this->assertSame('https://n8n.example.com', (string) setting('n8n.url'));
        $this->assertSame('n8n_api_secret', (string) setting('n8n.key'), 'المفتاح لم يُفكّ تشفيره');
        $this->assertStringStartsWith('enc:', (string) Setting::where('key', 'n8n.key')->value('value'),
            'مفتاح n8n خُزّن خاماً');
    }

    public function test_internal_target_is_refused_by_ssrf_guard(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('integrations.n8n.save'),
            ['url' => 'http://169.254.169.254/'])
            ->assertSessionHasErrors('url');

        $this->assertSame('', (string) setting('n8n.url'), 'رابطٌ داخليّ حُفظ رغم حارس SSRF');
    }

    public function test_panel_is_owner_only(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get(route('integrations.n8n'))->assertForbidden();
        $this->actingAs($this->employee)->post(route('integrations.n8n.save'), ['url' => 'https://x.test'])->assertForbidden();
    }

    public function test_n8n_keys_are_not_in_general_settings_catalog(): void
    {
        $keys = \App\Http\Controllers\Web\SettingController::exposedKeys();
        $this->assertNotContains('n8n.url', $keys);
        $this->assertNotContains('n8n.key', $keys);
    }
}
