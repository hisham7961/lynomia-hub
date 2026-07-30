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

        Setting::updateOrCreate(['key' => $key], ['value' => is_numeric($val) ? +$val : $val]);
        Cache::forget('settings:all');

        $this->info("تم: $key = $val");

        return self::SUCCESS;
    }
}
