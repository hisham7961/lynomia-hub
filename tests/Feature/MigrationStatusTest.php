<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\OpsController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * (WP-2.7 · spec §3.14) حالةُ ترحيلات القاعدة في مركز التشغيل: الدفعةُ الحالية،
 * وقائمةُ المعلّق، **وعدّادٌ واحدٌ متّفق** — كان في النظام عدّادان (نسخةُ
 * `hub_pending_migrations()` التي يقرؤها `/healthz`، ونسخةُ
 * `OpsController::pendingMigrations` الخاصة) وقد يختلفان بخبيئةٍ أو بعطل،
 * فيقول المركزُ قولاً والمراقبةُ قولاً آخر. التوحيد: المتحكّم يستهلك المساعِدَ
 * ولا يملك نسخته.
 */
class MigrationStatusTest extends TestCase
{
    /** النسخةُ المكرَّرة حُذفت فعلاً — لا عدّادَ ثانياً يعود بالتقادم */
    public function test_the_duplicate_counter_is_gone(): void
    {
        $this->assertFalse(method_exists(OpsController::class, 'pendingMigrations'),
            'OpsController::pendingMigrations ما زالت موجودة — عدّادان لحقيقةٍ واحدة');
    }

    /** الدفعةُ الحالية تُعرض من جدول migrations نفسِه */
    public function test_screen_shows_current_batch(): void
    {
        $this->seedCore();
        $batch = (int) DB::table('migrations')->max('batch');

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();

        $this->assertStringContainsString('الدفعة الحالية', $html, 'الدفعة الحالية لا تُعرض');
        $this->assertStringContainsString('mig-batch">' . $batch . '<', $html,
            'رقم الدفعة المعروض لا يطابق MAX(batch)');
    }

    /**
     * العدّادُ الواحد: حذفُ صفِّ ترحيلٍ من الجدول يجعل ملفَّه «معلّقاً» —
     * فيتّفق ما يعرضه المركزُ (القائمة والعدّاد) مع ما يعدّه المساعِدُ الذي
     * يقرؤه `/healthz`، عن مصدرٍ واحد.
     */
    public function test_pending_counter_is_the_shared_helper(): void
    {
        $this->seedCore();

        $last = DB::table('migrations')->orderByDesc('id')->first();
        DB::table('migrations')->where('id', $last->id)->delete();
        Cache::forget('hub.pending_migrations');

        $this->assertSame(1, hub_pending_migrations(), 'حذف صفّ ترحيلٍ لم يجعله معلّقاً');

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();

        $this->assertStringContainsString($last->migration, $html,
            'اسم الترحيل المعلّق غائب عن القائمة');
        $this->assertStringContainsString('mig-pending">1<', $html,
            'العدّاد المعروض لا يساوي hub_pending_migrations()');
    }
}
