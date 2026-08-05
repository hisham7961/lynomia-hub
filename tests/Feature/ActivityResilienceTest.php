<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * مركز نشاط الموظفين — «التفاصيل» صفحةٌ مراقبةٍ مساعدة: يجب ألّا يُسقطها غيابُ
 * جدولٍ مساعدٍ واحد. على استضافةٍ متأخّرةٍ في الهجرات كان جدول `user_ips` (يقرؤه
 * `show` وحده لا `index`) غائباً، فتفتح القائمةُ وتسقط التفاصيلُ بـ500 («هناك خطا»).
 * الآن كلُّ مصدرِ بياناتٍ محميٌّ فيتحمّل غيابه: تُعرَض التفاصيلُ المتاحة لا صفحةُ خطأ.
 */
class ActivityResilienceTest extends TestCase
{
    public function test_activity_details_survive_a_missing_auxiliary_table(): void
    {
        $this->seedCore();

        // نُحاكي استضافةً متأخّرةً في الهجرات: جدولٌ مساعدٌ غائب
        Schema::drop('user_ips');

        $this->actingAs($this->owner)->get('/admin/activity/' . $this->employee->id)
            ->assertOk();   // قبل الإصلاح: 500
    }

    public function test_activity_center_list_still_renders(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/admin/activity')->assertOk();
    }
}
