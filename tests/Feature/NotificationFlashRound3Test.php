<?php

namespace Tests\Feature;

use App\Models\HubNotification;
use Tests\TestCase;

/**
 * بند ٣: تنبيهٌ أشدُّ لفتاً — نقطةُ عدٍّ خفيفة تستفتيها الواجهة دوريّاً فتُحدِّث
 * الشارة وعنوانَ التبويب وتُطلق وميضَ إطار الشاشة كل N دقيقة (افتراضياً ١٠)
 * ما دام هناك غيرُ مقروء. هنا نختبر النقطةَ الخلفية والإعداد.
 */
class NotificationFlashRound3Test extends TestCase
{
    protected function notify(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            hub_notify($this->owner->id, 'تنبيه', 'إشعار ' . $i);
        }
    }

    public function test_count_endpoint_returns_unread_and_flash_interval(): void
    {
        $this->seedCore();
        $this->notify(3);
        // إشعارٌ مقروءٌ لا يُحسَب
        HubNotification::create(['user_id' => $this->owner->id, 'kind' => 'تنبيه',
            'text' => 'مقروء', 'read' => true, 'created_at' => now()]);

        $this->actingAs($this->owner)->getJson(route('notifications.count'))
            ->assertOk()
            ->assertJson(['unread' => 3, 'flashMin' => 10]);
    }

    public function test_flash_interval_is_configurable(): void
    {
        $this->seedCore();
        $this->hubSetting('notify.flash_min', '5');

        $this->actingAs($this->owner)->getJson(route('notifications.count'))
            ->assertOk()->assertJson(['flashMin' => 5]);
    }

    public function test_layout_wires_the_count_url_for_the_flash(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('data-count-url="' . route('notifications.count') . '"', $html,
            'الجرس لا يحمل رابط العدّ — الوميض لن يعمل');
    }
}
