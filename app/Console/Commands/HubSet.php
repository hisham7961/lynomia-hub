<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** ضبط إعداد خادم من سطر الأوامر أو من حزم التحديث:  php artisan hub:set auth.session_min 0 */
class HubSet extends Command
{
    protected $signature = 'hub:set {key} {value}';

    protected $description = 'ضبط قيمة إعداد في جدول settings مع تفريغ الكاش';

    public function handle(): int
    {
        $key = (string) $this->argument('key');
        $val = (string) $this->argument('value');

        // **السلسلةُ كما هي** — مطابقاً لمسار الشاشة (`SettingController::put`).
        // كان `is_numeric($val) ? +$val : $val` يُفسد كلَّ قيمةٍ نصّيةٍ تبدو رقماً:
        // `'0071234'` تصير `71234` فيُدمَّر رقمُ حساب أو معرّفُ قناة، وعددٌ فوق
        // مدى العدد الصحيح يفقد دقّتَه بالتحويل إلى float — وكلُّه في حزمة تحديثٍ
        // صامتة لا يراها أحد. والمستهلكون يقارنون بـ`(string)` أصلاً.
        Setting::updateOrCreate(['key' => $key], ['value' => $val]);
        Cache::forget('settings:all');

        $this->info("تم: $key = $val");

        return self::SUCCESS;
    }
}
