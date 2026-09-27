<?php

namespace App\Console\Commands;

use App\Support\Ai\Reports\ProjectReportDigest;
use Illuminate\Console\Command;

/**
 * **ملخّصُ تقارير المشاريع يدويّاً** — والمجدولُ خطوةٌ في `hub:automation`.
 *
 *     php artisan hub:report-digest --dry            # أيُّ المشاريع فيها جديدٌ وكم نداءً، بلا نداءٍ ولا كتابة
 *     php artisan hub:report-digest --project=<id>   # مشروعٌ واحد (بلا سقف المشاريع)
 */
class HubReportDigest extends Command
{
    protected $signature = 'hub:report-digest {--project= : معرّفُ مشروعٍ واحد} {--dry : عدُّ ما سيُلخَّص دون نداءٍ ولا كتابة}';

    protected $description = 'ملخّصُ تقارير المشروع بالذكاء — جولةٌ تزايديّة (الجديدُ منذ آخر ملخّص)';

    public function handle(): int
    {
        if (($why = ProjectReportDigest::whyNot()) !== null) {
            $this->warn('لا ملخّص: ' . $why);

            return self::SUCCESS;
        }
        $dry = (bool) $this->option('dry');
        if ($dry) $this->warn('وضعُ المعاينة — لا نداءَ ولا كتابة');
        $pid = trim((string) $this->option('project'));

        $s = ProjectReportDigest::run($dry, $pid === '' ? null : $pid);
        $this->info(($dry ? 'سيُحدَّث' : 'حُدِّث') . " {$s['updated']} مشروعاً · أخفق {$s['failed']} · بلا جديدٍ صالح {$s['nothing']}"
            . " · مرشّحون {$s['candidates']} · نداءات {$s['calls']}" . ($s['pending'] ? " · {$s['pending']} بقي له ما يُطوى" : ''));
        foreach ($s['codes'] as $project => $code) $this->line("  ✗ {$project}: {$code}");
        if ($s['stopped'] !== null) $this->warn('⚠ الجولةُ وقفت: ' . $s['stopped']);

        return self::SUCCESS;
    }
}
