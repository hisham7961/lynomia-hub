<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\SecurityPosture;
use Tests\TestCase;

/**
 * **مجلسُ الخبراء · ت-٣ — «نسخةٌ حديثة» و«صفرُ ملفّات» في آنٍ واحد.**
 *
 * أبلغ خبيرُ السيناريوهاتِ أنّ `/admin/security` تقول «✅ حداثةُ النسخة
 * الاحتياطية» بينما `/admin/ops` تقول `storage/app/backups · 0 ملفاً · 0 B`.
 * وتحقّقتُ من الطرفين فوجدتُ **قارئَين لسؤالٍ واحد**:
 *
 *   `SecurityPosture::backupFresh()` → يسأل `heartbeat.backup` (**ختمَ زمن**)
 *   `OpsController::backupsPanel()`  → يعدّ **الملفّاتِ** على القرص
 *
 * **والمهمّةُ عملت فعلاً** (ختمٌ ناجحٌ عند 08:04) — ولهذا العلامةُ الخضراءُ
 * **مكتسَبةٌ بنبضةٍ ومكذوبةٌ بالقرص**. ولو لم تعمل قطّ لكانت النبرةُ تحذيراً
 * **ولصدقت الشاشة**.
 *
 * وأيُّ سببٍ لفقدِ الأثر — تدويرٌ معطوب، تنظيفُ قرص، تغيّرُ مسارٍ بعد نشر، حذفٌ
 * يدويّ، ضياعُ `APP_KEY` لنسخةٍ مشفّرة — يترك الشاشةَ تقول «سليم».
 *
 * **والمنتجُ يعرف هذا الصنفَ ويحرسه — في الكاتبِ فقط.** تعليقُ v2.312 في
 * `HubBackup` يصفه بالحرف: «فتُعطَّل قدرةُ التعافي كلها بصمت، **والمشغّل يقرأ
 * «✓» ويطمئن**». فحُرس الكاتبُ ولم يُحرس القارئ.
 *
 * **ولا قدرةَ تُنزع:** صفُّ الفحصِ باقٍ، ومركزُ التشغيلِ باقٍ بعدّادِه —
 * **يضيق تعريفُ «سليم» ليصدق فقط**.
 */
class CouncilBackupTruthTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/backups/hub-*.json*')) ?: [] as $f) @unlink($f);
        parent::tearDown();
    }

    /** صفُّ «حداثةُ النسخة» من فحوصِ الوضعيّة */
    protected function row(): array
    {
        foreach (SecurityPosture::checks() as $c) {
            if (($c['key'] ?? '') === 'backup_fresh') return $c;
        }
        $this->fail('صفُّ «حداثةُ النسخة» اختفى من فحوصِ الوضعيّة — قدرةٌ نُزعت');
    }

    /** يضع نبضةً ناجحةً حديثةً كما يفعل `HubBackup` تماماً */
    protected function freshHeartbeat(): void
    {
        // بالصيغةِ التي يكتبها `HubBackup` نفسُه — والنموذجُ يتكفّل بالترميز
        Setting::updateOrCreate(['key' => 'heartbeat.backup'],
            ['value' => now()->toIso8601String()]);
        Setting::updateOrCreate(['key' => 'heartbeat.backup.meta'],
            ['value' => ['ms' => 127, 'result' => 'ok', 'note' => 'hub-test']]);
        \Illuminate\Support\Facades\Cache::flush();
    }

    public function test_a_fresh_heartbeat_with_no_artifact_is_not_reported_healthy(): void
    {
        $this->seedCore();
        $this->freshHeartbeat();
        foreach (glob(storage_path('app/backups/hub-*.json*')) ?: [] as $f) @unlink($f);

        $row = $this->row();

        $this->assertNotSame('ok', (string) ($row['tone'] ?? ''),
            'نبضةٌ ناجحةٌ بلا أثرٍ قابلٍ للاستعادةِ تُقرأ «سليم» — '
            . 'فمركزُ الأمانِ يطمئن على ما لا رجعةَ فيه.');
        $this->assertGreaterThan(0, (int) ($row['n'] ?? 0),
            'ولا تُحسَب في «ما يستدعي الانتباه» — فالتحذيرُ الذي لا يُعدّ لا يُرى');
    }

    public function test_the_fix_message_names_the_real_cause(): void
    {
        $this->seedCore();
        $this->freshHeartbeat();
        foreach (glob(storage_path('app/backups/hub-*.json*')) ?: [] as $f) @unlink($f);

        $fix = (string) ($this->row()['fix'] ?? '');
        $this->assertNotSame('', $fix, 'صفٌّ غيرُ سليمٍ بلا علاجٍ مكتوب');
        // التثبيتُ على نصٍّ لاتينيٍّ مستقرّ — فالتشكيلُ العربيُّ يتغيّر بلا معنى
        $this->assertStringContainsString('storage/app/backups', $fix,
            'الرسالةُ تتحدّث عن النبضةِ بينما العطلُ في الأثر — فتُرسل المشغّلَ '
            . 'إلى البابِ الخطأ');
    }

    public function test_a_real_artifact_with_a_fresh_heartbeat_is_healthy(): void
    {
        $this->seedCore();
        $this->freshHeartbeat();
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) mkdir($dir, 0700, true);
        file_put_contents($dir . '/hub-' . now()->format('Y-m-d-Hi') . '.json', '{"ok":true}');

        $this->assertSame('ok', (string) ($this->row()['tone'] ?? ''),
            'نبضةٌ حديثةٌ وأثرٌ موجودٌ ولا تُقرأ «سليم» — الحارسُ أوسعُ ممّا يجب '
            . 'وقد حوّل شاشةً صادقةً إلى إنذارٍ دائم');
    }

    public function test_a_never_run_backup_still_warns_as_it_always_did(): void
    {
        $this->seedCore();
        Setting::where('key', 'like', 'heartbeat.backup%')->delete();
        \Illuminate\Support\Facades\Cache::flush();

        $this->assertNotSame('ok', (string) ($this->row()['tone'] ?? ''),
            'السلوكُ القديمُ انكسر: نسخةٌ لم تُؤخذ قطّ كانت تُحذّر — وهي محقّة');
    }
}
