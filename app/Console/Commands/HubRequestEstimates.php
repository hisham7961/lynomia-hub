<?php

namespace App\Console\Commands;

use App\Support\Ai\Brief\RequestEstimator;
use Illuminate\Console\Command;

/**
 * **تقديرُ الطلبات الواردة** يدويّاً (docs/ai-hub/47 §العمود و) — والمجدولُ خطوةٌ في `hub:automation`.
 *
 *     php artisan hub:request-estimates --dry
 */
class HubRequestEstimates extends Command
{
    protected $signature = 'hub:request-estimates {--dry : كم طلباً سيُقدَّر دون نداء}';

    protected $description = 'تقديرُ الطلبات الواردة من تاريخ نوعها ⇒ اقتراحاتٌ في صندوق الذكاء';

    public function handle(): int
    {
        if (! RequestEstimator::enabled()) {
            $this->warn('التقديرُ مطفأ (ai.request_estimates أو ai.proposals) — لا شيء.');

            return self::SUCCESS;
        }
        $r = RequestEstimator::run((bool) $this->option('dry'));
        $this->info("طلبات: {$r['requests']} · اقتراحات: {$r['proposals']}" . ($r['code'] ? " · توقّف: {$r['code']}" : ''));

        return self::SUCCESS;
    }
}
