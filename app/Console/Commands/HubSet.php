<?php

namespace App\Console\Commands;

use App\Support\Settings;
use Illuminate\Console\Command;

/** ضبط إعداد خادم من سطر الأوامر أو من حزم التحديث:  php artisan hub:set auth.session_min 0 */
class HubSet extends Command
{
    protected $signature = 'hub:set {key} {value}';

    protected $description = 'ضبط قيمة إعداد في جدول settings مع تفريغ الكاش';

    public function handle(): int
    {
        $key = (string) $this->argument('key');
        $val = (string) $this->argument('value');

        // **السلسلةُ كما هي** — مطابقاً لمسار الشاشة.
        // كان `is_numeric($val) ? +$val : $val` يُفسد كلَّ قيمةٍ نصّيةٍ تبدو رقماً:
        // `'0071234'` تصير `71234` فيُدمَّر رقمُ حساب أو معرّفُ قناة، وعددٌ فوق
        // مدى العدد الصحيح يفقد دقّتَه بالتحويل إلى float — وكلُّه في حزمة تحديثٍ
        // صامتة لا يراها أحد. والمستهلكون يقارنون بـ`(string)` أصلاً.
        //
        // (WP-9.2) والكتابةُ عبر `Settings::put` وحدَه: التشفيرُ للحسّاس (وسمُ
        // `sensitive` في الكتالوج — كان ثابتاً منسوخاً نسي `n8n.key` فيُكتب
        // نصّاً صريحاً بينما شاشتُه تشفّره)، وإبطالُ الخبيئة، و**أثرُ تدقيقٍ
        // لأول مرة**: كان مفتاحٌ أمنيٌّ يُطفأ من الطرفية بلا شاهدٍ إطلاقاً.
        $secret = Settings::isSecret($key);

        try {
            Settings::put($key, $val, 'cli', 'php artisan hub:set');
        } catch (\InvalidArgumentException $e) {
            $this->error('رُفضت القيمة: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info($secret ? "تم: $key = •••• (مشفَّر)" : "تم: $key = $val");

        return self::SUCCESS;
    }
}
