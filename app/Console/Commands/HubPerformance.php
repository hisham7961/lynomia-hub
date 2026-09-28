<?php

namespace App\Console\Commands;

use App\Support\Ai\Reports\EmployeePerformance;
use Illuminate\Console\Command;

/**
 * **تقاريرُ أداء الموظّفين يدويّاً** — والمجدولُ خطوةٌ في `hub:automation`.
 *
 *     php artisan hub:performance --dry                       # من سيُولَّد له وكم نداءً، بلا نداءٍ ولا كتابة
 *     php artisan hub:performance --employee=<id>             # موظّفٌ واحد (بلا سقف الجولة ولا حدِّ اليوم)
 *     php artisan hub:performance --employee=<id> --period=2026-09
 */
class HubPerformance extends Command
{
    protected $signature = 'hub:performance {--employee= : معرّفُ موظّفٍ واحد} {--period= : الفترة (2026-09 أو 2026-W39)} {--dry : عدُّ ما سيُولَّد دون نداءٍ ولا كتابة}';

    protected $description = 'تقريرُ أداء الموظّف بالذكاء — حقائقُ حتميّة ثمّ سردٌ محكوم، لكلِّ فترة';

    public function handle(): int
    {
        if (($why = EmployeePerformance::whyNot()) !== null) {
            $this->warn('لا تقرير: ' . $why);

            return self::SUCCESS;
        }
        $dry = (bool) $this->option('dry');
        if ($dry) $this->warn('وضعُ المعاينة — لا نداءَ ولا كتابة');
        $emp = trim((string) $this->option('employee'));
        $period = trim((string) $this->option('period'));

        $s = EmployeePerformance::run($dry, $emp === '' ? null : $emp, $period === '' ? null : $period, null, $emp !== '');
        $this->info(($dry ? 'سيُولَّد' : 'وُلِّد') . " {$s['updated']} تقريراً · أخفق {$s['failed']} · بلا جديد {$s['nothing']}"
            . " · متخطّى {$s['skipped']} · مرشّحون {$s['candidates']} · نداءات {$s['calls']}");
        foreach ($s['codes'] as $key => $code) $this->line("  ✗ {$key}: {$code}");
        if ($s['stopped'] !== null) $this->warn('⚠ الجولةُ وقفت: ' . $s['stopped']);

        return self::SUCCESS;
    }
}
