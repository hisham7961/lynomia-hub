<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * «اختبار استعادة النسخ» أُخرج من الواجهة بطلب المالك: لا مدخل في التنقّل ولا
 * تنبيه في موجز الصباح. والبيانات والمسار باقيان (لا حذف ولا هجرة مدمّرة) —
 * من يحتاجه يفتحه برابطه المباشر، ومن لا يحتاجه لا يراه.
 */
class RestoresRetiredTest extends TestCase
{
    public function test_it_is_gone_from_navigation(): void
    {
        $this->seedCore();
        $groups = collect(config('hub_nav'))->flatMap(fn ($g) => $g['items']);
        $this->assertFalse($groups->contains('restores'), 'ما زال في مجموعات التنقّل');

        $html = $this->actingAs($this->owner)->get('/m/tasks')->assertOk()->getContent();
        $this->assertStringNotContainsString('/m/restores', $html, 'ما زال رابطه في الشريط');
    }

    public function test_morning_no_longer_nags_about_restore_tests(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/morning')->assertOk()->getContent();
        $this->assertStringNotContainsString('لم تُختبر استعادة النسخ', $html);
    }

    public function test_the_data_and_route_survive(): void
    {
        $this->seedCore();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('restore_tests'),
            'لا هجرة مدمّرة — الجدول باقٍ');
        $this->actingAs($this->owner)->get('/m/restores')->assertOk();
    }
}
