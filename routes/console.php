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
Schedule::command('hub:digest')->weeklyOn(6, '07:00');   // تقرير تنفيذي أسبوعي (السبت ٧ صباحاً)
Schedule::command('hub:metrics-snapshot')->dailyAt('23:45');   // لقطة الأرقام المتحرّكة قبل انقضاء اليوم
