<?php

namespace App\Console\Commands;

use App\Support\Ai\Kpi\KpiInsights;
use Illuminate\Console\Command;

/**
 * **مؤشّراتٌ ذكيّة** يدويّاً (docs/ai-hub/47 §العمود هـ) — والمجدولُ خطوةٌ في `hub:automation` حين `ai.kpi_insights` مفعَّل.
 *
 *     php artisan hub:kpi-insights --dry      # كم مؤشّراً سيُحلَّل — بلا نداء
 *     php artisan hub:kpi-insights --force    # كلُّ المؤشّرات الآن ولو لم تتغيّر
 */
class HubKpiInsights extends Command
{
    protected $signature = 'hub:kpi-insights {--dry : ما سيُحلَّل دون نداء} {--force : تجاهلُ البصمة والأسبوع}';

    protected $description = 'مؤشّراتٌ ذكيّة: تفسيرُ الانحراف وهدفٌ مقترح ومؤشّراتٌ ناقصة';

    public function handle(): int
    {
        if (! KpiInsights::enabled() && ! $this->option('force')) {
            $this->warn('تحليلُ المؤشّرات مطفأ (ai.kpi_insights) — لا شيء. (--force يعمل ولو كان مطفأً)');

            return self::SUCCESS;
        }
        $r = KpiInsights::run((bool) $this->option('dry'), (bool) $this->option('force'));
        $this->info("حُلِّل {$r['analysed']} · بلا تغيير {$r['skipped']} · فشل {$r['failed']} · مؤشّراتٌ مقترحة {$r['ideas']}"
            . ($r['code'] ? " · توقّف: {$r['code']}" : ''));

        return self::SUCCESS;
    }
}
