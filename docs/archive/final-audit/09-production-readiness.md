# 09 — جاهزيّةُ الإنتاج

## تمهيدُ الإنتاج (config/route/view cache)

فُحِص تحت `APP_ENV=production`:

```
APP_ENV=production php artisan config:cache  →  INFO Configuration cached successfully. (exit 0)
APP_ENV=production php artisan route:cache    →  INFO Routes cached successfully.        (exit 0)
APP_ENV=production php artisan view:cache      →  (exit 0)
```

مسارات الإغلاق (closure routes) تُخبَّأ بلا خطأ. **متطلّباتٌ حرِجةٌ مجهولةٌ لتهيئةِ الإنتاج = 0.**

## المجدول (Scheduler · §79-84)

- **11 مهمّة** مُعرَّفةٌ في `routes/console.php` (`Schedule::call`/`->command`)، بـ12 نداءَ تواتر.
- **13** نداءَ `onFailure`/`onSuccess` (نبضاتُ قلبٍ heartbeats) — الأعطالُ لا تُبتلَع.
- التشغيلُ عبر cron واحدٍ (`schedule:run` كلَّ دقيقة).

## الطابور (Queue)

- **وظائفُ `ShouldQueue` = 0** — لا وظيفةَ غيرَ متزامنة. كلُّ `dispatch()` متزامنٌ (`sync`).
- لا حاجةَ لعاملِ طابورٍ (worker) للتشغيلِ الصحيح؛ لا مخاطرَ رسائلَ عالقةٍ في طابورٍ غيرِ مُدارٍ.

## بيئةُ التشغيل

- PHP `^8.2` (مُختبَرٌ على 8.4.19).
- قاعدةُ البيانات: SQLite (تطوير/اختبار) + MySQL/MariaDB (إنتاج/اختبارٌ صارم) — الحزمةُ خضراءُ
  على الاثنين.

## القاعدةُ الإلزاميّة — رقمُ النسخة

خطّاف `.githooks/pre-push` يرفض أيَّ دفعةٍ ذاتِ commits جديدةٍ دون رفعِ `VERSION`؛ يُفعَّل ذاتيّاً
عبر `composer install`. النسخةُ تُقرأ حيّاً من `config('hub.version')` وتُعرَض في ذيلِ الشريط.

## الخلاصة

| البند | الحالة |
|---|---|
| config/route/view cache (production) | أخضر |
| المجدول (11 مهمّة + heartbeats) | سليم |
| الطابور (sync، 0 ShouldQueue) | سليم |
| متطلّباتٌ حرِجةٌ مجهولة | 0 |
