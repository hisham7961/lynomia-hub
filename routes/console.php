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
| ومزلاجٌ بلا مدّةٍ يبقى ٢٤ ساعة إن قُتل التشغيل (v2.399): مدّةٌ صريحة — ٢٠ دقيقةً
| لمهامّ الخمس دقائق و٤ ساعاتٍ لليوميّة — فلا يُحبَس الصادرُ يوماً كاملاً.
*/
Schedule::command('hub:automation')->dailyAt('06:00')->withoutOverlapping(240)
    ->onFailure(fn () => hub_schedule_failed('hub:automation', 'QUEUE', 'ERROR'));
Schedule::command('hub:outbox')->everyFiveMinutes()->withoutOverlapping(20)
    ->onFailure(fn () => hub_schedule_failed('hub:outbox', 'QUEUE', 'ERROR'));
/*
| ملخّصُ تقارير المشروع **كلَّ ساعة** حين يختاره المالك (`reports.project_digest_frequency=hourly`) — وإلّا
| فهو خطوةٌ في hub:automation اليوميّة. `when` يُقرأ عند كلِّ دقّةٍ لا عند الإقلاع: تغييرُ الإعداد يسري فوراً،
| ومطفأٌ أو يوميٌّ ⇒ لا يجري. والجولةُ نفسُها تزايديّة: لا جديد ⇒ لا نداء.
*/
Schedule::command('hub:report-digest')->hourly()->withoutOverlapping(50)
    ->when(fn () => \App\Support\Ai\Reports\ProjectReportDigest::hourly() && \App\Support\Ai\Reports\ProjectReportDigest::enabled())
    ->onFailure(fn () => hub_schedule_failed('hub:report-digest', 'QUEUE', 'ERROR'));
/*
| المتابِع (docs/ai-hub/47 §العمود ب) — مرّتين في ساعات العمل حين يُفعَّل: استخراجٌ وإغلاقٌ بالدليل وسؤال.
| سؤالان في اليوم على الأكثر لكلِّ موظّف — والجدولةُ مرّتان فلا يتجاوزهما.
*/
Schedule::command('hub:followup')->dailyAt('10:05')->withoutOverlapping(60)
    ->when(fn () => \App\Support\Ai\FollowUp\FollowUp::enabled())
    ->onFailure(fn () => hub_schedule_failed('hub:followup', 'QUEUE', 'ERROR'));
Schedule::command('hub:followup')->dailyAt('14:05')->withoutOverlapping(60)
    ->when(fn () => \App\Support\Ai\FollowUp\FollowUp::enabled())
    ->onFailure(fn () => hub_schedule_failed('hub:followup', 'QUEUE', 'ERROR'));
// موجزُ الأسبوع للمالك (docs/ai-hub/47 §العمود و) — صباحَ الأحد، أوّلَ أسبوع العمل
Schedule::command('hub:exec-brief')->weeklyOn(0, '07:40')->withoutOverlapping(60)
    ->when(fn () => \App\Support\Ai\Brief\ExecBrief::enabled())
    ->onFailure(fn () => hub_schedule_failed('hub:exec-brief', 'QUEUE', 'ERROR'));
Schedule::command('hub:backup')->dailyAt('03:30')->withoutOverlapping(240)
    ->onFailure(fn () => hub_schedule_failed('hub:backup', 'QUEUE', 'HIGH'));
Schedule::command('hub:digest')->weeklyOn(6, '07:00')->withoutOverlapping(240)   // تقرير تنفيذي أسبوعي (السبت ٧ صباحاً)
    ->onFailure(fn () => hub_schedule_failed('hub:digest', 'QUEUE', 'ERROR'));
Schedule::command('hub:metrics-snapshot')->dailyAt('23:45')->withoutOverlapping(240)   // لقطة الأرقام المتحرّكة قبل انقضاء اليوم
    ->onFailure(fn () => hub_schedule_failed('hub:metrics-snapshot', 'QUEUE', 'ERROR'));
Schedule::command('hub:uptime-check')->everyFiveMinutes()->withoutOverlapping(20)      // فحص حيّ للسيرفرات والمواقع المراقَبة
    ->onFailure(fn () => hub_schedule_failed('hub:uptime-check', 'QUEUE', 'ERROR'));
Schedule::command('hub:quality-snapshot')->dailyAt('23:50')->withoutOverlapping(240)   // درجة جودة البيانات — بها يُقاس ما أُصلح
    ->onFailure(fn () => hub_schedule_failed('hub:quality-snapshot', 'QUEUE', 'ERROR'));
/*
| **وفاحصُ سلسلة التدقيق** (v2.336): كان الفاحصُ الوحيدُ لضمانِ عدم العبث بلا
| جدولةٍ ولا زرّ — يُرشَد إليه بسطر طرفيةٍ لا يملكها صاحبُ استضافةٍ مشتركة.
| فالسلسلةُ مختومةٌ ولا يفحصها شيء، والعبثُ يبقى غيرَ مكتشَفٍ إلى أن يخطر
| لأحدٍ أن يسأل — وهو ما لا يقع. أسبوعيّاً قبل الفجر، وزرٌّ في مركز التشغيل.
*/
$auditT0 = null;   // (WP-2.3) يلتقطه before ويقرؤه onSuccess — الإغلاقان يتشاركان المرجع
Schedule::command('hub:audit-verify')->weeklyOn(0, '04:30')->withoutOverlapping(240)
    ->before(function () use (&$auditT0) { $auditT0 = microtime(true); })
    // نبضةُ الفاحص من المجدول نفسِه: نجاحٌ أو فشلٌ يُقرأ في نموذج الصحّة (v2.399)،
    // وبمدّتها الحقيقية (WP-2.3) — فاتّجاهُ مدّة الفحص يُرسم في جدول المجدولات
    ->onSuccess(function () use (&$auditT0) {
        \App\Support\Ops\Health::beat('audit', $auditT0 !== null ? (int) round((microtime(true) - $auditT0) * 1000) : null);
    })
    ->onFailure(fn () => hub_schedule_failed('hub:audit-verify', 'SECURITY', 'HIGH'));   // نبضة fail + خطأ + حادثة أمنية

// ── Control Plane: Phase 2 ──
/*
| لقطةُ التشغيل (WP-2.3): كلَّ ٥ دقائق تُكتب رتبةُ الجاهزية وموارد الخادم في
| metric_points — رخيصةٌ عمداً (critic #38)، والعدّاداتُ الثقيلةُ يوميّاً خلف حارس.
| مفتاحُ نبضتها 'ops' وقائمةُ اشتقاق hub_schedule_failed (طور ١، لا تُحرَّر هنا)
| لا تعرفه — فتُكتب نتيجةُ الفشل تحت المفتاح الصحيح صراحةً كي يراها نموذجُ الصحّة.
*/
Schedule::command('hub:ops-snapshot')->everyFiveMinutes()->withoutOverlapping(20)
    ->onFailure(function () {
        hub_schedule_failed('hub:ops-snapshot', 'QUEUE', 'ERROR');
        try {
            \App\Models\Setting::updateOrCreate(['key' => 'heartbeat.ops.meta'],
                ['value' => ['ms' => null, 'result' => 'fail', 'note' => 'فشل التشغيل المجدول', 'at' => now()->toIso8601String()]]);
            \Illuminate\Support\Facades\Cache::forget('settings:all');
        } catch (\Throwable $e) {
        }
    });

// ── Control Plane: Phase 4 ──
/*
| لقطةُ الوضعية الأمنية اليومية (WP-4.2): درجةُ SecurityPosture واتجاهُها في
| metric_points ثم تسويةُ النتائج (security_findings). قبل منتصف الليل بثلثِ
| ساعةٍ كي تلحق باليوم الذي تقيسه. مفتاحُ نبضتها 'security' وقائمةُ اشتقاق
| hub_schedule_failed (طور ١، لا تُحرَّر هنا) لا تعرفه — فتُكتب نتيجةُ الفشل
| تحت المفتاح الصحيح صراحةً كي يراها نموذجُ الصحّة (نمطُ hub:ops-snapshot نفسُه).
*/
Schedule::command('hub:security-snapshot')->dailyAt('23:40')->withoutOverlapping(240)
    ->onFailure(function () {
        hub_schedule_failed('hub:security-snapshot', 'SECURITY', 'ERROR');
        try {
            \App\Models\Setting::updateOrCreate(['key' => 'heartbeat.security.meta'],
                ['value' => ['ms' => null, 'result' => 'fail', 'note' => 'فشل التشغيل المجدول', 'at' => now()->toIso8601String()]]);
            \Illuminate\Support\Facades\Cache::forget('settings:all');
        } catch (\Throwable $e) {
        }
    });

// ── Control Plane: Phase 6 ──
/*
| تقييمُ التنبيهات النافذية (WP-6.3 · §9.1/§3.9): كلَّ ٥ دقائق تُقيَّم قواعدُ
| «X خلال Y دقيقة» وتُكشف الحوادثُ التشغيلية (ops:*) ببصماتها. مفتاحُ نبضته
| 'alerts' وقائمةُ اشتقاق hub_schedule_failed (طور ١، لا تُحرَّر هنا) لا تعرفه —
| فتُكتب نتيجةُ الفشل تحت المفتاح الصحيح صراحةً كي يراها نموذجُ الصحّة
| (نمطُ hub:ops-snapshot نفسُه).
*/
Schedule::command('hub:alerts-evaluate')->everyFiveMinutes()->withoutOverlapping(20)
    ->onFailure(function () {
        hub_schedule_failed('hub:alerts-evaluate', 'QUEUE', 'ERROR');
        try {
            \App\Models\Setting::updateOrCreate(['key' => 'heartbeat.alerts.meta'],
                ['value' => ['ms' => null, 'result' => 'fail', 'note' => 'فشل التشغيل المجدول', 'at' => now()->toIso8601String()]]);
            \Illuminate\Support\Facades\Cache::forget('settings:all');
        } catch (\Throwable $e) {
        }
    });
