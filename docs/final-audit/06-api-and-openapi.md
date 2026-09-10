# 06 — الـAPI ومواصفةُ OpenAPI

## سطوحُ الـAPI

| السطح | المسارات | الحارس |
|---|---|---|
| `/api/v1` | 28 | `ApiAuth` + حاجزُ العميل (AUDIT-1) |
| `/api/mobile/v1` | 84 | `ApiAuth` + `MobilePortalGuard` |

## عزلُ صلاحيّةِ الـAPI

- **`/api/v1`:** كان تحت `ApiAuth` فقط؛ أُضيف حاجزُ عميلٍ صريحٌ في `V1Controller::resolveApi()`
  يعيد 404 لأيِّ وحدةٍ خارجَ `PortalGuard::MODULE_ALLOW` لحساباتِ العميل (AUDIT-1، P1، أُصلِح).
- **`/api/mobile/v1`:** `MobilePortalGuard` يعكس `PortalGuard` (عزلُ العميل + مسارٌ مسموح).
- لا تغييرَ في عقدِ الـAPI (الإضافةُ لا الكسر): الداخليُّ يصل كالمعتاد
  (`test_internal_api_token_still_reaches_modules`).

## مواصفةُ OpenAPI — تُولَّد لا تُحرَّر (§99)

- تُولَّد بـ`php artisan hub:openapi --out=docs/openapi.json` من `App\Support\OpenApi`.
- **العدد الحاليّ:** 182 مساراً، 172 مخطّطاً، الإصدار مربوطٌ بـ`config('hub.version')`.
- بعد كلِّ إصلاحاتِ الطور، أُعيد التوليد؛ شجرةُ العمل تعكس المُخرَجَ المقصودَ فقط
  (لا انحرافَ يدويّ). **عدمُ تطابقِ مسارٍ قديم = 0.**
- بوّابةُ CI تُسقط أيَّ انحرافٍ بين الملفِّ والمولِّد (خارجَ `phpunit`).

## AUDIT-4 — تسميةُ مسارِ المواصفة (P2، أُصلِح)

مسار `openapi.json` في `routes/api.php` لم يكن مُسمّىً، وسجلُّ القدرات يشير إلى اسمِ مسارٍ
معدوم. أُضيف `->name('api.openapi')`. اختبارُ الانحدار: `test_openapi_route_is_named`
(`Route::has('api.openapi')`).

## مسارات مكسورة / بلا واجهة

- مساراتٌ مكسورة = 0.
- لا كعبٌ (stub) يسند ميزةً `ENABLED` (تحقّقٌ عبر تدقيقِ المسارات مقابلَ سجلِّ القدرات).
- المساران المبنيّان-غيرُ-المربوطين (`security.event`, `dm.edit`) موثّقان P3 في
  `04-navigation-and-discoverability.md`.

## الخلاصة

| البند | العدد |
|---|---|
| تسريبُ صلاحيّةِ API | 0 |
| عدمُ تطابقِ مسارٍ قديمٍ في OpenAPI | 0 |
| كعبٌ يسند ENABLED | 0 |
