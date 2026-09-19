<?php

namespace Tests\Feature\UltimateReview;

use App\Support\Health;
use App\Support\SysMonitor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **عتباتُ التشغيل: أتُحرّك الحكمَ أم تُزيّن الشاشة؟** (المراجعةُ الشاملة ·
 * الطبقة ٣ · L3-05).
 *
 * تسعُ عتباتٍ رقميّةٍ يضبطها المشغّلُ ليقول للنظام **متى يقلق**. وهي أخطرُ
 * أنواعِ الإعداد صمتاً: العتبةُ الخاطئةُ لا تُنتج رسالةَ خطأ، بل **سكوتاً** —
 * والسكوتُ يُقرأ «كلُّ شيءٍ بخير». وهو الدرسُ المحفوظُ في `CLAUDE.md` عن
 * `notifications_hub.kind`: قواعدُ التنبيه لا تُشعِر أحداً، والحزمةُ خضراء.
 *
 * وكلُّ عتبةٍ تُقاس **بثلاثِ نطاقاتٍ لا باثنتين** حيث كان للنطاقِ ثلاثةُ وجوه
 * (مرتاح · متوسط · مرتفع)، فلا يكفي أن تتحرّك بل أن تبلغ **الوجهَ الصحيح**.
 *
 * **وما استُثني عمداً، بسببٍ مكتوب:** `ops.db_ms_warn` و`ops.slow_ms` يُقارنان
 * **زمناً مقيساً حقيقيّاً** (`select 1`، ومدّةُ الطلب). وأرضيّتاهما في الكود
 * (`max(1, …)` و`max(50, …)`) تجعل الوجهَ «البطيء» رهينَ حِملِ آلةِ الاختبار
 * لحظتَها — أي **قرعةً**، وهي بالضبط ما يحذّر منه `CLAUDE.md`. فتُتركان
 * موصوفتَين لا مُدّعىً إثباتُهما.
 */
class OpsThresholdsActuallyMoveTheVerdictTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    // ── ① عتبتا القرص: دالّةٌ خالصةٌ تأخذ النسبةَ وتردُّ الحكم ──────────────

    public function test_disk_thresholds_move_all_three_bands(): void
    {
        $this->seedCore();

        $this->hubSetting('ops.disk_warn', '85');
        $this->hubSetting('ops.disk_crit', '97');
        $this->assertSame(Health::HEALTHY, Health::diskStatus(50), 'قرصٌ نصفُه مشغولٌ أُعلن غيرَ سليم');
        $this->assertSame(Health::DEGRADED, Health::diskStatus(90), 'تجاوزُ عتبةِ التحذير لم يُنتج تدهوراً');
        $this->assertSame(Health::UNAVAILABLE, Health::diskStatus(98), 'تجاوزُ العتبةِ الحرِجة لم يُنتج تعطّلاً');

        // والآن تُشدّ العتبتان — والنسبةُ نفسُها تُقرأ حكماً آخر
        $this->hubSetting('ops.disk_warn', '40');
        $this->hubSetting('ops.disk_crit', '45');
        $this->assertSame(Health::UNAVAILABLE, Health::diskStatus(50),
            'شُدَّت العتبتان و٥٠٪ ما زالت «سليمة» — العتبةُ لا تصل الحكم');

        // وتُرخى فيعود الحكمُ سليماً — وجهان لا وجهٌ واحد
        $this->hubSetting('ops.disk_warn', '99');
        $this->hubSetting('ops.disk_crit', '100');
        $this->assertSame(Health::HEALTHY, Health::diskStatus(98),
            'أُرخيت العتبتان و٩٨٪ ما زالت «متعطّلة» — العتبةُ تُشدّ ولا تُرخى');
    }

    /** والحرِجةُ لا تنزل تحت التحذيريّة — `max($warn, …)` يمنع ترتيباً مقلوباً */
    public function test_a_critical_threshold_below_the_warning_one_is_clamped(): void
    {
        $this->seedCore();
        $this->hubSetting('ops.disk_warn', '80');
        $this->hubSetting('ops.disk_crit', '10');           // مقلوبةٌ عمداً

        $this->assertSame(Health::HEALTHY, Health::diskStatus(50),
            '٥٠٪ تحت التحذيريّة (٨٠) ومع ذلك أُعلنت متعطّلةً بعتبةٍ حرِجةٍ مقلوبة');
        $this->assertSame(Health::UNAVAILABLE, Health::diskStatus(85),
            'الحرِجةُ المقلوبةُ لم تُثبَّت عند التحذيريّة');
    }

    // ── ② عتبتا عمرِ الطابور: رسالةٌ عالقةٌ في الصادر ──────────────────────

    /** يزرع رسالةً في الطابور عمرُها `$min` دقيقة */
    private function stuck(int $min): void
    {
        DB::table('outbox')->insert(['id' => (string) Str::uuid(), 'kind' => 'test', 'channel' => 'app',
            'text' => 'رسالةٌ عالقة', 'state' => 'queued', 'created_at' => now()->subMinutes($min)]);
    }

    private function outboxStatus(): string
    {
        $m = new \ReflectionMethod(Health::class, 'outbox');
        $m->setAccessible(true);

        return (string) ($m->invoke(null)['status'] ?? '');
    }

    public function test_queue_age_thresholds_move_all_three_bands(): void
    {
        $this->seedCore();
        $this->stuck(30);                                    // عالقةٌ منذ نصفِ ساعة

        $this->hubSetting('ops.queue_age_warn', '20');
        $this->hubSetting('ops.queue_age_crit', '60');
        $this->assertSame(Health::DEGRADED, $this->outboxStatus(),
            'رسالةٌ عالقةٌ ٣٠ دقيقةً فوق عتبةِ التحذير (٢٠) ولم يتدهور الحكم');

        $this->hubSetting('ops.queue_age_crit', '25');       // صارت فوق الحرِجة
        $this->assertSame(Health::UNAVAILABLE, $this->outboxStatus(),
            'العتبةُ الحرِجةُ شُدَّت تحت عمرِ الرسالة ولم يُعلَن التعطّل');

        $this->hubSetting('ops.queue_age_warn', '120');      // أُرخيت فوق العمر
        $this->hubSetting('ops.queue_age_crit', '240');
        $this->assertSame(Health::HEALTHY, $this->outboxStatus(),
            'أُرخيت العتبتان فوق عمرِ الرسالة والحكمُ ما زال قلِقاً — العتبةُ لا تُرخى');
    }

    // ── ③ معامِلُ تأخّرِ المجدولات: يُمدّد النافذتَين معاً ─────────────────

    public function test_the_scheduler_late_factor_stretches_both_windows(): void
    {
        $this->seedCore();

        $this->hubSetting('ops.scheduler_late_factor', '1');
        [$late1, $dead1] = Health::jobWindows('outbox');
        $this->hubSetting('ops.scheduler_late_factor', '3');
        [$late3, $dead3] = Health::jobWindows('outbox');

        $this->assertSame($late1 * 3, $late3, 'معامِلُ التأخّرِ لم يُمدّد نافذةَ «متأخرة»');
        $this->assertSame($dead1 * 3, $dead3, 'معامِلُ التأخّرِ لم يُمدّد نافذةَ «متعطّلة»');
        $this->assertGreaterThan(0, $late1, 'النافذةُ الأساسُ صفرٌ — فالضربُ فيها لا يُثبت شيئاً');
    }

    /** وأرضيّةُ المعامِل تمنع صفراً يُلغي النافذةَ كلَّها */
    public function test_a_zero_factor_cannot_erase_the_window(): void
    {
        $this->seedCore();
        $this->hubSetting('ops.scheduler_late_factor', '0');
        [$late, $dead] = Health::jobWindows('automation');

        $this->assertGreaterThan(0, $late, 'معامِلٌ صفرٌ ألغى نافذةَ التأخّر — فلا مجدولةَ تُعلَن متأخّرةً أبداً');
        $this->assertGreaterThanOrEqual($late, $dead, 'نافذةُ التعطّلِ نزلت تحت نافذةِ التأخّر');
    }

    // ── ④ عتبتا المعالجِ والذاكرة: تُقاسان **نسبةً إلى القياسِ الحيّ** ──────

    /**
     * حِملُ الآلةِ لحظةَ الاختبار غيرُ معلومٍ سلفاً، فالعتباتُ تُختار **نسبةً
     * إليه** لا بأرقامٍ ثابتة — فيبقى الحكمُ قاطعاً مهما كان الحِمل، ولا تُكتب
     * قرعةٌ تخضرّ هنا وتسقط على CI.
     *
     * **وهامشٌ واسعٌ حول القياس** (‏-٢٠ / +٥٠ نقطة): القياسُ يُعاد بين تأكيدٍ
     * وآخر، والحِملُ يتحرّك بينهما. فالهامشُ يستوعب الانحرافَ الطبيعيَّ ويُبقي
     * الحكمَ قاطعاً — عتبةٌ ملاصقةٌ للقياسِ كانت ستجعل النتيجةَ قرعةً، وهي
     * الصنفُ الذي يحذّر منه `CLAUDE.md` نصّاً.
     */
    public function test_cpu_thresholds_are_measured_against_the_live_reading(): void
    {
        $this->seedCore();
        $cpu = SysMonitor::cpu();
        if (! ($cpu['ok'] ?? false)) $this->markTestSkipped('قياسُ المعالجِ غيرُ متاحٍ على هذه الآلة');
        $pct = (int) $cpu['pct'];

        $this->hubSetting('ops.cpu_warn', (string) ($pct + 50));
        $this->hubSetting('ops.cpu_crit', (string) ($pct + 60));
        $this->assertSame('مرتاح', SysMonitor::cpu()['band'], 'عتبةٌ فوق القياسِ ومع ذلك أُعلن الحِملُ مرتفعاً');

        $this->hubSetting('ops.cpu_warn', (string) max(1, $pct - 20));
        $this->hubSetting('ops.cpu_crit', (string) ($pct + 50));
        $this->assertSame('متوسط', SysMonitor::cpu()['band'], 'بلوغُ عتبةِ التحذير لم يُنتج «متوسط»');

        $this->hubSetting('ops.cpu_crit', (string) max(1, $pct - 20));
        $this->assertSame('مرتفع', SysMonitor::cpu()['band'], 'بلوغُ العتبةِ الحرِجة لم يُنتج «مرتفع»');
    }

    public function test_memory_thresholds_are_measured_against_the_live_reading(): void
    {
        $this->seedCore();
        $mem = SysMonitor::memory();
        if (! ($mem['ok'] ?? false)) $this->markTestSkipped('قياسُ الذاكرةِ غيرُ متاحٍ على هذه الآلة');
        $pct = (int) $mem['pct'];

        $this->hubSetting('ops.mem_warn', (string) ($pct + 50));
        $this->hubSetting('ops.mem_crit', (string) ($pct + 60));
        $this->assertSame('ok', SysMonitor::memory()['tone'], 'عتبةٌ فوق القياسِ ومع ذلك أُنذر عن الذاكرة');

        $this->hubSetting('ops.mem_warn', (string) max(1, $pct - 20));
        $this->hubSetting('ops.mem_crit', (string) ($pct + 50));
        $this->assertSame('wn', SysMonitor::memory()['tone'], 'بلوغُ عتبةِ التحذير لم يُنتج تحذيراً');

        $this->hubSetting('ops.mem_crit', (string) max(1, $pct - 20));
        $this->assertSame('bad', SysMonitor::memory()['tone'], 'بلوغُ العتبةِ الحرِجة لم يُنتج إنذاراً');
    }
}
