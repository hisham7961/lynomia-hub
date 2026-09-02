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
Schedule::command('hub:automation')->dailyAt('06:00')->withoutOverlapping()
    ->onFailure(fn () => hub_schedule_failed('hub:automation', 'QUEUE', 'ERROR'));
Schedule::command('hub:outbox')->everyFiveMinutes()->withoutOverlapping()
    ->onFailure(fn () => hub_schedule_failed('hub:outbox', 'QUEUE', 'ERROR'));
Schedule::command('hub:backup')->dailyAt('03:30')->withoutOverlapping()
    ->onFailure(fn () => hub_schedule_failed('hub:backup', 'QUEUE', 'HIGH'));
Schedule::command('hub:digest')->weeklyOn(6, '07:00')->withoutOverlapping()   // تقرير تنفيذي أسبوعي (السبت ٧ صباحاً)
    ->onFailure(fn () => hub_schedule_failed('hub:digest', 'QUEUE', 'ERROR'));
Schedule::command('hub:metrics-snapshot')->dailyAt('23:45')->withoutOverlapping()   // لقطة الأرقام المتحرّكة قبل انقضاء اليوم
    ->onFailure(fn () => hub_schedule_failed('hub:metrics-snapshot', 'QUEUE', 'ERROR'));
Schedule::command('hub:uptime-check')->everyFiveMinutes()->withoutOverlapping()      // فحص حيّ للسيرفرات والمواقع المراقَبة
    ->onFailure(fn () => hub_schedule_failed('hub:uptime-check', 'QUEUE', 'ERROR'));
Schedule::command('hub:quality-snapshot')->dailyAt('23:50')->withoutOverlapping()   // درجة جودة البيانات — بها يُقاس ما أُصلح
    ->onFailure(fn () => hub_schedule_failed('hub:quality-snapshot', 'QUEUE', 'ERROR'));
/*
| **وفاحصُ سلسلة التدقيق** (v2.336): كان الفاحصُ الوحيدُ لضمانِ عدم العبث بلا
| جدولةٍ ولا زرّ — يُرشَد إليه بسطر طرفيةٍ لا يملكها صاحبُ استضافةٍ مشتركة.
| فالسلسلةُ مختومةٌ ولا يفحصها شيء، والعبثُ يبقى غيرَ مكتشَفٍ إلى أن يخطر
| لأحدٍ أن يسأل — وهو ما لا يقع. أسبوعيّاً قبل الفجر، وزرٌّ في مركز التشغيل.
*/
Schedule::command('hub:audit-verify')->weeklyOn(0, '04:30')->withoutOverlapping()
    // نبضةُ الفاحص من المجدول نفسِه: نجاحٌ أو فشلٌ يُقرأ في نموذج الصحّة (v2.399)
    ->onSuccess(fn () => \App\Support\Health::beat('audit'))
    ->onFailure(fn () => hub_schedule_failed('hub:audit-verify', 'SECURITY', 'HIGH'));   // نبضة fail + خطأ + حادثة أمنية
