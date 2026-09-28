<?php

namespace App\Console\Commands;

use App\Support\Ai\Understanding\ProjectUnderstanding;
use Illuminate\Console\Command;

/**
 * **ملفُّ فهم المشروع** يدويّاً (docs/ai-hub/47 §العمود ج) — والمجدولُ خطوةٌ في `hub:automation` حين `ai.understanding` مفعَّل.
 *
 *     php artisan hub:understanding --dry                 # أيُّ مشروعٍ سيُبنى فهمُه — بلا نداء
 *     php artisan hub:understanding --project=<uuid>      # مشروعٌ واحدٌ الآن ولو لم تتغيّر مصادرُه
 */
class HubUnderstanding extends Command
{
    protected $signature = 'hub:understanding {--project= : معرّفُ مشروعٍ واحد} {--dry : ما سيُبنى دون نداء}';

    protected $description = 'ملفُّ فهم المشروع واقتراحاتُه — لما تغيّرت مصادرُه';

    public function handle(): int
    {
        if (! ProjectUnderstanding::enabled() && ! $this->option('project')) {
            $this->warn('ملفُّ الفهم مطفأ (ai.understanding) — لا شيء. (--project يعمل ولو كان مطفأً)');

            return self::SUCCESS;
        }
        $dry = (bool) $this->option('dry');
        $pid = trim((string) $this->option('project'));
        $r = ProjectUnderstanding::run($dry, $pid !== '' ? $pid : null, null, $pid !== '');
        $this->info(($dry ? 'سيُبنى ' : 'بُني ') . "{$r['built']} · بلا تغيير {$r['skipped']} · فشل {$r['failed']}"
            . ($r['code'] ? " · توقّف: {$r['code']}" : ''));

        return self::SUCCESS;
    }
}
