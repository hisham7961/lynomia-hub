<?php

use Illuminate\Support\Facades\Schedule;

/*
| الأتمتة اليومية: توليد المتكررات + تقييم قواعد التنبيه.
| يتطلب cron واحداً على السيرفر:
|   * * * * * cd /path/to/hub && php artisan schedule:run >> /dev/null 2>&1
*/
Schedule::command('hub:automation')->dailyAt('06:00');
Schedule::command('hub:outbox')->everyFiveMinutes();
Schedule::command('hub:backup')->dailyAt('03:30');
