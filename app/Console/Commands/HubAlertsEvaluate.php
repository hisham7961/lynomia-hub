<?php

namespace App\Console\Commands;

use App\Support\AlertEngine;
use App\Support\Health;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * **تقييمُ التنبيهات النافذية كلَّ ٥ دقائق** (WP-6.3 · §9.1/§3.9).
 *
 * الدورةُ اليومية (`hub:automation`) تقيّم قواعدَ الحقول؛ وهذه السكّةُ الخمسية
 * تقيّم قواعدَ «X خلال Y دقيقة» (فشلُ دخول، منعٌ، تغييرُ أدوار، قفلُ طوارئ،
 * أخطاءٌ حرجة، مجدولاتٌ ميتة) وتكشف الحوادثَ التشغيلية ببصمات `ops:*`.
 *
 * `Health::check()` يُحسب **مرّةً واحدة** للتشغيلة ويُمرَّر للمحرّك (تقييمُ
 * المجدولات + كشفُ ops معاً) — وهو المصدرُ الذي تفرضه §3.9؛ الكلفةُ مقيسةٌ
 * ومقبولة لأن هذا الأمرَ هو جهةُ الكشف الوحيدة، بخلاف hub:ops-snapshot الذي
 * اقتُصر على `ready()` (critic #38) لأنه يكتب تليمترياً لا يكشف شيئاً.
 */
class HubAlertsEvaluate extends Command
{
    protected $signature = 'hub:alerts-evaluate {--dry : عرض ما سيحدث دون كتابة}';
    protected $description = 'تقييمُ قواعد التنبيه النافذية وكشفُ الحوادث التشغيلية كلَّ ٥ دقائق';

    public function handle(): int
    {
        $t0 = microtime(true);
        if (! Schema::hasTable('alert_instances')) {
            $this->warn('جدول alert_instances غائب — شغّل الترحيلات أولاً');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry');
        if ($dry) $this->warn('وضع المعاينة — لن يُكتب شيء');

        $r = (new AlertEngine($dry, fn ($m) => $this->line($m)))->evaluate();

        $this->info("تنبيهات: {$r['fired']} إطلاق · {$r['resolved']} تعافٍ · {$r['incidents']} حادثة آلية · {$r['notifs']} إشعار");

        if (! $dry) Health::beat('alerts', (int) round((microtime(true) - $t0) * 1000));

        return self::SUCCESS;
    }
}
