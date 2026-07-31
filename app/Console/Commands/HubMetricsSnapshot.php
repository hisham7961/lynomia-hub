<?php

namespace App\Console\Commands;

use App\Support\Metrics;
use Illuminate\Console\Command;

/**
 * لقطةٌ يومية للحقول المقيسة — تُبنى بها السلسلة الزمنية حتى بلا مصدرٍ خارجي.
 * الحقل وحده يحفظ آخر قيمة ويدهس ما قبلها؛ هذه اللقطة تحوّل «الرقم الحالي»
 * إلى تاريخٍ يُقرأ منه النمو والاتجاه.
 */
class HubMetricsSnapshot extends Command
{
    protected $signature = 'hub:metrics-snapshot {--source=sync : وسم المصدر المسجَّل مع النقاط}';
    protected $description = 'التقاط القيم الحالية للحقول المقيسة كنقاطٍ في السلسلة الزمنية';

    public function handle(): int
    {
        $out = Metrics::snapshotAll((string) $this->option('source'));

        if (! $out) {
            $this->info('لا حقول مقيسة فيها قيم بعد — لا نقاط.');

            return self::SUCCESS;
        }

        foreach ($out as $module => $n) {
            $this->info(sprintf('%s: %d نقطة', hub_mod($module)['label'] ?? $module, $n));
        }
        $this->info('المجموع: ' . array_sum($out) . ' نقطة عند ' . now()->startOfDay()->toDateString());

        return self::SUCCESS;
    }
}
