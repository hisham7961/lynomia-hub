<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * (WP-2.7 · spec §25) تحمّلُ العطل في مركز التشغيل: شاشةُ التشغيل هي التي تُفتح
 * **وقتَ العطل** — فسقوطُها بخطأ ٥٠٠ لحظةَ سقوطِ جدولٍ هو أسوأُ سلوكٍ ممكن.
 *
 * ثبت بالتشغيل الفعلي أنّ إسقاط جدول `outbox` كان يُسقط `/admin/ops` كاملاً
 * (قارئُ `DB::table('outbox')->pluck` بلا حارس في OpsController::index) —
 * فالقاعدة المحروسة هنا: كلُّ قسمٍ يقرأ جدولاً يلفّ قراءتَه، والقسمُ الساقط
 * يُعرض «غير متاح» بينما بقيّةُ الشاشة — وفي مقدّمتها حالةُ الصحّة الحرجة —
 * تبقى ظاهرة.
 */
class OpsResilienceTest extends TestCase
{
    /** إسقاطُ outbox: الصفحة ٢٠٠، قسمُ الطوابير «غير متاح»، وحكمُ نموذج الصحّة على المكوّن يبقى ظاهراً */
    public function test_dropping_outbox_renders_unavailable_not_500(): void
    {
        $this->seedCore();
        Schema::drop('outbox');

        $html = $this->actingAs($this->owner)->get('/admin/ops')
            ->assertOk()->getContent();

        $this->assertStringContainsString('غير متاح', $html,
            'قسمُ الطوابير الساقط يجب أن يقول «غير متاح» لا أن يختفي صامتاً');
        // الحالةُ الحرجة لا تُبتلع: نموذجُ الصحّة يقول حكمَه على المكوّن الغائب في الترويسة نفسِها
        $this->assertStringContainsString('الجدول غائب', $html,
            'حكمُ نموذج الصحّة على مكوّن outbox الغائب اختفى — الحارسُ أخفى الحالةَ الحرجة');
    }

    /** إسقاطُ error_events: قارئا «مؤشرات ٧ أيام» وانحدارِ الأخطاء لا يُسقطان الشاشة */
    public function test_dropping_error_events_renders_unavailable_not_500(): void
    {
        $this->seedCore();
        Schema::drop('error_events');

        $html = $this->actingAs($this->owner)->get('/admin/ops')
            ->assertOk()->getContent();

        $this->assertStringContainsString('غير متاح', $html,
            'قسمُ مؤشرات الأخطاء الساقط يجب أن يقول «غير متاح»');
    }

    /** والقسمُ الفاشل لا يعرض أصفاراً كاذبة: «٠ فاشلة» على جدولٍ غائب طمأنةٌ زائفة */
    public function test_dropped_outbox_shows_no_fake_zero_stats(): void
    {
        $this->seedCore();
        Schema::drop('outbox');

        $html = $this->actingAs($this->owner)->get('/admin/ops')
            ->assertOk()->getContent();

        // جدولُ إحصاءات الصندوق (ob-stats) لا يُرسم حين تتعذّر قراءتُه —
        // فلا «أقدمُ منتظرة: لا رسائل» ولا «المُسلَّم: 0» فوق جدولٍ غير موجود
        $this->assertStringNotContainsString('ob-stats', $html,
            'إحصاءاتُ الصندوق ظهرت بأصفارٍ كاذبة فوق جدولٍ غائب');
    }
}
