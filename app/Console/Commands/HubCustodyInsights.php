<?php

namespace App\Console\Commands;

use App\Support\Assets\CustodyInsights;
use Illuminate\Console\Command;

/**
 * **تحليلُ الذكاء لأصناف العهد يدويّاً** — والمجدولُ خطوةٌ في `hub:automation` حين `custody.ai_insights` مفعَّل.
 *
 *     php artisan hub:custody-insights --dry        # أيُّ صنفٍ تغيّرت حقائقُه فسيُحلَّل — بلا نداءٍ ولا كتابة
 *     php artisan hub:custody-insights --type=SV    # صنفُ السيرفرات وحدَه
 *
 * لا يُعاد تحليلُ صنفٍ لم تتغيّر بصمةُ حقائقه منذ آخر نجاح.
 */
class HubCustodyInsights extends Command
{
    protected $signature = 'hub:custody-insights {--type= : كودُ صنفٍ واحد (SV · LT …)} {--dry : ما سيُحلَّل دون نداءٍ ولا كتابة}';

    protected $description = 'تحليلُ الذكاء لأصناف العهد — لما تغيّرت حقائقُه فقط';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $type = trim((string) $this->option('type'));
        if ($dry) $this->warn('وضعُ المعاينة — لا نداءَ ولا كتابة');

        $s = CustodyInsights::run($type !== '' ? $type : null, $dry);
        if ($s['stopped'] !== null) {
            $this->warn('لا تحليل: ' . $s['stopped']);

            return self::SUCCESS;
        }

        foreach ($s['codes'] as $code => $action) $this->line("  {$code}: {$action}");
        $this->info(($dry ? 'سيُحلَّل' : 'حُلِّل') . " {$s['generated']} صنفاً · بلا تغيير {$s['skipped']} · فشل {$s['failed']}");

        return self::SUCCESS;
    }
}
