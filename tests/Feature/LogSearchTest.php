<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **بحثُ السجلّ المحدود (WP-3.5) — الملفُّ الصحيح، بسقفٍ لا يُغرِق الذاكرة.**
 *
 * السائق `daily` يكتب `laravel-YYYY-MM-DD.log`، وبطاقةُ التشغيل القديمة كانت
 * تقرأ `laravel.log` الذي **لا يوجد أبداً** — شريطُ سجلٍّ ميّتٌ منذ ولادته
 * (critic #18). صفحةُ `errors.logs` تقرأ الملفَّ المؤرَّخ الصحيح، بذيلٍ مسقوفٍ
 * بالبايتات (`ops.log_tail_kb`) فملفُّ ٢٠م.ب لا يُحمَّل كاملاً، وكلُّ سطرٍ يمرّ
 * بالمُطهِّر الواحد، والبابُ للمالك وحده، والفراغُ يقول سببه بصدق.
 */
class LogSearchTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/logsearch-' . getmypid());
        @mkdir($this->dir, 0777, true);
        config()->set('logging.default', 'stack');
        config()->set('logging.channels.stack.channels', ['daily']);
        config()->set('logging.channels.daily.path', $this->dir . '/laravel.log');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** سطرُ سجلٍّ بصيغة لارافيل الحرفية */
    private function line(string $level, string $msg, int $minutesAgo = 5): string
    {
        return '[' . now()->subMinutes($minutesAgo)->format('Y-m-d H:i:s') . '] testing.' . $level . ': ' . $msg . "\n";
    }

    private function dated(): string
    {
        return $this->dir . '/laravel-' . now()->toDateString() . '.log';
    }

    /* ────────── الملفُّ الصحيح ────────── */

    /** يقرأ الملفَّ المؤرَّخ الذي يكتبه السائق daily — لا laravel.log الميّت */
    public function test_reads_the_dated_file_not_the_legacy_name(): void
    {
        $this->seedCore();
        file_put_contents($this->dated(), $this->line('ERROR', 'DATED_FILE_MARKER_A7'));
        file_put_contents($this->dir . '/laravel.log', $this->line('ERROR', 'LEGACY_FILE_MARKER_Z9'));

        $html = $this->actingAs($this->owner)->get('/admin/errors/logs')->assertOk()->getContent();

        $this->assertStringContainsString('DATED_FILE_MARKER_A7', $html,
            'الملفُّ المؤرَّخ laravel-YYYY-MM-DD.log لم يُقرأ — الصفحةُ تنظر لملفٍّ لا يوجد');
        $this->assertStringNotContainsString('LEGACY_FILE_MARKER_Z9', $html,
            'laravel.log غيرُ المؤرَّخ قُرئ — السائق daily لا يكتبه أصلاً');
    }

    /* ────────── سقفُ البايتات ────────── */

    /** ملفٌّ ضخم يُقرأ ذيلُه المسقوف فقط — أولُ الملف لا يصل الذاكرةَ ولا الشاشة */
    public function test_never_loads_a_huge_file_fully(): void
    {
        $this->seedCore();
        $fh = fopen($this->dated(), 'w');
        fwrite($fh, $this->line('ERROR', 'HEAD_MARKER_EARLY', 30));
        $pad = str_repeat('x', 950);
        for ($i = 0; $i < 3000; $i++) {              // ≈ ٣م.ب من أسطرٍ صالحة الصيغة
            fwrite($fh, $this->line('ERROR', "حشو {$i} {$pad}", 20));
        }
        fwrite($fh, $this->line('ERROR', 'TAIL_MARKER_LATE', 1));
        fclose($fh);

        $html = $this->actingAs($this->owner)->get('/admin/errors/logs')->assertOk()->getContent();

        $this->assertStringContainsString('TAIL_MARKER_LATE', $html, 'ذيلُ الملف — الأحدثُ — غائب');
        $this->assertStringNotContainsString('HEAD_MARKER_EARLY', $html,
            'أولُ ملفٍّ بحجم أميال ظهر في الصفحة — الملفُّ حُمّل كاملاً لا ذيلُه المسقوف');
    }

    /* ────────── المرشِّحات والمدى ────────── */

    /** المستوى والمدى ومعرّفُ الطلب — كلٌّ يقصّ ما لا يخصّه */
    public function test_level_range_and_request_id_filters_apply(): void
    {
        $this->seedCore();
        file_put_contents($this->dated(),
            $this->line('CRITICAL', 'OLD_CRIT_LINE', 180)
            . $this->line('ERROR', 'FRESH_ERR_LINE rid=req-abc123', 10)
            . $this->line('CRITICAL', 'FRESH_CRIT_LINE', 5));

        $html = $this->actingAs($this->owner)
            ->get('/admin/errors/logs?level=CRITICAL&range=1h')->assertOk()->getContent();
        $this->assertStringContainsString('FRESH_CRIT_LINE', $html);
        $this->assertStringNotContainsString('OLD_CRIT_LINE', $html, 'سطرٌ خارج المدى الزمنيّ ظهر');
        $this->assertStringNotContainsString('FRESH_ERR_LINE', $html, 'مستوى ERROR ظهر تحت مرشِّح CRITICAL');

        $html = $this->actingAs($this->owner)
            ->get('/admin/errors/logs?rid=req-abc123')->assertOk()->getContent();
        $this->assertStringContainsString('FRESH_ERR_LINE', $html);
        $this->assertStringNotContainsString('FRESH_CRIT_LINE', $html, 'مرشِّحُ معرّف الطلب لا يقصّ');
    }

    /* ────────── الطمس ────────── */

    /** كلُّ سطرٍ يمرّ بالمُطهِّر الواحد — سرٌّ في السجلّ لا يعبر إلى الشاشة */
    public function test_output_is_redacted(): void
    {
        $this->seedCore();
        file_put_contents($this->dated(),
            $this->line('ERROR', 'فشل الاتصال password=SuperSecret123 برأس Bearer abcdefghijklmnop'));

        $html = $this->actingAs($this->owner)->get('/admin/errors/logs')->assertOk()->getContent();

        $this->assertStringNotContainsString('SuperSecret123', $html, 'كلمةُ مرورٍ من السجلّ وصلت الشاشة');
        $this->assertStringNotContainsString('abcdefghijklmnop', $html, 'رمزُ Bearer وصل الشاشةَ بنصّه');
        $this->assertStringContainsString('password=***', $html, 'أثرُ الطمس غائب — أين مرّ السطر؟');
    }

    /* ────────── الباب والفراغ ────────── */

    /** البابُ للمالك وحده — ٤٠٣ لغيره */
    public function test_forbidden_for_non_owner(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/admin/errors/logs')->assertForbidden();
        $this->actingAs($this->viewer)->get('/admin/errors/logs')->assertForbidden();
    }

    /** لا ملفَّ سجلٍّ ضمن المدى — فراغٌ يقول سببه لا جدولٌ فارغ صامت */
    public function test_honest_empty_state_when_no_file(): void
    {
        $this->seedCore();

        $html = $this->actingAs($this->owner)->get('/admin/errors/logs')->assertOk()->getContent();

        $this->assertStringContainsString('لا ملفات سجلّ مؤرَّخة ضمن هذا المدى', $html,
            'غيابُ الملفات بلا تفسير — المستخدم لا يفرّق بين «لا أخطاء» و«لا ملف»');
    }
}
