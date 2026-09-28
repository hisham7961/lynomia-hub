<?php

namespace App\Console\Commands;

use App\Support\Ai\Brief\ExecBrief;
use Illuminate\Console\Command;

/**
 * **موجزُ الأسبوع للمالك** (docs/ai-hub/47 §العمود و) — مجدولٌ صباحَ الأحد حين `ai.exec_brief` مفعَّل.
 *
 *     php artisan hub:exec-brief --dry     # لمن سيُبنى — بلا نداء
 *     php artisan hub:exec-brief --force   # يُعاد بناءُ موجز هذا الأسبوع
 */
class HubExecBrief extends Command
{
    protected $signature = 'hub:exec-brief {--dry : لمن سيُبنى دون نداء} {--force : أعد بناء موجز هذا الأسبوع}';

    protected $description = 'موجزُ الأسبوع للمالك: خمسةُ أمورٍ تحتاج قرارَه';

    public function handle(): int
    {
        if (! ExecBrief::enabled() && ! $this->option('force')) {
            $this->warn('موجزُ الأسبوع مطفأ (ai.exec_brief) — لا شيء. (--force يعمل ولو كان مطفأً)');

            return self::SUCCESS;
        }
        $r = ExecBrief::run((bool) $this->option('dry'), (bool) $this->option('force'));
        $this->info("بُني {$r['built']} موجزاً · أُشعر {$r['notified']}" . ($r['code'] ? " · آخرُ إخفاق: {$r['code']}" : ''));

        return self::SUCCESS;
    }
}
