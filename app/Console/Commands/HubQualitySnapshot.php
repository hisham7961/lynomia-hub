<?php

namespace App\Console\Commands;

use App\Support\DataQuality;
use Illuminate\Console\Command;

/**
 * لقطة جودة البيانات اليومية.
 *
 * درجةُ اليوم وحدها لا تقول شيئاً: لا يُعرف منها إن كنا نُنظّف أم نُراكم.
 * وبلا سلسلةٍ زمنية لا وجود لـ«إنجاز» أصلاً — الإنجاز فرقٌ بين نقطتين.
 */
class HubQualitySnapshot extends Command
{
    protected $signature = 'hub:quality-snapshot {--date= : تاريخ اللقطة (افتراضيه اليوم)}';
    protected $description = 'تسجيل درجة جودة البيانات وعدد نواقصها في السلسلة الزمنية';

    public function handle(): int
    {
        DataQuality::snapshot($this->option('date'));
        $t = DataQuality::scan()['totals'];

        $this->info("درجة الجودة {$t['score']}٪ · {$t['defects']} نقصاً في {$t['checks']} فحصاً · "
            . "{$t['clean']} وحدة نظيفة من {$t['modules']}");

        \App\Support\Health::beat('quality');
        return self::SUCCESS;
    }
}
