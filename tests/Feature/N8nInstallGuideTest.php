<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * شاشةُ n8n كانت تُحيل إلى ملفٍ على الخادم دون أن تعرض الخطوات — «لم أفهم كيف
 * أركّبه». الآن الخطواتُ نفسُها (الأمرُ، النطاقُ الفرعيّ، شرطُ Docker) أمام
 * المستخدم في الشاشة، بلا مغادرتها.
 */
class N8nInstallGuideTest extends TestCase
{
    public function test_panel_shows_concrete_install_steps(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->get(route('integrations.n8n'))
            ->assertOk()
            ->assertSee('docker compose up -d')      // الأمرُ الفعليّ لا مجرّد إحالة
            ->assertSee('cp .env.example .env')       // خطوةُ التهيئة
            ->assertSee('reverse proxy');             // النطاقُ الفرعيّ عبر البروكسي
    }
}
