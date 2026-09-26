<?php

namespace App\Console\Commands;

use App\Support\Ai\Auditor\Auditor;
use Illuminate\Console\Command;

/**
 * **جولةُ المدقّق يدويّاً** — والجولةُ المجدولةُ خطوةٌ في `hub:automation` (٠٦:٠٠).
 *
 *     php artisan hub:auditor --dry   # ماذا سيُرصد، بلا كتابة
 */
class HubAuditor extends Command
{
    protected $signature = 'hub:auditor {--dry : عرضُ ما سيُرصد دون كتابة}';
    protected $description = 'المدقّقُ العامّ لعمل الفريق — جولةٌ واحدةٌ الآن';

    public function handle(): int
    {
        if (! Auditor::enabled()) {
            $this->warn('المدقّقُ مطفأ (auditor.enabled) — لا جولة.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry');
        if ($dry) $this->warn('وضعُ المعاينة — لن يُكتب شيء');

        $failed = 0;
        foreach (Auditor::run($dry) as $key => $s) {
            if (($s['skipped'] ?? null) !== null) {
                $this->line("… {$key}: لم يُسأل — {$s['skipped']}");

                continue;
            }
            if ($s['error'] !== null) {
                $failed++;
                $this->error("✗ {$key}: فشل ({$s['error']}) — نتائجُه القائمة لم تُمسّ");

                continue;
            }
            $this->info("✓ {$key}: رُصد {$s['found']} · جديدٌ {$s['opened']} · زال شرطُه {$s['resolved']}");
            // جولةٌ ناقصةٌ تُقال — لا تبدو «لا شيء» (سقفٌ أو ميزانيّةٌ أو سياسةٌ أو بوّابة)
            if (($s['incomplete'] ?? null) !== null) $this->warn("  ⚠ {$key}: الجولةُ ناقصة ({$s['incomplete']}) — لم يُحَلّ إلّا ما أُعيد فحصُه");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
