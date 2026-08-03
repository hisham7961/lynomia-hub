<?php

use Illuminate\Support\Facades\Schedule;

/*
| الأتمتة اليومية: توليد المتكررات + تقييم قواعد التنبيه.
| يتطلب cron واحداً على السيرفر:
|   * * * * * cd /path/to/hub && php artisan schedule:run >> /dev/null 2>&1
*/
/*
| withoutOverlapping على كل أمرٍ كاتب: تشغيلٌ يدويّ أثناء الكرون، أو تشغيلةٌ
| بطيئة تتجاوز التالية، كانا يجعلان خطوتَي التوليد تقرآن الصفوف نفسها وتكتبان —
| فواتير وإشعاراتٌ وصادرٌ مكرّر. المزلاج يمنع التداخل على نفس العقدة (وonOneServer
| يُضاف عند تعدّد العُقد). القراءة المحضة (uptime) لا تحتاجه لكنه لا يضرّها.
*/
Schedule::command('hub:automation')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('hub:outbox')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('hub:backup')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('hub:digest')->weeklyOn(6, '07:00')->withoutOverlapping();   // تقرير تنفيذي أسبوعي (السبت ٧ صباحاً)
Schedule::command('hub:metrics-snapshot')->dailyAt('23:45')->withoutOverlapping();   // لقطة الأرقام المتحرّكة قبل انقضاء اليوم
Schedule::command('hub:uptime-check')->everyFiveMinutes()->withoutOverlapping();      // فحص حيّ للسيرفرات والمواقع المراقَبة
Schedule::command('hub:quality-snapshot')->dailyAt('23:50')->withoutOverlapping();   // درجة جودة البيانات — بها يُقاس ما أُصلح
