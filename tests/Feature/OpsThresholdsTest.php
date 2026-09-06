<?php

namespace Tests\Feature;

use App\Models\OutboxMessage;
use App\Support\Health;
use App\Support\SecurityPosture;
use App\Support\SysMonitor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WP-2.1 — عتباتُ التشغيل قابلةٌ للضبط من شاشة الإعدادات:
 * افتراضيّاتُها **تساوي ثوابتَ اليوم حرفياً** فلا يتغيّر سلوكٌ بلا ضبط، والقراءةُ
 * داخل rescue فلا يعتمد فحصُ الصحّة على صحّة القاعدة التي يفحصها، وفحصُ حداثة
 * النسخة الاحتياطية في مركز الأمان يتوحّد على نوافذ Health::JOBS نفسِها بدل
 * عتبتين مختلفتين (30/72 هناك و26/50 هنا) تقولان قولين في الشيء الواحد.
 */
class OpsThresholdsTest extends TestCase
{
    /** ضبطُ ops.disk_warn=50 يجعل قرصاً مستخدماً ٦٠٪ «متدهوراً» — والافتراضي لا يشتكي منه */
    public function test_disk_warn_setting_turns_sixty_percent_degraded(): void
    {
        $this->seedCore();

        $this->assertSame(Health::HEALTHY, Health::diskStatus(60), 'الافتراضي ٨٥٪ لا يشتكي من ٦٠٪');

        $this->hubSetting('ops.disk_warn', '50');
        $this->assertSame(Health::DEGRADED, Health::diskStatus(60), 'عتبة التحذير المضبوطة ٥٠٪ تجعل ٦٠٪ متدهوراً');

        $this->hubSetting('ops.disk_crit', '55');
        $this->assertSame(Health::UNAVAILABLE, Health::diskStatus(60), 'وتجاوزُ العتبة الحرجة المضبوطة = متعطّل');
    }

    /** الافتراضياتُ تُطابق ثوابتَ اليوم حرفياً: 85/97 للقرص، ونوافذُ JOBS كما هي بمعامل ١ */
    public function test_defaults_reproduce_todays_constants(): void
    {
        $this->seedCore();

        $this->assertSame(Health::HEALTHY, Health::diskStatus(84));
        $this->assertSame(Health::DEGRADED, Health::diskStatus(85));
        $this->assertSame(Health::DEGRADED, Health::diskStatus(96));
        $this->assertSame(Health::UNAVAILABLE, Health::diskStatus(97));
        $this->assertSame(Health::HEALTHY, Health::diskStatus(null), 'بلا قياسٍ لا اشتكاء');

        // نوافذ «متأخرة/متعطّلة» بالدقائق — كما في Health::JOBS بلا أي تمدّد
        $this->assertSame([26 * 60, 50 * 60], Health::jobWindows('backup'));
        $this->assertSame([15, 60], Health::jobWindows('outbox'));
    }

    /** عتبتا عمر طابور الصادر (٢٠/٦٠ دقيقة) تُقرآن من الإعدادات */
    public function test_outbox_queue_age_thresholds_are_configurable(): void
    {
        $this->seedCore();
        OutboxMessage::create(['kind' => 'x', 'channel' => 'tg', 'text' => 't', 'state' => 'queued',
            'created_at' => now()->subMinutes(30)]);

        $this->assertSame(Health::DEGRADED, Health::check()['components']['outbox']['status'],
            'افتراضياً: رسالةٌ أقدم من ٢٠ دقيقة = الطابور يتأخّر');

        $this->hubSetting('ops.queue_age_warn', '45');
        $this->hubSetting('ops.queue_age_crit', '90');
        $this->assertSame(Health::HEALTHY, Health::check()['components']['outbox']['status'],
            'توسيعُ النافذة يجعل الثلاثين دقيقةً عاديةً');

        $this->hubSetting('ops.queue_age_warn', '5');
        $this->hubSetting('ops.queue_age_crit', '25');
        $this->assertSame(Health::UNAVAILABLE, Health::check()['components']['outbox']['status'],
            'وتضييقُ الحرجة يجعلها انقطاعاً');
    }

    /** معاملُ تأخّر المجدولات يمدّد نوافذَ JOBS كلَّها دفعةً واحدة */
    public function test_scheduler_late_factor_stretches_jobs_windows(): void
    {
        $this->seedCore();
        $this->hubSetting('heartbeat.automation', now()->subHours(30)->toIso8601String());

        $this->assertSame(Health::DEGRADED, Health::scheduler()['data']['jobs']['automation']['status'],
            'افتراضياً: ٣٠ ساعةً تتجاوز نافذة التأخر (٢٦س)');

        $this->hubSetting('ops.scheduler_late_factor', '2');
        $this->assertSame(Health::HEALTHY, Health::scheduler()['data']['jobs']['automation']['status'],
            'بمعامل ٢ تتسع النافذة إلى ٥٢ ساعةً فلا تأخّر');
        $this->assertSame([2 * 26 * 60, 2 * 50 * 60], Health::jobWindows('automation'));
    }

    /** فحصُ حداثة النسخة في مركز الأمان يتوحّد على نوافذ Health::JOBS['backup'] (26/50 ساعة) */
    public function test_backup_freshness_unifies_on_health_jobs_windows(): void
    {
        $this->seedCore();
        $find = fn () => collect(SecurityPosture::checks())->firstWhere('key', 'backup_fresh');

        $this->hubSetting('heartbeat.backup', now()->subHours(20)->toIso8601String());
        $this->assertSame('ok', $find()['tone'], '٢٠ ساعةً داخل النافذة اليومية');

        $this->hubSetting('heartbeat.backup', now()->subHours(30)->toIso8601String());
        $this->assertSame('wn', $find()['tone'], '٣٠ ساعةً تتجاوز نافذة التأخر الموحّدة (٢٦س) — كانت عتبةُ المركز القديمة ٣٠س تسكت عنها');

        $this->hubSetting('heartbeat.backup', now()->subHours(51)->toIso8601String());
        $this->assertSame('bad', $find()['tone'], '٥١ ساعةً تتجاوز نافذة التعطل الموحّدة (٥٠س) — لا انتظار ٧٢ ساعةً القديمة');
    }

    /** عتبتا المعالج والذاكرة تُقرآن من الإعدادات — بمقارنةٍ ذاتيةٍ على القياس الحي نفسه */
    public function test_cpu_and_memory_thresholds_come_from_settings(): void
    {
        $this->seedCore();

        $this->hubSetting('ops.cpu_warn', '1');
        $this->hubSetting('ops.cpu_crit', '1');
        $c = SysMonitor::cpu();
        if ($c['ok']) {
            $this->assertSame($c['pct'] >= 1 ? 'bad' : 'ok', $c['tone'], 'عتبة المعالج الحرجة المضبوطة (١٪) تسري');
        }

        $m0 = SysMonitor::memory();
        if (! $m0['ok'] || $m0['pct'] < 1) {
            $this->markTestSkipped('لا قياس ذاكرة نظامٍ على هذه البيئة');
        }
        $this->hubSetting('ops.mem_warn', '1');
        $this->hubSetting('ops.mem_crit', (string) ($m0['pct'] + 50));
        $this->assertSame('wn', SysMonitor::memory()['tone'], 'فوق عتبة التحذير المضبوطة ودون الحرجة = تحذير');

        $this->hubSetting('ops.mem_warn', (string) ($m0['pct'] + 20));
        $this->hubSetting('ops.mem_crit', (string) ($m0['pct'] + 30));
        $this->assertSame('ok', SysMonitor::memory()['tone'], 'دون عتبة التحذير المضبوطة = مرتاح');
    }

    /** ‎/healthz يُجيب ولو تعذّرت قراءةُ جدول الإعدادات — العتبات تسقط لافتراضيّاتها لا للخطأ */
    public function test_healthz_responds_when_settings_are_unreadable(): void
    {
        $this->seedCore();
        Schema::drop('settings');
        Cache::flush();

        $res = $this->getJson('/healthz')->assertOk();
        $this->assertContains($res->json('status'), ['ok', 'degraded']);
        $this->assertNotEmpty($res->json('health'));

        // ونموذجُ الصحّة الكامل لا يرمي — كلُّ قراءة عتبةٍ داخل rescue
        $full = Health::check();
        $this->assertArrayHasKey('storage', $full['components']);
    }

    /** كلُّ مفتاحٍ جديد له مدخلٌ في شاشة الإعدادات، والحفظُ يسري فوراً */
    public function test_new_threshold_keys_are_visible_and_saveable(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/admin/settings')->assertOk()->getContent();
        foreach (['ops_cpu_warn', 'ops_cpu_crit', 'ops_mem_warn', 'ops_mem_crit', 'ops_disk_warn',
                  'ops_disk_crit', 'ops_db_ms_warn', 'ops_queue_age_warn', 'ops_queue_age_crit',
                  'ops_scheduler_late_factor'] as $input) {
            $this->assertStringContainsString('name="' . $input . '"', $html, "المفتاح $input بلا مدخل في الشاشة");
        }

        $this->actingAs($this->owner)->post('/admin/settings', ['ops_disk_warn' => '70'])->assertRedirect();
        $this->assertSame('70', (string) setting('ops.disk_warn'));
    }

    /** النسخةُ المضمّنة «85» في عرض القرص ذهبت — العرضُ يقرأ العتبةَ نفسَها من الإعدادات */
    public function test_ops_disk_view_reads_its_threshold_from_settings(): void
    {
        $src = (string) file_get_contents(resource_path('views/ops/parts/system.blade.php'));
        $this->assertStringContainsString("setting('ops.disk_warn'", $src, 'عتبة القرص في العرض تُقرأ من الإعدادات');
        $this->assertStringNotContainsString('> 85', $src, 'الثابت المضمّن ٨٥ لم يعد في العرض');
    }

    /** شاشةُ التشغيل قُسّمت أقساماً — كلُّ حزمة عملٍ لاحقة تحرّر ملفَّ قسمِها وحده */
    public function test_ops_screen_is_split_into_parts_and_renders(): void
    {
        $idx = (string) file_get_contents(resource_path('views/ops/index.blade.php'));
        foreach (['header', 'health', 'system', 'pulse', 'consumers', 'outbox', 'scheduler', 'backups',
                  'errors', 'db', 'cache', 'starters', 'integrity', 'env', 'logtail', 'demo'] as $part) {
            $this->assertStringContainsString("@include('ops.parts." . $part . "')", $idx, "قسم $part غير مضمَّن");
            $this->assertFileExists(resource_path('views/ops/parts/' . $part . '.blade.php'));
        }

        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringContainsString('صحّة المنصة', $html);
        $this->assertStringContainsString('من يستهلك القرص', $html);
        $this->assertStringContainsString('🎭 الوضع التجريبي', $html);
    }
}
