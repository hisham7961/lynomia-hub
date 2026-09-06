<?php

namespace App\Console\Commands;

use App\Support\DataQuality;
use App\Support\ExecutionStats;
use Illuminate\Console\Command;

/**
 * لقطة جودة البيانات اليومية — **ولقطةُ التنفيذ معها** (WP-8.4 · §6.12).
 *
 * درجةُ اليوم وحدها لا تقول شيئاً: لا يُعرف منها إن كنا نُنظّف أم نُراكم.
 * وبلا سلسلةٍ زمنية لا وجود لـ«إنجاز» أصلاً — الإنجاز فرقٌ بين نقطتين.
 *
 * والتنفيذُ مثلُها: «متأخّرٌ ١٢» بلا أمسٍ لا يقول أنتقدّم أم نتراجع. ولأنّ
 * اللقطتين يوميّتان ومتلازمتان في المعنى، تُكتبان في الأمر الواحد — لا أمرَ
 * مجدولٌ ثانٍ يُنسى تسجيلُه في `Health::JOBS` فلا يراه `/healthz`.
 */
class HubQualitySnapshot extends Command
{
    protected $signature = 'hub:quality-snapshot {--date= : تاريخ اللقطة (افتراضيه اليوم)}';
    protected $description = 'تسجيل درجة جودة البيانات ونواقصها ولقطة التنفيذ في السلسلة الزمنية';

    public function handle(): int
    {
        $t0 = microtime(true);   // (WP-2.3) مدّةُ اللقطة الحقيقية تُنبَض — لا نبضةَ بلا مدّة
        DataQuality::snapshot($this->option('date'));
        $t = DataQuality::scan()['totals'];

        // لقطةُ التنفيذ (§6.12): إنجازٌ والتزامٌ ومتأخّرٌ ومشكلاتٌ مفتوحة — بالقارئ
        // الواحد `ExecutionStats` لا بحسابٍ ثانٍ يُكتب هنا، ويُطبَع ما كُتب حرفياً
        $x = ExecutionStats::snapshot($this->option('date'));

        // ── WP-8.5 (§6.9): لقطةُ المؤشّرات — نقطةٌ يومية `('kpis', <id>, 'value')`
        // لكل مؤشّرٍ نشط. بلا سلسلةٍ لا اتّجاهَ لمؤشّر، و«٤٥٪» وحدها لا تقول
        // أصاعدةٌ هي أم هابطة. تُكتب هنا لا في أمرٍ مجدولٍ ثانٍ يُنسى تسجيلُه
        // في `Health::JOBS` فلا يراه `/healthz`.
        $kpis = \App\Support\KpiCentre::snapshot($this->option('date'));

        $this->info("درجة الجودة {$t['score']}٪ · {$t['defects']} نقصاً في {$t['checks']} فحصاً · "
            . "{$t['clean']} وحدة نظيفة من {$t['modules']}");
        $this->info("المؤشّرات: {$kpis} نقطة قياس");
        if ($x) {
            $this->info('التنفيذ: الإنجاز ' . ($x['planned']['pct'] === null ? '—' : $x['planned']['pct'] . '٪')
                . ' · الالتزام ' . ($x['on_time']['pct'] === null ? '—' : $x['on_time']['pct'] . '٪')
                . " · {$x['overdue']} متأخّرة · {$x['blocked']} متوقّفة");
        }

        \App\Support\Health::beat('quality', (int) round((microtime(true) - $t0) * 1000));
        return self::SUCCESS;
    }
}
