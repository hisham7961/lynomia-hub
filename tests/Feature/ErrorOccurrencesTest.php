<?php

namespace Tests\Feature;

use App\Models\ErrorEvent;
use App\Support\ErrorLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WP-3.2 «العيّناتُ المحدودة»: كلُّ وقوعٍ لخطأٍ مجمَّع يترك عيّنةً في
 * `error_occurrences` (أيُّ طلبٍ سبّبه؟ أيُّ مستخدم؟ أيُّ نسخة؟) بسقفٍ
 * صلبٍ لكل حدثٍ — الجدولُ عيّناتٌ لا أرشيف، والعدُّ الحقيقيّ يبقى في
 * `error_events.count` لا يمسّه القصّ.
 */
class ErrorOccurrencesTest extends TestCase
{
    /** ٦٠ وقوعاً ⇒ بالضبط ٥٠ صفاً (الأحدثُ يبقى) بينما عدّادُ الحدث يبقى ٦٠ */
    public function test_sixty_occurrences_keep_cap_newest_rows_and_true_count(): void
    {
        $this->seedCore();

        foreach (range(1, 60) as $i) {
            // معرّفُ طلبٍ مميّز لكل وقوعٍ — به نثبت أيَّ العيّنات بقيت بعد القصّ
            request()->attributes->set('request_id', sprintf('rid-%03d', $i));
            ErrorLog::capture('php', 'RuntimeException: انفجارٌ متكرّر', 'cap.php', 7);
        }

        $ev = ErrorEvent::where('file', 'cap.php')->firstOrFail();
        $this->assertSame(60, (int) $ev->count, 'القصُّ لا يمسّ العدَّ الحقيقيّ');

        $rows = DB::table('error_occurrences')->where('error_event_id', $ev->id)
            ->orderBy('id')->pluck('request_id');
        $this->assertCount(50, $rows, 'بالضبط errors.occurrences_keep صفاً');
        $this->assertSame('rid-011', $rows->first(), 'الأقدمُ حُذف');
        $this->assertSame('rid-060', $rows->last(), 'الأحدثُ باقٍ');
    }

    /** العيّنة تحمل معرّفَ الطلب واسمَ المسار والنسخةَ والمستخدم — من طلبٍ حقيقيّ */
    public function test_sample_carries_request_id_route_release_and_user(): void
    {
        $this->seedCore();
        Route::middleware('web')->get('/_occ_probe', fn () => throw new \RuntimeException('occ probe failure'))->name('occ.probe');

        $res = $this->actingAs($this->owner)->get('/_occ_probe');
        $res->assertStatus(500);
        $rid = $res->headers->get('X-Request-Id');

        $ev = ErrorEvent::where('message', 'like', '%occ probe failure%')->firstOrFail();
        $occ = DB::table('error_occurrences')->where('error_event_id', $ev->id)->orderBy('id')->first();
        $this->assertNotNull($occ, 'كلُّ وقوعٍ يترك عيّنة');
        $this->assertSame($rid, $occ->request_id, 'أيُّ طلبٍ سبّبه؟ — بالمعرّف نفسِه الذي رآه المستخدم');
        $this->assertSame('occ.probe', $occ->route);
        $this->assertSame((string) config('hub.version'), $occ->release);
        $this->assertSame($this->owner->id, $occ->user_id);
        $this->assertSame('GET', $occ->method);
        $this->assertStringContainsString('_occ_probe', (string) $occ->url);
    }

    /** التقاطُ البطء يمرّر المدّةَ الحقيقية — لا «طبقةً» نصّية وحدها */
    public function test_slow_kind_stores_real_duration_ms(): void
    {
        $this->seedCore();
        $this->hubSetting('ops.slow_ms', '50');
        Route::middleware('web')->get('/_slow_probe', function () {
            usleep(120000);   // ١٢٠ مللي ثانية > عتبة الخمسين

            return 'ok';
        });

        $this->actingAs($this->owner)->get('/_slow_probe')->assertOk();

        $ev = ErrorEvent::where('kind', 'slow')->firstOrFail();
        $ms = DB::table('error_occurrences')->where('error_event_id', $ev->id)->value('duration_ms');
        $this->assertNotNull($ms, 'عيّنةُ البطء تحمل مدّةً');
        $this->assertGreaterThanOrEqual(100, (int) $ms, 'المدّةُ حقيقيةٌ لا رمزية');
    }

    /** إسقاطُ الجدول لا يكسر الالتقاط — حتى تحت حارسٍ مخبّأٍ دافئ */
    public function test_dropped_table_never_breaks_capture(): void
    {
        $this->seedCore();

        // التقاطٌ أول يُدفّئ خبيئةَ حارس الجدول (hub_has_col) بـ«موجود»
        ErrorLog::capture('php', 'قبل الإسقاط', 'drop.php', 1);
        $this->assertSame(1, DB::table('error_occurrences')->count());

        Schema::drop('error_occurrences');

        // الحارسُ الدافئ سيجرّب الإدراجَ فيفشل — والالتقاطُ لا يبالي
        ErrorLog::capture('php', 'قبل الإسقاط', 'drop.php', 1);
        $this->assertSame(2, (int) ErrorEvent::where('file', 'drop.php')->value('count'),
            'العدُّ استمرّ والجدولُ غائب');
    }

    /** العروضُ تُفرض عند الكاتب: route≤160 · url≤400 · release≤20 · request_id≤40 */
    public function test_writer_enforces_declared_column_widths(): void
    {
        $this->seedCore();

        // (١) التقاطٌ من الطرفية بمعرّفٍ فائضٍ ونسخةٍ فائضة — يُقصّان عند الكاتب
        config(['hub.version' => str_repeat('9.', 30)]);
        request()->attributes->set('request_id', str_repeat('r', 80));
        ErrorLog::capture('php', 'فائضُ عرضٍ من الطرفية', 'wide.php', 1);
        $occ = DB::table('error_occurrences')->orderByDesc('id')->first();
        $this->assertLessThanOrEqual(40, mb_strlen((string) $occ->request_id));
        $this->assertLessThanOrEqual(20, mb_strlen((string) $occ->release));

        // (٢) طلبٌ حقيقيّ برابطٍ فائضٍ واسمِ مسارٍ فائض
        Route::middleware('web')->get('/_wide_probe', fn () => throw new \RuntimeException('wide probe'))
            ->name(str_repeat('n', 200));
        $this->actingAs($this->owner)->get('/_wide_probe?q=' . str_repeat('a', 450))->assertStatus(500);
        $ev = ErrorEvent::where('message', 'like', '%wide probe%')->firstOrFail();
        $occ = DB::table('error_occurrences')->where('error_event_id', $ev->id)->orderByDesc('id')->first();
        $this->assertLessThanOrEqual(160, mb_strlen((string) $occ->route));
        $this->assertLessThanOrEqual(400, mb_strlen((string) $occ->url));
        $this->assertLessThanOrEqual(10, mb_strlen((string) $occ->method));
    }

    /** المقصُّ مقيَّدٌ بحدثه: ستون وقوعاً لحدثٍ لا تمسّ عيّناتِ جارِه صفاً واحداً */
    public function test_trim_never_touches_other_events_rows(): void
    {
        $this->seedCore();

        // حدثٌ هادئ بعيّنةٍ واحدة — الضحيةُ المحتملة لمقصٍّ غير مقيَّد
        request()->attributes->set('request_id', 'rid-quiet');
        ErrorLog::capture('php', 'خطأ هادئ', 'quiet.php', 1);
        $quiet = ErrorEvent::where('file', 'quiet.php')->firstOrFail();

        // حدثٌ صاخب يتجاوز السقف فيُشغّل المقصّ مراراً
        foreach (range(1, 60) as $i) {
            request()->attributes->set('request_id', sprintf('rid-noisy-%03d', $i));
            ErrorLog::capture('php', 'خطأ صاخب', 'noisy.php', 2);
        }

        $this->assertSame(1,
            DB::table('error_occurrences')->where('error_event_id', $quiet->id)->count(),
            'مقصُّ الحدث الصاخب حذف عيّنةَ الحدث الهادئ');
        $this->assertSame(50,
            DB::table('error_occurrences')->where('error_event_id', ErrorEvent::where('file', 'noisy.php')->value('id'))->count());
    }

    /** مقصُّ العمر في hub:automation: عيّنةٌ أقدمُ من المدّة تُحذف وبأثرِ تدقيق */
    public function test_retention_prunes_old_occurrences_with_audit_trail(): void
    {
        $this->seedCore();
        ErrorLog::capture('php', 'حدثٌ للعيّنات', 'ret.php', 1);
        $evId = ErrorEvent::where('file', 'ret.php')->value('id');

        // عيّنةٌ بائتة (أقدمُ من ٣٠ يوماً) وعيّنةٌ حديثة
        DB::table('error_occurrences')->insert([
            'error_event_id' => $evId, 'occurred_at' => now()->subDays(40)->toDateTimeString(),
            'request_id' => 'rid-old', 'release' => 'x',
        ]);
        $this->assertSame(2, DB::table('error_occurrences')->count());

        $this->artisan('hub:automation')->assertExitCode(0);

        $left = DB::table('error_occurrences')->orderBy('id')->pluck('request_id');
        $this->assertNotContains('rid-old', $left, 'البائتةُ ذهبت');
        $this->assertSame(1, $left->count(), 'الحديثةُ باقية');
        // §13: لا تقليمَ صامتاً — أثرُ تدقيقٍ بعدد المحذوف
        $this->assertDatabaseHas('audits', ['action' => 'تقليم احتفاظ', 'name' => 'error_occurrences: 1 صف']);
    }
}
