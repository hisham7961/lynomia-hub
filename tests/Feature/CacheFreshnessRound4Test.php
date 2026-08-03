<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Task;
use Tests\TestCase;

/**
 * الموجة الرابعة — نضارة الخبيئة (missing-data-stamp):
 * خبيئتا رادار الانتهاء (hub_expiry) والتقويم الموحّد بمهلة (٥–١٠ دقائق) بلا ختم
 * بيانات — فتعديلُ سجلٍّ مؤرَّخ (تجديد عقد، موعد مهمة جديد) لا يظهر حتى انقضاء
 * المهلة، ولا زر تحديث. الختم يسبق المهلة: المفتاح يحمل hub_data_stamp فيتغيّر
 * ساعةَ تتغيّر البيانات.
 */
class CacheFreshnessRound4Test extends TestCase
{
    /** (ب) الرادار يعكس تجديد العقد فوراً بلا fresh قسري */
    public function test_expiry_radar_reflects_renewal_without_forced_fresh(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $c = Contract::create(['title' => 'عقد على وشك الانتهاء', 'type' => 'عقد عميل',
            'date_end' => now()->addDays(10)->toDateString(), 'status' => 'ساري']);

        $before = collect(hub_expiry(false))->pluck('name');
        $this->assertTrue($before->contains('عقد على وشك الانتهاء'), 'العقد لا يظهر في الرادار أصلاً');

        // جدّده — يخرج من نافذة الـ30 يوماً
        $c->update(['date_end' => now()->addYear()->toDateString()]);

        $after = collect(hub_expiry(false))->pluck('name');
        $this->assertFalse($after->contains('عقد على وشك الانتهاء'),
            'الرادار ظل يُنذر بعقدٍ جُدِّد — الخبيئة قديمة بلا ختم بيانات');
    }

    /** (أ) التقويم يعرض سجلاً مؤرَّخاً جديداً بلا fresh قسري */
    public function test_calendar_reflects_new_dated_record_without_forced_fresh(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $month = now()->format('Y-m');

        $this->get('/calendar?month=' . $month)->assertOk();   // يملأ الخبيئة

        Task::create(['title' => 'مهمة تقويم فريدة', 'status' => 'جديدة', 'due' => now()->toDateString()]);

        $this->get('/calendar?month=' . $month)->assertOk()->assertSee('مهمة تقويم فريدة');
    }
}
