<?php

namespace App\Console\Commands;

use App\Support\Ai\Center\AiQuickSetup;
use Illuminate\Console\Command;

/**
 * **الإعدادُ السريع للذكاء** — يملأ الأغراضَ الفارغةَ من نماذج البوّابة، وميزانيّةً شهريّةً إن غابت.
 *
 *     php artisan hub:ai-setup                      # معاينةٌ بلا كتابة
 *     php artisan hub:ai-setup --apply --usd=50 --brain
 */
class HubAiSetup extends Command
{
    protected $signature = 'hub:ai-setup {--apply : تطبيقُ الخطّة (بدونه معاينةٌ فقط)} {--usd=50 : السقفُ الشهريُّ العامّ بالدولار إن لم تكن ميزانيّة (0 = لا ميزانيّة)} {--brain : تفعيلُ البحث بالمعنى إن وُجد نموذجُ تضمين}';
    protected $description = 'الإعدادُ السريع لأغراض النماذج والميزانيّة — الفارغُ وحدَه';

    public function handle(): int
    {
        $usd = max(0, (int) $this->option('usd'));
        $plan = AiQuickSetup::plan($usd);
        foreach ($plan['profiles'] as $p) {
            $this->line(match ($p['state']) {
                'configured' => "✔ {$p['label']} ({$p['key']}): مضبوطٌ — لا يُمسّ",
                'fill' => "＋ {$p['label']} ({$p['key']}): " . implode(' ← ', $p['models']),
                default => "✖ {$p['label']} ({$p['key']}): {$p['why']}",
            });
        }
        $this->line($plan['budget'] ? "＋ ميزانيّةٌ شهريّةٌ عامّة: {$plan['budget']['usd']} USD" : '✔ الميزانيّات: موجودةٌ أو لم تُطلب');

        if (! $this->option('apply')) {
            $this->warn('معاينةٌ فقط — أضِف --apply للتطبيق');

            return self::SUCCESS;
        }
        $r = AiQuickSetup::apply($usd, (bool) $this->option('brain'));
        $this->info("طُبّق: {$r['attached']} ضمّاً" . ($r['budget'] ? ' · ميزانيّةٌ أُنشئت' : '') . ($r['brain'] ? ' · البحثُ بالمعنى مفعَّل — شغّل hub:brain للفهرسة' : ''));

        return self::SUCCESS;
    }
}
