<?php

namespace App\Console\Commands;

use App\Support\Ai\FollowUp\CommitmentExtractor;
use App\Support\Ai\FollowUp\CommitmentResolver;
use App\Support\Ai\FollowUp\FollowUp;
use App\Support\Ai\FollowUp\FollowUpSender;
use Illuminate\Console\Command;

/**
 * **المتابِع** (docs/ai-hub/47 §العمود ب) — مجدولٌ مرّتين يومياً حين `followup.enabled` مفعَّل.
 *
 *     php artisan hub:followup --dry      # كم تقريراً سيُقرأ، وكم التزاماً سيُسأل عنه — بلا نداءٍ ولا كتابة
 *     php artisan hub:followup            # استخراجٌ ثم إغلاقٌ بالدليل ثم سؤالٌ وتصعيد
 */
class HubFollowUp extends Command
{
    protected $signature = 'hub:followup {--dry : ما سيحدث دون نداءٍ ولا كتابة}';

    protected $description = 'المتابِع: التزاماتُ التقارير — استخراجٌ وإغلاقٌ بالدليل وسؤالٌ «ماذا حدث؟»';

    public function handle(): int
    {
        if (! FollowUp::enabled()) {
            $this->warn('المتابِع مطفأ (followup.enabled) — لا شيء.');

            return self::SUCCESS;
        }
        $dry = (bool) $this->option('dry');
        if ($dry) $this->warn('وضعُ المعاينة — لا نداءَ ولا كتابة');

        $gc = null;
        $x = CommitmentExtractor::run($gc, $dry);
        $this->line("الاستخراج: {$x['reports']} تقريراً · {$x['found']} التزاماً جديداً · {$x['calls']} نداءً"
            . ($x['code'] ? " · توقّف: {$x['code']}" : ''));

        $r = CommitmentResolver::run($gc, $dry);
        $this->line("الإغلاقُ بالدليل: {$r['closed']} أُغلق · {$r['deferred']} أُمهل · {$r['calls']} نداءً"
            . ($r['code'] ? " · توقّف: {$r['code']}" : ''));

        $s = FollowUpSender::run($dry);
        $this->info("الأسئلة: {$s['asked']} التزاماً لـ{$s['users']} موظّفاً · مُصعَّد: {$s['escalated']}");

        return self::SUCCESS;
    }
}
