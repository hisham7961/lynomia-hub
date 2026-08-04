<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\SettingController;
use App\Models\Setting;
use Tests\TestCase;

/**
 * بند ٦: تجميعُ تكامل تلجرام في مركز التكامل — كان توكن البوت والقناة مبعثرَين
 * وسط عشرات مفاتيح الإعدادات العامة. الآن يُداران من مركز المراسلة (المفتاحان
 * والتخزين المشفَّر كما هما)، ويُزالان من كتالوج الإعدادات العامة.
 */
class TelegramInIntegrationCenterTest extends TestCase
{
    public function test_telegram_config_is_saved_from_the_integration_center(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)
            ->post(route('integrations.messaging.telegram'),
                ['tg_token' => '123456:ABC-token', 'tg_chat' => '@lyn_channel'])
            ->assertRedirect();

        $this->assertSame('123456:ABC-token', (string) setting('notify.tg_token'),
            'التوكن لم يُحفظ أو لم يُفكّ تشفيره من مركز التكامل');
        $this->assertSame('@lyn_channel', (string) setting('notify.tg_chat'));

        // مخزَّنٌ مشفَّراً (enc:) لا خاماً
        $this->assertStringStartsWith('enc:', (string) Setting::where('key', 'notify.tg_token')->value('value'),
            'التوكن خُزِّن خاماً بلا تشفير');
    }

    public function test_telegram_form_shows_in_messaging_center(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->get(route('integrations.messaging'))
            ->assertOk()
            ->assertSee(route('integrations.messaging.telegram'), false);
    }

    public function test_telegram_keys_left_the_general_settings_catalog(): void
    {
        $keys = SettingController::exposedKeys();
        $this->assertNotContains('notify.tg_token', $keys,
            'توكن تلجرام ما يزال مبعثراً في الإعدادات العامة — لم يُجمَّع في مركز التكامل');
        $this->assertNotContains('notify.tg_chat', $keys);
    }
}
